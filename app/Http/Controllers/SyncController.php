<?php

namespace App\Http\Controllers;

use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(private readonly SyncService $syncService)
    {
    }

    public function batch(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'batch_id' => ['nullable', 'string'],
            'rescue_mode' => ['nullable', 'boolean'],
            'rescue_optional_question_codes' => ['nullable', 'array'],
            'rescue_optional_question_codes.*' => ['string'],
            'interviews' => ['required', 'array', 'min:1'],
            'interviews.*.interview_uuid' => ['required', 'string'],
            'interviews.*.form_sid' => ['required', 'integer'],
            'interviews.*.form_version' => ['required', 'string'],
            'interviews.*.answers' => ['nullable', 'array'],
            'interviews.*.answers.*.question_code' => ['required', 'string'],
            'interviews.*.answers.*.subquestion_code' => ['nullable', 'string'],
            'interviews.*.answers.*.value' => ['nullable'],
            'interviews.*.answers.*.response' => ['nullable'],
        ]);

        $result = $this->syncService->processBatch($request, $payload);

        return new JsonResponse($result['body'], $result['status_code']);
    }
}
