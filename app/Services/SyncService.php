<?php

namespace App\Services;

use App\Adapters\LimeSurveyAdapter;
use App\Models\FormVersionCache;
use App\Models\MobileDevice;
use App\Models\SyncBatch;
use App\Models\SyncInterview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly LimeSurveyAdapter $limeSurveyAdapter
    ) {
    }

    public function processBatch(Request $request, array $payload): array
    {
        $route = 'api/v1/sync/responses/batch';
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        if ($idempotencyKey === '') {
            return [
                'status_code' => 400,
                'body' => [
                    'error' => [
                        'code' => 'MISSING_IDEMPOTENCY_KEY',
                        'message' => 'Idempotency-Key header is required.',
                    ],
                ],
            ];
        }

        $requestHash = $this->idempotencyService->hashPayload($payload);
        $existing = $this->idempotencyService->find($route, $idempotencyKey);

        if ($existing) {
            if ($existing->request_hash !== $requestHash) {
                return [
                    'status_code' => 409,
                    'body' => [
                        'error' => [
                            'code' => 'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD',
                            'message' => 'Idempotency key already used with a different payload.',
                        ],
                    ],
                ];
            }

            $saved = json_decode((string) $existing->response_json, true);

            return [
                'status_code' => (int) ($existing->status_code ?? 200),
                'body' => is_array($saved) ? $saved : [
                    'error' => [
                        'code' => 'INVALID_IDEMPOTENCY_SNAPSHOT',
                        'message' => 'Stored idempotency snapshot is invalid.',
                    ],
                ],
            ];
        }

        $device = $this->resolveDevice($request);

        $result = DB::transaction(function () use ($payload, $idempotencyKey, $device) {
            $batch = SyncBatch::query()->create([
                'batch_uuid' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'device_id' => $device->id,
                'status' => 'processing',
                'accepted_count' => 0,
                'rejected_count' => 0,
            ]);

            $accepted = 0;
            $rejected = 0;
            $results = [];

            foreach (($payload['interviews'] ?? []) as $interview) {
                $interviewUuid = (string) ($interview['interview_uuid'] ?? '');

                if ($interviewUuid === '') {
                    $rejected++;
                    $results[] = [
                        'interview_uuid' => '',
                        'status' => 'rejected',
                        'error' => [
                            'code' => 'INVALID_PAYLOAD',
                            'message' => 'Interview UUID is required.',
                        ],
                    ];
                    continue;
                }

                $already = SyncInterview::query()->where('interview_uuid', $interviewUuid)->first();
                if ($already && $already->status === 'synced' && !empty($already->server_ref)) {
                    $results[] = [
                        'interview_uuid' => $interviewUuid,
                        'status' => 'duplicate',
                        'server_ref' => $already->server_ref,
                    ];
                    continue;
                }

                $sid = (int) ($interview['form_sid'] ?? 0);
                $version = (string) ($interview['form_version'] ?? '');

                if ($sid <= 0 || $version === '') {
                    $this->storeRejectedInterview($batch->id, $interviewUuid, $sid, $version, 'INVALID_INTERVIEW', 'Missing form_sid or form_version.');
                    $rejected++;
                    $results[] = [
                        'interview_uuid' => $interviewUuid,
                        'status' => 'rejected',
                        'error' => [
                            'code' => 'INVALID_INTERVIEW',
                            'message' => 'Missing form_sid or form_version.',
                        ],
                    ];
                    continue;
                }

                $hasVersion = FormVersionCache::query()
                    ->where('sid', $sid)
                    ->where('version', $version)
                    ->exists();

                if (!$hasVersion) {
                    $this->storeRejectedInterview($batch->id, $interviewUuid, $sid, $version, 'FORM_VERSION_NOT_FOUND', 'Form version does not exist in cache.');
                    $rejected++;
                    $results[] = [
                        'interview_uuid' => $interviewUuid,
                        'status' => 'rejected',
                        'error' => [
                            'code' => 'FORM_VERSION_NOT_FOUND',
                            'message' => 'Form version does not exist in cache.',
                        ],
                    ];
                    continue;
                }

                $payload = $this->limeSurveyAdapter->getFormByVersion($sid, $version);
                if (is_array($payload)) {
                    $this->limeSurveyAdapter->syncQuestionMapFromPayload($sid, $version, $payload);
                }

                $resolvedAnswers = [];
                $mappingError = null;

                foreach (($interview['answers'] ?? []) as $idx => $answer) {
                    $mapped = $this->mapAnswerToInternalRefs($sid, $version, $answer, $idx);
                    if (isset($mapped['error'])) {
                        $mappingError = $mapped['error'];
                        break;
                    }

                    foreach ($mapped['pairs'] as $internalRef => $value) {
                        $resolvedAnswers[$internalRef] = $value;
                    }
                }

                if ($mappingError) {
                    $this->storeRejectedInterview(
                        $batch->id,
                        $interviewUuid,
                        $sid,
                        $version,
                        $mappingError['code'],
                        $mappingError['message'] . ' [' . $mappingError['item'] . ']'
                    );

                    $rejected++;
                    $results[] = [
                        'interview_uuid' => $interviewUuid,
                        'status' => 'rejected',
                        'error' => $mappingError,
                    ];
                    continue;
                }

                $persistResult = $this->limeSurveyAdapter->persistInterview($interview, $resolvedAnswers);
                if (!$persistResult['ok']) {
                    $this->storeRejectedInterview(
                        $batch->id,
                        $interviewUuid,
                        $sid,
                        $version,
                        'PERSIST_FAILED',
                        (string) ($persistResult['error'] ?? 'Unknown persist failure')
                    );

                    $rejected++;
                    $results[] = [
                        'interview_uuid' => $interviewUuid,
                        'status' => 'rejected',
                        'error' => [
                            'code' => 'PERSIST_FAILED',
                            'message' => (string) ($persistResult['error'] ?? 'Unknown persist failure'),
                        ],
                    ];
                    continue;
                }

                SyncInterview::query()->updateOrCreate(
                    ['interview_uuid' => $interviewUuid],
                    [
                        'sync_batch_id' => $batch->id,
                        'form_sid' => $sid,
                        'form_version' => $version,
                        'status' => 'synced',
                        'server_ref' => $persistResult['server_ref'] ?? null,
                        'error_code' => null,
                        'error_message' => null,
                    ]
                );

                $accepted++;
                $results[] = [
                    'interview_uuid' => $interviewUuid,
                    'status' => 'synced',
                    'server_ref' => $persistResult['server_ref'] ?? null,
                ];
            }

            $batch->accepted_count = $accepted;
            $batch->rejected_count = $rejected;
            $batch->status = $rejected === 0 ? 'success' : ($accepted > 0 ? 'partial' : 'failed');
            $batch->processed_at = now();
            $batch->save();

            return [
                'status_code' => 200,
                'body' => [
                    'request_id' => (string) Str::uuid(),
                    'data' => [
                        'batch_id' => $batch->batch_uuid,
                        'batch_status' => $batch->status,
                        'accepted' => $accepted,
                        'rejected' => $rejected,
                        'results' => $results,
                    ],
                ],
            ];
        });

        $this->idempotencyService->store(
            $route,
            $idempotencyKey,
            $requestHash,
            $result['status_code'],
            $result['body']
        );

        return $result;
    }

    private function resolveDevice(Request $request): MobileDevice
    {
        $deviceId = (string) $request->header('X-Device-Id', '');
        if ($deviceId === '') {
            $deviceId = (string) Str::uuid();
        }

        return MobileDevice::query()->firstOrCreate(
            ['device_uuid' => $deviceId],
            [
                'name' => 'MVP Device',
                'is_active' => true,
                'last_seen_at' => now(),
            ]
        );
    }

    private function mapAnswerToInternalRefs(int $sid, string $version, array $answer, int $index): array
    {
        $questionCode = (string) ($answer['question_code'] ?? '');
        if ($questionCode === '') {
            return [
                'error' => [
                    'code' => 'INVALID_ANSWER_PAYLOAD',
                    'message' => 'Each answer must include question_code.',
                    'item' => 'answers.' . $index,
                ],
            ];
        }

        $value = $answer['value'] ?? null;
        $pairs = [];

        if (array_key_exists('subquestion_code', $answer) && $answer['subquestion_code'] !== null && trim((string) $answer['subquestion_code']) !== '') {
            $subCode = (string) $answer['subquestion_code'];
            $internalRef = $this->limeSurveyAdapter->resolveInternalRef($sid, $version, $questionCode, $subCode);
            if (!$internalRef) {
                return [
                    'error' => [
                        'code' => 'MAPPING_NOT_FOUND',
                        'message' => 'No internal mapping found for answer.',
                        'item' => $questionCode . '.' . $subCode,
                    ],
                ];
            }

            $pairs[$internalRef] = $this->extractAnswerValue($answer);
            return ['pairs' => $pairs];
        }

        if (is_array($value) && array_key_exists('selected_subquestion_codes', $value)) {
            foreach (($value['selected_subquestion_codes'] ?? []) as $sqCodeRaw) {
                $sqCode = (string) $sqCodeRaw;
                if ($sqCode === '') {
                    continue;
                }

                $internalRef = $this->limeSurveyAdapter->resolveInternalRef($sid, $version, $questionCode, $sqCode);
                if (!$internalRef) {
                    return [
                        'error' => [
                            'code' => 'MAPPING_NOT_FOUND',
                            'message' => 'No internal mapping found for multi-select subquestion.',
                            'item' => $questionCode . '.' . $sqCode,
                        ],
                    ];
                }
                $pairs[$internalRef] = 'Y';
            }

            $otherText = $value['other_text'] ?? null;
            if (is_string($otherText) && trim($otherText) !== '') {
                $otherRef = $this->limeSurveyAdapter->resolveInternalRef($sid, $version, $questionCode, 'other');
                if ($otherRef) {
                    $pairs[$otherRef] = $otherText;
                }
            }

            return ['pairs' => $pairs];
        }

        $mainRef = $this->limeSurveyAdapter->resolveInternalRef($sid, $version, $questionCode, null);
        if (!$mainRef) {
            return [
                'error' => [
                    'code' => 'MAPPING_NOT_FOUND',
                    'message' => 'No internal mapping found for answer.',
                    'item' => $questionCode,
                ],
            ];
        }

        $pairs[$mainRef] = $this->extractAnswerValue($answer);

        return ['pairs' => $pairs];
    }

    private function extractAnswerValue(array $answer): mixed
    {
        if (array_key_exists('value', $answer)) {
            $value = $answer['value'];

            if (is_array($value)) {
                if (array_key_exists('option_code', $value)) {
                    return $value['option_code'];
                }

                if (array_key_exists('text', $value)) {
                    return $value['text'];
                }
            }

            return $value;
        }

        if (array_key_exists('response', $answer)) {
            return $answer['response'];
        }

        return null;
    }

    private function storeRejectedInterview(
        int $batchId,
        string $interviewUuid,
        int $sid,
        string $version,
        string $code,
        string $message
    ): void {
        SyncInterview::query()->updateOrCreate(
            ['interview_uuid' => $interviewUuid],
            [
                'sync_batch_id' => $batchId,
                'form_sid' => $sid,
                'form_version' => $version,
                'status' => 'rejected',
                'server_ref' => null,
                'error_code' => $code,
                'error_message' => $message,
            ]
        );
    }
}
