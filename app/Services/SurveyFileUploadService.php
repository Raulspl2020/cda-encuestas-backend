<?php

namespace App\Services;

use App\Models\SurveyUploadFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SurveyFileUploadService
{
    public function storeUpload(
        int $sid,
        string $interviewUuid,
        string $questionCode,
        UploadedFile $file,
        ?string $title,
        ?string $comment
    ): SurveyUploadFile {
        $disk = (string) env('SURVEY_UPLOAD_DISK', 'local');
        $token = (string) Str::uuid();
        $safeOriginal = trim((string) $file->getClientOriginalName());
        if ($safeOriginal === '') {
            $safeOriginal = 'archivo';
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $tempFilename = 'tmp_' . Str::random(24) . ($extension !== '' ? ('.' . $extension) : '');
        $tempPath = 'survey_uploads/' . $sid . '/' . $interviewUuid . '/' . $tempFilename;

        Storage::disk($disk)->put($tempPath, file_get_contents($file->getRealPath()));

        return SurveyUploadFile::query()->create([
            'file_token' => $token,
            'sid' => $sid,
            'interview_uuid' => $interviewUuid,
            'question_code' => strtoupper(trim($questionCode)),
            'original_name' => $safeOriginal,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'temp_disk' => $disk,
            'temp_path' => $tempPath,
            'title' => $title,
            'comment' => $comment,
            'status' => 'uploaded',
        ]);
    }

    public function buildLimeSurveyFileAnswerValue(
        int $sid,
        string $interviewUuid,
        string $questionCode,
        mixed $value
    ): string {
        $tokens = $this->extractTokens($value);
        if (empty($tokens)) {
            return '[]';
        }

        $files = SurveyUploadFile::query()
            ->where('sid', $sid)
            ->where('interview_uuid', $interviewUuid)
            ->where('question_code', strtoupper(trim($questionCode)))
            ->whereIn('file_token', $tokens)
            ->get()
            ->keyBy('file_token');

        $serialized = [];
        foreach ($tokens as $token) {
            $file = $files->get($token);
            if (!$file) {
                throw new RuntimeException('Uploaded file token not found: ' . $token);
            }

            $lsFilename = $this->ensureCopiedToLimeSurvey($file);
            $serialized[] = [
                'title' => (string) ($file->title ?? ''),
                'comment' => (string) ($file->comment ?? ''),
                'name' => $file->original_name,
                'filename' => $lsFilename,
                'ext' => $this->extensionFromName($file->original_name),
                'size' => round(((int) $file->size_bytes) / 1024, 2),
            ];
        }

        return json_encode($serialized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function ensureCopiedToLimeSurvey(SurveyUploadFile $file): string
    {
        if ($file->ls_filename) {
            return (string) $file->ls_filename;
        }

        $lsUploadDir = trim((string) env('LS_UPLOAD_DIR', ''));
        if ($lsUploadDir === '') {
            throw new RuntimeException('LS_UPLOAD_DIR is not configured.');
        }

        $targetDir = rtrim($lsUploadDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'surveys'
            . DIRECTORY_SEPARATOR
            . $file->sid
            . DIRECTORY_SEPARATOR
            . 'files';

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Unable to create LimeSurvey upload directory: ' . $targetDir);
        }

        $extension = $this->extensionFromName($file->original_name);
        $lsFilename = 'fu_' . Str::random(15) . ($extension !== '' ? '.' . $extension : '');
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $lsFilename;

        $stream = Storage::disk($file->temp_disk)->readStream($file->temp_path);
        if ($stream === false) {
            throw new RuntimeException('Unable to read temporary uploaded file.');
        }

        $written = file_put_contents($targetPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($written === false) {
            throw new RuntimeException('Unable to write file into LimeSurvey directory.');
        }

        $file->ls_filename = $lsFilename;
        $file->ls_relative_path = 'surveys/' . $file->sid . '/files/' . $lsFilename;
        $file->status = 'consumed';
        $file->consumed_at = now();
        $file->save();

        return $lsFilename;
    }

    private function extractTokens(mixed $value): array
    {
        $tokens = [];

        if (is_array($value)) {
            if (isset($value['file_token']) && is_string($value['file_token'])) {
                $tokens[] = $value['file_token'];
            }

            if (isset($value['files']) && is_array($value['files'])) {
                foreach ($value['files'] as $item) {
                    if (is_array($item) && isset($item['file_token']) && is_string($item['file_token'])) {
                        $tokens[] = $item['file_token'];
                    }
                }
            }

            $isList = array_is_list($value);
            if ($isList) {
                foreach ($value as $item) {
                    if (is_array($item) && isset($item['file_token']) && is_string($item['file_token'])) {
                        $tokens[] = $item['file_token'];
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $tokens), fn ($t) => $t !== '')));
    }

    private function extensionFromName(string $name): string
    {
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        return preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
    }
}
