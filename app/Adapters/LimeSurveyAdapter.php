<?php

namespace App\Adapters;

use App\Models\FormQuestionMap;
use App\Models\FormVersionCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LimeSurveyAdapter
{
    public function getActiveForms(): array
    {
        return FormVersionCache::query()
            ->where('is_active', true)
            ->orderByRaw('COALESCE(published_at, updated_at, created_at) DESC')
            ->orderByDesc('id')
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
    }

    public function getFormByVersion($sid, $version): ?array
    {
        $cached = FormVersionCache::query()
            ->where('sid', (int) $sid)
            ->where('version', (string) $version)
            ->first();

        if (!$cached) {
            return null;
        }

        $decoded = json_decode((string) $cached->payload_json, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function resolveInternalRef($sid, $version, $questionCode, $subquestionCode = null): ?string
    {
        $query = FormQuestionMap::query()
            ->where('sid', (int) $sid)
            ->where('version', (string) $version)
            ->where('question_code', (string) $questionCode);

        if ($subquestionCode === null) {
            $query->whereNull('subquestion_code');
        } else {
            $query->where('subquestion_code', (string) $subquestionCode);
        }

        return $query->value('internal_ref');
    }

    public function persistInterview(array $interview, array $resolvedAnswers): array
    {
        $sid = (int) ($interview['form_sid'] ?? 0);
        if ($sid <= 0) {
            return [
                'ok' => false,
                'server_ref' => null,
                'error' => 'INVALID_SID',
            ];
        }

        $table = (string) env('LS_DB_PREFIX', 'cda_') . 'survey_' . $sid;

        if (!Schema::connection('limesurvey')->hasTable($table)) {
            return [
                'ok' => false,
                'server_ref' => null,
                'error' => 'TABLE_NOT_FOUND: ' . $table,
            ];
        }

        $columns = DB::connection('limesurvey')->select('SHOW COLUMNS FROM `' . $table . '`');
        $columnMap = [];
        foreach ($columns as $col) {
            $name = (string) ($col->Field ?? '');
            if ($name !== '') {
                $columnMap[$name] = strtolower((string) ($col->Type ?? 'text'));
            }
        }

        $payload = [];
        $mappedAnswerCount = 0;
        foreach ($resolvedAnswers as $column => $value) {
            if (!isset($columnMap[$column])) {
                continue;
            }
            $payload[$column] = $this->normalizeValueForType($value, $columnMap[$column]);
            $mappedAnswerCount++;
        }

        $now = now()->format('Y-m-d H:i:s');
        foreach (['datestamp', 'startdate', 'submitdate'] as $dateCol) {
            if (isset($columnMap[$dateCol]) && !isset($payload[$dateCol])) {
                $payload[$dateCol] = $now;
            }
        }

        if (isset($columnMap['startlanguage']) && !isset($payload['startlanguage'])) {
            $payload['startlanguage'] = $this->getSurveyLanguage($sid);
        }

        if ($mappedAnswerCount === 0) {
            return [
                'ok' => false,
                'server_ref' => null,
                'error' => 'NO_MAPPED_ANSWERS_TO_PERSIST',
            ];
        }

        try {
            $id = DB::connection('limesurvey')->table($table)->insertGetId($payload);

            return [
                'ok' => true,
                'server_ref' => $table . ':' . $id,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'server_ref' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function buildFallbackFormPayload(int $sid, string $version): array
    {
        $meta = $this->fetchSurveyMeta($sid);
        if ($meta === null) {
            return [
                'sid' => $sid,
                'version' => $version,
                'title' => 'Formulario ' . $sid,
                'version_hash' => hash('sha256', $sid . '|' . $version),
                'welcome_html' => '',
                'end_html' => '',
                'groups' => [],
                'questions' => [],
                'rules' => ['conditions' => []],
            ];
        }

        $prefix = (string) env('LS_DB_PREFIX', 'cda_');
        $language = $meta['language'];

        $groups = DB::connection('limesurvey')
            ->table($prefix . 'groups as g')
            ->leftJoin($prefix . 'group_l10ns as gl', function ($join) use ($language) {
                $join->on('gl.gid', '=', 'g.gid')->where('gl.language', '=', $language);
            })
            ->where('g.sid', $sid)
            ->orderBy('g.group_order')
            ->get([
                'g.gid',
                'g.group_order',
                'g.grelevance',
                DB::raw('COALESCE(gl.group_name, CONCAT("Sección ", g.gid)) as group_name'),
            ]);

        $questionsRaw = DB::connection('limesurvey')
            ->table($prefix . 'questions as q')
            ->leftJoin($prefix . 'question_l10ns as ql', function ($join) use ($language) {
                $join->on('ql.qid', '=', 'q.qid')->where('ql.language', '=', $language);
            })
            ->where('q.sid', $sid)
            ->where('q.parent_qid', 0)
            ->orderBy('q.question_order')
            ->get([
                'q.qid',
                'q.gid',
                'q.type',
                'q.title',
                'q.question_order',
                'q.relevance',
                'q.mandatory',
                'q.other',
                DB::raw('COALESCE(ql.question, q.title) as question_text'),
            ]);

        $qids = $questionsRaw->pluck('qid')->map(fn ($v) => (int) $v)->values()->all();

        $attributesByQid = [];
        if (!empty($qids)) {
            $attrs = DB::connection('limesurvey')
                ->table($prefix . 'question_attributes')
                ->whereIn('qid', $qids)
                ->where(function ($q) use ($language) {
                    $q->where('language', $language)->orWhereNull('language');
                })
                ->get(['qid', 'attribute', 'value', 'language']);

            foreach ($attrs as $attr) {
                $qid = (int) $attr->qid;
                $name = (string) $attr->attribute;
                if ($name === '') {
                    continue;
                }
                if (!isset($attributesByQid[$qid])) {
                    $attributesByQid[$qid] = [];
                }
                $attributesByQid[$qid][$name] = (string) ($attr->value ?? '');
            }
        }

        $optionsByQid = [];
        if (!empty($qids)) {
            $options = DB::connection('limesurvey')
                ->table($prefix . 'answers as a')
                ->leftJoin($prefix . 'answer_l10ns as al', function ($join) use ($language) {
                    $join->on('al.aid', '=', 'a.aid')->where('al.language', '=', $language);
                })
                ->whereIn('a.qid', $qids)
                ->where('a.scale_id', 0)
                ->orderBy('a.sortorder')
                ->get([
                    'a.qid',
                    'a.code',
                    DB::raw('COALESCE(al.answer, a.code) as answer_text'),
                ]);

            foreach ($options as $opt) {
                $qid = (int) $opt->qid;
                if (!isset($optionsByQid[$qid])) {
                    $optionsByQid[$qid] = [];
                }
                $optionsByQid[$qid][] = [
                    'code' => (string) $opt->code,
                    'label' => [
                        $language => (string) ($opt->answer_text ?? $opt->code),
                    ],
                ];
            }
        }

        $subByParent = [];
        if (!empty($qids)) {
            $subs = DB::connection('limesurvey')
                ->table($prefix . 'questions as sq')
                ->leftJoin($prefix . 'question_l10ns as sql', function ($join) use ($language) {
                    $join->on('sql.qid', '=', 'sq.qid')->where('sql.language', '=', $language);
                })
                ->whereIn('sq.parent_qid', $qids)
                ->where('sq.scale_id', 0)
                ->orderBy('sq.question_order')
                ->get([
                    'sq.parent_qid',
                    'sq.title',
                    'sq.question_order',
                    DB::raw('COALESCE(sql.question, sq.title) as question_text'),
                ]);

            foreach ($subs as $sub) {
                $parent = (int) $sub->parent_qid;
                if (!isset($subByParent[$parent])) {
                    $subByParent[$parent] = [];
                }
                $subByParent[$parent][] = [
                    'code' => (string) $sub->title,
                    'text' => [
                        $language => (string) ($sub->question_text ?? $sub->title),
                    ],
                    '_order' => (int) ($sub->question_order ?? 0),
                ];
            }
        }

        foreach ($subByParent as $parent => $subs) {
            usort($subs, fn ($a, $b) => ($a['_order'] <=> $b['_order']));
            $subByParent[$parent] = array_map(function (array $sq): array {
                unset($sq['_order']);
                return $sq;
            }, $subs);
        }

        $questionCodeByQid = [];
        foreach ($questionsRaw as $q) {
            $questionCodeByQid[(int) $q->qid] = (string) $q->title;
        }

        $conditions = [];
        if (!empty($qids)) {
            $conditionRows = DB::connection('limesurvey')
                ->table($prefix . 'conditions')
                ->whereIn('qid', $qids)
                ->get(['qid', 'cqid', 'method', 'value', 'scenario']);

            foreach ($conditionRows as $c) {
                $targetCode = $questionCodeByQid[(int) $c->qid] ?? null;
                $sourceCode = $questionCodeByQid[(int) $c->cqid] ?? null;
                if (!$targetCode || !$sourceCode) {
                    continue;
                }

                $conditions[] = [
                    'target_question_code' => $targetCode,
                    'source_question_code' => $sourceCode,
                    'operator' => $this->normalizeConditionOperator((string) ($c->method ?? '==')),
                    'value' => (string) ($c->value ?? ''),
                    'scenario' => (int) ($c->scenario ?? 1),
                ];
            }
        }

        $questions = [];
        foreach ($questionsRaw as $q) {
            $qid = (int) $q->qid;
            $mappedType = $this->mapQuestionType((string) $q->type);

            $questions[] = [
                'gid' => (int) $q->gid,
                'code' => (string) $q->title,
                'type' => $mappedType,
                'order' => (int) ($q->question_order ?? 0),
                'text' => [
                    $language => (string) ($q->question_text ?? $q->title),
                ],
                'relevance' => trim((string) ($q->relevance ?? '')) === '' ? '1' : (string) $q->relevance,
                'mandatory' => strtoupper((string) ($q->mandatory ?? 'N')) === 'Y',
                'supports_multi_select' => $mappedType === 'multi_option',
                'supports_other' => strtoupper((string) ($q->other ?? 'N')) === 'Y',
                'attributes' => $attributesByQid[$qid] ?? [],
                'options' => $optionsByQid[$qid] ?? [],
                'subquestions' => $subByParent[$qid] ?? [],
            ];
        }

        $groupPayload = [];
        foreach ($groups as $g) {
            $groupPayload[] = [
                'gid' => (int) $g->gid,
                'order' => (int) ($g->group_order ?? 0),
                'name' => (string) ($g->group_name ?? ('Sección ' . $g->gid)),
                'relevance' => trim((string) ($g->grelevance ?? '')) === '' ? '1' : (string) $g->grelevance,
            ];
        }

        return [
            'sid' => $sid,
            'version' => $version,
            'title' => $meta['title'],
            'version_hash' => hash('sha256', $sid . '|' . $version),
            'welcome_html' => $meta['welcome_html'],
            'end_html' => $meta['end_html'],
            'groups' => $groupPayload,
            'questions' => $questions,
            'rules' => [
                'conditions' => $conditions,
            ],
        ];
    }

    public function syncQuestionMapFromPayload(int $sid, string $version, array $payload): void
    {
        FormQuestionMap::query()->where('sid', $sid)->where('version', $version)->delete();

        $prefix = (string) env('LS_DB_PREFIX', 'cda_');
        $questions = DB::connection('limesurvey')
            ->table($prefix . 'questions')
            ->where('sid', $sid)
            ->get(['qid', 'gid', 'title', 'parent_qid']);

        $parentsByCode = [];
        $childrenByParentAndCode = [];
        foreach ($questions as $q) {
            $qid = (int) $q->qid;
            $parentQid = (int) $q->parent_qid;
            $title = (string) $q->title;
            if ($title === '') {
                continue;
            }

            if ($parentQid === 0) {
                $parentsByCode[$title] = [
                    'qid' => $qid,
                    'gid' => (int) $q->gid,
                ];
            } else {
                if (!isset($childrenByParentAndCode[$parentQid])) {
                    $childrenByParentAndCode[$parentQid] = [];
                }
                $childrenByParentAndCode[$parentQid][$title] = [
                    'qid' => $qid,
                    'gid' => (int) $q->gid,
                ];
            }
        }

        foreach (($payload['questions'] ?? []) as $question) {
            $questionCode = (string) ($question['code'] ?? '');
            if ($questionCode === '') {
                continue;
            }

            $parent = $parentsByCode[$questionCode] ?? null;
            if ($parent === null) {
                continue;
            }

            $baseRef = $sid . 'X' . $parent['gid'] . 'X' . $parent['qid'];

            FormQuestionMap::query()->create([
                'sid' => $sid,
                'version' => $version,
                'question_code' => $questionCode,
                'subquestion_code' => null,
                'internal_ref' => $baseRef,
            ]);

            foreach (($question['subquestions'] ?? []) as $subquestion) {
                $subCode = (string) ($subquestion['code'] ?? '');
                if ($subCode === '') {
                    continue;
                }

                $child = $childrenByParentAndCode[$parent['qid']][$subCode] ?? null;
                if ($child === null) {
                    continue;
                }

                FormQuestionMap::query()->create([
                    'sid' => $sid,
                    'version' => $version,
                    'question_code' => $questionCode,
                    'subquestion_code' => $subCode,
                    'internal_ref' => $baseRef . $subCode,
                ]);
            }

            if (($question['supports_other'] ?? false) === true) {
                FormQuestionMap::query()->create([
                    'sid' => $sid,
                    'version' => $version,
                    'question_code' => $questionCode,
                    'subquestion_code' => 'other',
                    'internal_ref' => $baseRef . 'other',
                ]);
            }
        }
    }

    private function getSurveyLanguage(int $sid): string
    {
        $prefix = (string) env('LS_DB_PREFIX', 'cda_');
        $row = DB::connection('limesurvey')
            ->table($prefix . 'surveys')
            ->where('sid', $sid)
            ->first(['language']);

        return (string) ($row->language ?? 'es-CO');
    }

    private function fetchSurveyMeta(int $sid): ?array
    {
        $prefix = (string) env('LS_DB_PREFIX', 'cda_');
        $row = DB::connection('limesurvey')->selectOne(
            "SELECT s.language AS base_language,\n"
            . "COALESCE(sl.surveyls_title, CONCAT('Formulario ', s.sid)) AS title,\n"
            . "COALESCE(sl.surveyls_welcometext, '') AS welcome_html,\n"
            . "COALESCE(sl.surveyls_endtext, '') AS end_html\n"
            . "FROM {$prefix}surveys s\n"
            . "LEFT JOIN {$prefix}surveys_languagesettings sl ON sl.surveyls_survey_id = s.sid AND sl.surveyls_language = s.language\n"
            . "WHERE s.sid = ? LIMIT 1",
            [$sid]
        );

        if (!$row) {
            return null;
        }

        $language = (string) ($row->base_language ?? 'es-CO');
        if ($language === '') {
            $language = 'es-CO';
        }

        return [
            'language' => $language,
            'title' => (string) ($row->title ?? ('Formulario ' . $sid)),
            'welcome_html' => (string) ($row->welcome_html ?? ''),
            'end_html' => (string) ($row->end_html ?? ''),
        ];
    }

    private function normalizeConditionOperator(string $method): string
    {
        $method = trim($method);

        return in_array($method, ['==', '!=', '>', '<', '>=', '<='], true) ? $method : '==';
    }

    private function mapQuestionType(string $lsType): string
    {
        return match (strtoupper(trim($lsType))) {
            'T', 'U' => 'long_text',
            'S', 'Q' => 'short_text',
            'N' => 'number',
            'D' => 'date',
            'Y' => 'yes_no',
            'L', '!', 'O' => 'single_option',
            'M', 'P' => 'multi_option',
            default => 'text',
        };
    }

    private function normalizeValueForType(mixed $value, string $columnType): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (str_contains($columnType, 'int')) {
            return is_numeric($value) ? (int) $value : 0;
        }

        if (str_contains($columnType, 'decimal') || str_contains($columnType, 'float') || str_contains($columnType, 'double')) {
            return is_numeric($value) ? (float) $value : 0;
        }

        $stringValue = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($stringValue === false) {
            $stringValue = '';
        }

        if (preg_match('/^(?:var)?char\((\d+)\)/', $columnType, $matches) === 1) {
            $max = (int) $matches[1];
            if ($max > 0 && mb_strlen($stringValue) > $max) {
                return mb_substr($stringValue, 0, $max);
            }
        }

        return $stringValue;
    }
}
