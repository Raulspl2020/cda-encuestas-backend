<?php

declare(strict_types=1);

$baseUrl = $argv[1] ?? 'https://cda-encuestas-backend.vercel.app/api/v1/sync/responses/batch';
$deviceId = $argv[2] ?? 'mobile-mvp-device-01';

function sendBatch(string $url, string $deviceId, string $idempotencyKey, array $payload): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Device-Id: ' . $deviceId,
            'Idempotency-Key: ' . $idempotencyKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 60,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    echo "\n=== {$idempotencyKey} ===\n";
    echo "HTTP: {$httpCode}\n";

    if ($error !== '') {
        echo "cURL error: {$error}\n";
        return;
    }

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        echo "Body: {$body}\n";
        return;
    }

    $result = $decoded['data']['results'][0] ?? null;
    if (is_array($result)) {
        echo 'Interview status: ' . (($result['status'] ?? 'unknown')) . "\n";
        echo 'Error code: ' . (($result['error']['code'] ?? 'N/A')) . "\n";
        echo 'Error item: ' . (($result['error']['item'] ?? 'N/A')) . "\n";
        echo 'Server ref: ' . (($result['server_ref'] ?? 'N/A')) . "\n";
    }

    echo 'Body JSON: ' . json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

$minimalPayload = [
    'batch_id' => 'script-minimal-' . date('YmdHis'),
    'interviews' => [[
        'interview_uuid' => 'aaaaaaa1-1111-1111-1111-111111111111',
        'form_sid' => 868833,
        'form_version' => '20260304005936',
        'answers' => [[
            'question_code' => 'Q00',
            'value' => '2026-04-21',
        ]],
    ]],
];

$cedulaPayload = [
    'batch_id' => 'script-cedula-' . date('YmdHis'),
    'interviews' => [[
        'interview_uuid' => 'aaaaaaa2-2222-2222-2222-222222222222',
        'form_sid' => 868833,
        'form_version' => '20260304005936',
        'answers' => [[
            'question_code' => 'G01Q33',
            'value' => '1020304050',
        ]],
    ]],
];

$fullPayload = [
    'batch_id' => 'script-full-' . date('YmdHis'),
    'interviews' => [[
        'interview_uuid' => 'aaaaaaa3-3333-3333-3333-333333333333',
        'form_sid' => 868833,
        'form_version' => '20260304005936',
        'answers' => [
            ['question_code' => 'Q00', 'value' => '2026-04-21'],
            ['question_code' => 'G01Q33', 'value' => '1020304050'],
            ['question_code' => 'G01Q02', 'value' => 'Prueba Backend'],
            ['question_code' => 'G01Q32', 'value' => '3001234567'],
            ['question_code' => 'G01Q03', 'value' => ['option_code' => 'AO02']],
            ['question_code' => 'G01Q05', 'value' => ['option_code' => 'AO01']],
            ['question_code' => 'G01Q35', 'value' => ['option_code' => 'AO01']],
            ['question_code' => 'G01Q36', 'value' => 'Barrio Centro'],
            ['question_code' => 'G02Q08', 'value' => ['selected_subquestion_codes' => ['SQ001']]],
            ['question_code' => 'G01Q09', 'value' => ['selected_subquestion_codes' => ['SQ001']]],
            ['question_code' => 'G02Q11', 'value' => ['option_code' => 'AO01']],
            ['question_code' => 'G02Q34', 'value' => ['option_code' => 'Y']],
            ['question_code' => 'G01Q15', 'value' => ['option_code' => 'AO01']],
            ['question_code' => 'G01Q16', 'value' => ['option_code' => 'AO01']],
            ['question_code' => 'G01Q10', 'value' => ['option_code' => 'Y']],
            ['question_code' => 'G01Q17', 'value' => ['selected_subquestion_codes' => ['SQ001']]],
            ['question_code' => 'G03Q31', 'value' => 'Ingreso por transporte de plantas'],
        ],
    ]],
];

sendBatch($baseUrl, $deviceId, 'sync-test-minimal-' . uniqid(), $minimalPayload);
sendBatch($baseUrl, $deviceId, 'sync-test-cedula-' . uniqid(), $cedulaPayload);
sendBatch($baseUrl, $deviceId, 'sync-test-full-' . uniqid(), $fullPayload);
