<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Device\DeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceTokenController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DeviceTokenService $deviceTokenService) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'device_type' => ['required', Rule::in(['android'])],
        ]);

        $deviceToken = $this->deviceTokenService->register(
            $request->user(),
            trim((string) $validated['token']),
            (string) $validated['device_type'],
        );

        return $this->success([
            'id' => (int) $deviceToken->id,
            'device_type' => $deviceToken->device_type,
            'is_active' => (bool) $deviceToken->is_active,
        ], 'Token perangkat berhasil disimpan.', 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
        ]);

        $deactivated = $this->deviceTokenService->deactivate(
            $request->user(),
            trim((string) $validated['token']),
        );

        return $this->success([
            'deactivated' => $deactivated,
        ], 'Token perangkat berhasil dinonaktifkan.');
    }
}
