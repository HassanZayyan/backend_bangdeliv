<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdminReviewDriverDocumentsRequest;
use App\Http\Requests\Api\SubmitDriverDocumentsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\DriverVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverVerificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DriverVerificationService $service) {}

    public function myStatus(Request $request): JsonResponse
    {
        try {
            $payload = $this->service->myStatus($request->user());

            return $this->success($payload, 'Status verifikasi driver berhasil diambil.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function submitDocuments(SubmitDriverDocumentsRequest $request): JsonResponse
    {
        try {
            $payload = $this->service->submitDocuments($request->user(), $request->validated());

            return $this->success($payload, 'Dokumen berhasil diunggah dan menunggu verifikasi admin.', 201);
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:120'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', 'in:pending,active,rejected,suspended'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $paginator = $this->service->adminQueue($validated);

        return $this->success(
            $paginator->items(),
            'Daftar verifikasi driver berhasil diambil.',
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]
        );
    }

    public function adminShow(int $driverId): JsonResponse
    {
        try {
            $payload = $this->service->adminShow($driverId);

            return $this->success($payload, 'Detail verifikasi driver berhasil diambil.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function adminReview(AdminReviewDriverDocumentsRequest $request, int $driverId): JsonResponse
    {
        try {
            $payload = $this->service->reviewDocuments($request->user(), $driverId, $request->validated());

            return $this->success($payload, 'Review dokumen driver berhasil disimpan.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }
}
