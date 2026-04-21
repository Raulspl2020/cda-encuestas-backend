<?php

namespace App\Http\Controllers;

use App\Services\SurveyFileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SurveyUploadController extends Controller
{
    public function __construct(private readonly SurveyFileUploadService $surveyFileUploadService)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'sid' => ['required', 'integer'],
            'question_code' => ['required', 'string'],
            'interview_uuid' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $upload = $this->surveyFileUploadService->storeUpload(
            sid: (int) $payload['sid'],
            interviewUuid: (string) $payload['interview_uuid'],
            questionCode: (string) $payload['question_code'],
            file: $request->file('file'),
            title: isset($payload['title']) ? (string) $payload['title'] : null,
            comment: isset($payload['comment']) ? (string) $payload['comment'] : null,
        );

        return new JsonResponse([
            'request_id' => (string) Str::uuid(),
            'status' => 'ok',
            'data' => [
                'file_token' => $upload->file_token,
                'metadata' => [
                    'name' => $upload->original_name,
                    'size' => (int) $upload->size_bytes,
                    'mime' => $upload->mime_type,
                    'stored_path' => $upload->temp_path,
                    'question_code' => $upload->question_code,
                ],
            ],
        ]);
    }
}
