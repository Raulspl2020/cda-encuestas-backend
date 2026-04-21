<?php

namespace App\Console\Commands;

use App\Adapters\LimeSurveyAdapter;
use Illuminate\Console\Command;

class LimeSurveyRebuildMapCommand extends Command
{
    protected $signature = 'limesurvey:rebuild-map {sid} {version}';

    protected $description = 'Rebuild and validate form question map for a survey version';

    public function __construct(private readonly LimeSurveyAdapter $limeSurveyAdapter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $sid = (int) $this->argument('sid');
        $version = (string) $this->argument('version');

        if ($sid <= 0 || trim($version) === '') {
            $this->error('Invalid arguments. Usage: php artisan limesurvey:rebuild-map <sid> <version>');
            return self::FAILURE;
        }

        $payload = $this->limeSurveyAdapter->getFormByVersion($sid, $version);
        if (!is_array($payload)) {
            $this->error("Form version not found in cache for sid={$sid}, version={$version}");
            return self::FAILURE;
        }

        $stats = $this->limeSurveyAdapter->syncQuestionMapFromPayload($sid, $version, $payload);
        $coverage = $this->limeSurveyAdapter->validateMappingCoverage($sid, $version, $payload);

        $this->line('--- Mapping rebuild summary ---');
        $this->line("sid: {$sid}");
        $this->line("version: {$version}");
        $this->line('total_questions: ' . ($stats['total_questions'] ?? 0));
        $this->line('mapped_questions: ' . ($stats['mapped_questions'] ?? 0));
        $this->line('mapped_rows: ' . ($stats['mapped_rows'] ?? 0));
        $this->line('missing_question_codes: ' . json_encode($stats['missing_question_codes'] ?? []));
        $this->line('missing_subquestion_codes: ' . json_encode($stats['missing_subquestion_codes'] ?? []));
        $this->line('missing_required_codes: ' . json_encode($coverage['missing_required_codes'] ?? []));
        $this->line('ready: ' . (($coverage['ready'] ?? false) ? 'true' : 'false'));

        $normalizedCedulaCode = $this->limeSurveyAdapter->normalizeMappingCode('G01Q33');
        $cedulaRef = $this->limeSurveyAdapter->resolveInternalRef($sid, $version, $normalizedCedulaCode, null);
        $this->line('G01Q33_internal_ref: ' . ($cedulaRef ?? 'NOT_FOUND'));

        if (($coverage['ready'] ?? false) !== true) {
            $this->error('Mapping is incomplete. Mandatory questions are missing references.');
            return self::FAILURE;
        }

        $this->info('Mapping rebuild completed successfully.');
        return self::SUCCESS;
    }
}
