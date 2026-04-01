<?php

namespace App\Services;

use App\Models\ApiIdempotencyKey;

class IdempotencyService
{
    public function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function find(string $route, string $idempotencyKey): ?ApiIdempotencyKey
    {
        return ApiIdempotencyKey::query()
            ->where('route', $route)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function store(
        string $route,
        string $idempotencyKey,
        string $requestHash,
        int $statusCode,
        array $responseBody
    ): void {
        ApiIdempotencyKey::query()->updateOrCreate(
            [
                'route' => $route,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'request_hash' => $requestHash,
                'status_code' => $statusCode,
                'response_json' => json_encode($responseBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'expires_at' => now()->addDays(7),
            ]
        );
    }
}
