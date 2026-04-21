<?php

namespace App\Services;

use App\Adapters\LimeSurveyAdapter;
use App\Models\FormVersionCache;
use App\Models\MobileDevice;
use App\Models\SyncBatch;
use App\Models\SyncInterview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly LimeSurveyAdapter $limeSurveyAdapter,
        private readonly SurveyFileUploadService $surveyFileUploadService,
    ) {
    }

    public function processBatch(Request $request, array $payload): array
    {
        $route = 'api/v1/sync/responses/batch';
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');
        $requestId = (string) Str::uuid();

        if ($idempotencyKey === '') {
            return [
                'status_code' => 400,
                'body' => [
                    'request_id' => $requestId,
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
                        'request_id' => $requestId,
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
                    'request_id' => $requestId,
                    'error' => [
                        'code' => 'INVALID_IDEMPOTENCY_SNAPSHOT',
                        'message' => 'Stored idempotency snapshot is invalid.',
                    ],
                ],
            ];
        }

        $device = $this->resolveDevice($request);
        Log::info('Sync batch request received.', [
            'request_id' => $requestId,
            'idempotency_key' => $idempotencyKey,
            'device_uuid' => $device->device_uuid,
            'interviews_count' => count($payload['interviews'] ?? []),
        ]);

        $result = DB::transaction(function () use ($payload, $idempotencyKey, $device, $requestId) {
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
            $preparedForms = [];

            foreach (($payload['interviews'] ?? []) as $interview) {
                $interviewUuid = (string) ($interview['interview_uuid'] ?? '');

                if ($interviewUuid === '') {
                    $rejected++;
                    $error = [
                        'code' => 'INVALID_PAYLOAD',
                        'message' => 'Interview UUID is required.',
                        'item' => 'interviews.*.interview_uuid',
                    ];
                    $results[] = [
                        'interview_uuid' => '',
                        'status' => 'rejected',
                        'error' => $error,
                    ];
                    $this->logInterviewIssue($requestId, $batch->batch_uuid, '', 0, '', $error, $interview);
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
                    $error = [
                        'code' => 'INVALID_INTERVIEW',
                        'message' => 'Missing form_sid or form_version.',
                        'item' => 'form_sid/form_version',
                    ];
                    $results[] = [
                        'interview_uuid' => $interviewUuid,
                        'status' => 'rejected',
                        'error' => $error,
                    ];
                    $this->logInterviewIssue($requestId, $batch->batch_uuid, $interviewUuid, $sid, $version, $error, $interview);
                    continue;
                }

                $hasVersion = FormVersionCache::query()
                    ->where('sid', $sid)
                    ->where('version', $version)
                    ->exists();

                if (!$hasVersion) {
                    $this->storeRejectedInterview($batch->id, $interviewUuid, $sid, $version, 'FORM_VERSION_NOT_FOUND', 'Form version does not exist in cache.');
                    $rejected++;
                    $error = [
                        'code' => 'FORM_VERSION_NOT_FOUND',
                        'message' => 'Form version does not exist in cache.',
                        'item' => 'form_version',
                    ];
                    $results[] = [
                        'interview_uuid' => $interviewUuid,
                        'status' => 'rejected',
                        'error' => $error,
                    ];
                    $this->logInterviewIssue($requestId, $batch->batch_uuid, $interviewUuid, $sid, $version, $error, $interview);
                    continue;
                }

                $formKey = $sid . '|' . $version;
                if (!isset($preparedForms[$formKey])) {
                    $formPayload = $this->limeSurveyAdapter->getFormByVersion($sid, $version);
                    if (!is_array($formPayload)) {
                        $preparedForms[$formKey] = [
                            'ready' => false,
                            'missing_required_codes' => [],
                            'missing_codes' => [],
                            'question_types' => [],
                            'error' => [
                                'code' => 'FORM_VERSION_PAYLOAD_NOT_FOUND',
                                'message' => 'Unable to load cached form payload for mapping.',
                                'item' => 'form_version_payload',
                            ],
                        ];
                    } else {
                        $preparedForms[$formKey] = array_merge(
                            $this->limeSurveyAdapter->ensureQuestionMap($sid, $version, $formPayload),
                            ['question_types' => $this->buildQuestionTypeMap($formPayload)]
                        );
                    }
                }

                $prepared = $preparedForms[$formKey];
                if (($prepared['ready'] ?? false) !== true) {
                    $missingCode = $prepared['missing_required_codes'][0] ?? null;
                    $error = $prepared['error'] ?? [
                        'code' => 'FORM_MAPPING_INCOMPLETE',
                        'message' => $missingCode !== null
                            ? 'No existe mapping para la pregunta obligatoria ' . $missingCode
                            : 'Form question mapping is incomplete for this version.',
                        'item' => $missingCode ?? 'form_mapping',
                    ];

                    $this->storeRejectedInterview(
                        $batch->id,
                        $interviewUuid,
                        $sid,
                        $version,
                        (string) ($error['code'] ?? 'FORM_MAPPING_INCOMPLETE'),
                        (string) ($error['message'] ?? 'Form question mapping is incomplete.')
                    );

                    $rejected++;
                    $results[] = [
                        'interview_uuid' => $interviewUuid,
                        'status' => 'rejected',
                        'error' => $error,
                    ];
                    $this->logInterviewIssue($requestId, $batch->batch_uuid, $interviewUuid, $sid, $version, $error, $interview);
                    continue;
                }

                $resolvedAnswers = [];
                $mappingError = null;

                foreach (($interview['answers'] ?? []) as $idx => $answer) {
                    $mapped = $this->mapAnswerToInternalRefs(
                        $sid,
                        $version,
                        $interviewUuid,
                        $prepared['question_types'] ?? [],
                        $answer,
                        $idx,
                    );
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
                    $this->logInterviewIssue($requestId, $batch->batch_uuid, $interviewUuid, $sid, $version, $mappingError, $interview);
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
                    $error = [
                        'code' => 'PERSIST_FAILED',
                        'message' => (string) ($persistResult['error'] ?? 'Unknown persist failure'),
                        'item' => 'limesurvey.insert',
                    ];
                    $results[] = [
                        'interview_uuid' => $interviewUuid,
                        'status' => 'rejected',
                        'error' => $error,
                    ];
                    $this->logInterviewIssue($requestId, $batch->batch_uuid, $interviewUuid, $sid, $version, $error, $interview);
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

            $statusCode = $batch->status === 'success' ? 200 : 207;

            Log::info('Sync batch processed.', [
                'request_id' => $requestId,
                'batch_id' => $batch->batch_uuid,
                'status' => $batch->status,
                'accepted' => $accepted,
                'rejected' => $rejected,
                'http_status' => $statusCode,
            ]);

            return [
                'status_code' => $statusCode,
                'body' => [
                    'request_id' => $requestId,
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

    private function logInterviewIssue(
        string $requestId,
        string $batchId,
        string $interviewUuid,
        int $sid,
        string $version,
        array $error,
        array $interview
    ): void {
        $answers = $interview['answers'] ?? [];
        $answerCodes = [];
        if (is_array($answers)) {
            foreach ($answers as $answer) {
                if (is_array($answer)) {
                    $code = (string) ($answer['question_code'] ?? '');
                    if ($code !== '') {
                        $answerCodes[] = $code;
                    }
                }
            }
        }

        Log::warning('Sync interview rejected.', [
            'request_id' => $requestId,
            'batch_id' => $batchId,
            'interview_uuid' => $interviewUuid,
            'form_sid' => $sid,
            'form_version' => $version,
            'error' => $error,
            'payload_summary' => [
                'answers_count' => is_array($answers) ? count($answers) : 0,
                'answer_codes' => array_slice($answerCodes, 0, 20),
            ],
        ]);
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

    private function mapAnswerToInternalRefs(
        int $sid,
        string $version,
        string $interviewUuid,
        array $questionTypes,
        array $answer,
        int $index
    ): array
    {
        $questionCodeRaw = (string) ($answer['question_code'] ?? '');
        $questionCode = $this->limeSurveyAdapter->normalizeMappingCode($questionCodeRaw);
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
        $questionType = (string) ($questionTypes[$questionCode] ?? '');

        if (array_key_exists('subquestion_code', $answer) && $answer['subquestion_code'] !== null && trim((string) $answer['subquestion_code']) !== '') {
            $subCodeRaw = (string) $answer['subquestion_code'];
            $subCode = $this->limeSurveyAdapter->normalizeMappingCode($subCodeRaw);
            $internalRef = $this->limeSurveyAdapter->resolveInternalRef($sid, $version, $questionCode, $subCode);
            if (!$internalRef) {
                return [
                    'error' => [
                        'code' => 'MAPPING_NOT_FOUND',
                        'message' => 'No internal mapping found for answer.',
                        'item' => ($questionCodeRaw !== '' ? $questionCodeRaw : $questionCode) . '.' . ($subCodeRaw !== '' ? $subCodeRaw : $subCode),
                    ],
                ];
            }

            $pairs[$internalRef] = $this->extractAnswerValue($answer);
            return ['pairs' => $pairs];
        }

        if ($questionType === 'file_upload') {
            $mainRef = $this->limeSurveyAdapter->resolveInternalRef($sid, $version, $questionCode, null);
            if (!$mainRef) {
                return [
                    'error' => [
                        'code' => 'MAPPING_NOT_FOUND',
                        'message' => 'No internal mapping found for file upload answer.',
                        'item' => $questionCodeRaw !== '' ? $questionCodeRaw : $questionCode,
                    ],
                ];
            }

            try {
                $pairs[$mainRef] = $this->surveyFileUploadService->buildLimeSurveyFileAnswerValue(
                    $sid,
                    $interviewUuid,
                    $questionCode,
                    $value,
                );
            } catch (\RuntimeException $e) {
                return [
                    'error' => [
                        'code' => 'FILE_UPLOAD_INVALID',
                        'message' => $e->getMessage(),
                        'item' => $questionCodeRaw !== '' ? $questionCodeRaw : $questionCode,
                    ],
                ];
            }

            return ['pairs' => $pairs];
        }

        if (is_array($value) && array_key_exists('selected_subquestion_codes', $value)) {
            foreach (($value['selected_subquestion_codes'] ?? []) as $sqCodeRaw) {
                $sqCode = $this->limeSurveyAdapter->normalizeMappingCode((string) $sqCodeRaw);
                if ($sqCode === '') {
                    continue;
                }

                $internalRef = $this->limeSurveyAdapter->resolveInternalRef($sid, $version, $questionCode, $sqCode);
                if (!$internalRef) {
                    return [
                        'error' => [
                            'code' => 'MAPPING_NOT_FOUND',
                            'message' => 'No internal mapping found for multi-select subquestion.',
                            'item' => ($questionCodeRaw !== '' ? $questionCodeRaw : $questionCode) . '.' . (string) $sqCodeRaw,
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

    private function buildQuestionTypeMap(array $formPayload): array
    {
        $map = [];
        foreach (($formPayload['questions'] ?? []) as $question) {
            if (!is_array($question)) {
                continue;
            }

            $code = $this->limeSurveyAdapter->normalizeMappingCode((string) ($question['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $map[$code] = (string) ($question['type'] ?? '');
        }

        return $map;
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
