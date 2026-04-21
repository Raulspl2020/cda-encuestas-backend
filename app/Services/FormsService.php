<?php

namespace App\Services;

use App\Adapters\LimeSurveyAdapter;
use App\Models\FormVersionCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class FormsService
{
    public function __construct(private readonly LimeSurveyAdapter $limeSurveyAdapter)
    {
    }

    public function getActiveForms(): array
    {
        $cached = FormVersionCache::query()
            ->where('is_active', true)
            ->orderByDesc('published_at')
            ->get()
            ->map(function (FormVersionCache $item): array {
                $payload = json_decode((string) $item->payload_json, true);
                $title = is_array($payload) ? (string) ($payload['title'] ?? ('Formulario ' . $item->sid)) : ('Formulario ' . $item->sid);

                return [
                    'sid' => (int) $item->sid,
                    'form_id' => 'form_' . $item->sid,
                    'title' => $title,
                    'status' => 'active',
                    'current_version' => (string) $item->version,
                    'version_hash' => (string) $item->version_hash,
                    'published_at' => optional($item->published_at)?->toIso8601String(),
                    'active_from' => optional($item->active_from)?->toIso8601String(),
                    'active_to' => optional($item->active_to)?->toIso8601String(),
                    'requires_download' => true,
                ];
            })
            ->unique('sid')
            ->values()
            ->all();

        $cachedBySid = [];
        foreach ($cached as $item) {
            $sid = (int) ($item['sid'] ?? 0);
            if ($sid > 0) {
                $cachedBySid[$sid] = $item;
            }
        }

        $activeFromLs = $this->fetchActiveFormsFromLimeSurvey();
        $activeSids = [];
        $merged = [];

        foreach ($activeFromLs as $form) {
            $sid = (int) ($form['sid'] ?? 0);
            if ($sid <= 0) {
                continue;
            }

            $activeSids[$sid] = true;
            $merged[] = isset($cachedBySid[$sid])
                ? array_merge($cachedBySid[$sid], $form)
                : $form;
        }

        if (empty($activeSids)) {
            $merged = array_values($cachedBySid);
        }

        usort($merged, function (array $a, array $b): int {
            $aVersion = (string) ($a['current_version'] ?? '');
            $bVersion = (string) ($b['current_version'] ?? '');

            $byVersion = strcmp($bVersion, $aVersion);
            if ($byVersion !== 0) {
                return $byVersion;
            }

            return ((int) ($b['sid'] ?? 0)) <=> ((int) ($a['sid'] ?? 0));
        });

        return $merged;
    }

    public function getFormVersion(int $sid, string $version): ?array
    {
        $cached = FormVersionCache::query()
            ->where('sid', $sid)
            ->where('version', $version)
            ->first();

        if ($cached) {
            $decoded = json_decode((string) $cached->payload_json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $payload = $this->limeSurveyAdapter->buildFallbackFormPayload($sid, $version);

        FormVersionCache::query()->updateOrCreate(
            [
                'sid' => $sid,
                'version' => $version,
            ],
            [
                'version_hash' => (string) ($payload['version_hash'] ?? hash('sha256', $sid . '|' . $version)),
                'is_active' => true,
                'published_at' => now(),
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );

        return $payload;
    }

    public function getFormVersionSyncReadiness(int $sid, string $version, array $payload): array
    {
        $mapStatus = $this->limeSurveyAdapter->ensureQuestionMap($sid, $version, $payload);

        return [
            'ready' => (bool) ($mapStatus['ready'] ?? false),
            'missing_required_codes' => $mapStatus['missing_required_codes'] ?? [],
            'missing_codes' => $mapStatus['missing_codes'] ?? [],
            'mapped_questions' => (int) ($mapStatus['mapped_questions'] ?? 0),
            'total_questions' => (int) ($mapStatus['total_questions'] ?? 0),
        ];
    }

    private function fetchActiveFormsFromLimeSurvey(): array
    {
        $prefix = (string) env('LS_DB_PREFIX', 'cda_');
        $surveys = $prefix . 'surveys';
        $langs = $prefix . 'surveys_languagesettings';

        $rows = DB::connection('limesurvey')->select(
            "SELECT s.sid, s.datecreated, s.startdate, COALESCE(sl.surveyls_title, CONCAT('Formulario ', s.sid)) AS title\n"
            . "FROM {$surveys} s\n"
            . "LEFT JOIN {$langs} sl ON sl.surveyls_survey_id = s.sid AND sl.surveyls_language = s.language\n"
            . "WHERE s.active = 'Y'\n"
            . "ORDER BY s.datecreated DESC, s.sid DESC"
        );

        return array_map(function (object $row): array {
            $sid = (int) ($row->sid ?? 0);
            $created = null;
            $dateCreatedRaw = (string) ($row->datecreated ?? '');
            if ($dateCreatedRaw !== '') {
                try {
                    $created = Carbon::parse($dateCreatedRaw)->utc();
                } catch (\Throwable) {
                    $created = null;
                }
            }

            $activeFrom = null;
            $startDateRaw = (string) ($row->startdate ?? '');
            if ($startDateRaw !== '') {
                try {
                    $activeFrom = Carbon::parse($startDateRaw)->utc();
                } catch (\Throwable) {
                    $activeFrom = null;
                }
            }

            $version = $created?->format('YmdHis') ?? date('YmdHis');

            return [
                'sid' => $sid,
                'form_id' => 'form_' . $sid,
                'title' => (string) ($row->title ?? ('Formulario ' . $sid)),
                'status' => 'active',
                'current_version' => $version,
                'version_hash' => hash('sha256', $sid . '|' . $version),
                'published_at' => $created?->toIso8601String(),
                'active_from' => $activeFrom?->toIso8601String(),
                'active_to' => null,
                'requires_download' => true,
            ];
        }, $rows);
    }
}
