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
            'interviews' => ['required', 'array'],
            'interviews.*.interview_uuid' => ['required', 'string'],
            'interviews.*.form_sid' => ['required', 'integer'],
            'interviews.*.form_version' => ['required', 'string'],
            'interviews.*.answers' => ['nullable', 'array'],
        ]);

        $result = $this->syncService->processBatch($request, $payload);

        return new JsonResponse($result['body'], $result['status_code']);
    }
}
