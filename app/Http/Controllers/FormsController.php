<?php

namespace App\Http\Controllers;

use App\Services\FormsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class FormsController extends Controller
{
    public function __construct(private readonly FormsService $formsService)
    {
    }

    public function active(): JsonResponse
    {
        $forms = $this->formsService->getActiveForms();

        return new JsonResponse([
            'request_id' => (string) Str::uuid(),
            'data' => ['forms' => $forms],
            'meta' => ['count' => count($forms)],
        ]);
    }

    public function version(int $sid, string $version): JsonResponse
    {
        $payload = $this->formsService->getFormVersion($sid, $version);

        if ($payload === null) {
            return new JsonResponse([
                'request_id' => (string) Str::uuid(),
                'error' => [
                    'code' => 'FORM_VERSION_NOT_FOUND',
                    'message' => 'Form version not found.',
                ],
            ], 404);
        }

        $syncReadiness = $this->formsService->getFormVersionSyncReadiness($sid, $version, $payload);
        if (!$syncReadiness['ready']) {
            return new JsonResponse([
                'request_id' => (string) Str::uuid(),
                'error' => [
                    'code' => 'FORM_MAPPING_INCOMPLETE',
                    'message' => 'Form version is not ready for offline sync. Missing required mapping.',
                    'item' => $syncReadiness['missing_required_codes'][0] ?? null,
                ],
                'data' => [
                    'sid' => $sid,
                    'version' => $version,
                    'sync_readiness' => $syncReadiness,
                ],
            ], 409);
        }

        $payload['sync_readiness'] = $syncReadiness;

        return new JsonResponse([
            'request_id' => (string) Str::uuid(),
            'data' => $payload,
        ]);
    }
}
