<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdminReviewDriverDocumentsRequest;
use App\Services\Admin\AdminPagination;
use App\Services\Driver\DriverVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverVerificationController extends Controller
{
    public function __construct(private readonly DriverVerificationService $service) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $statusFilter = strtolower(trim((string) $request->query('status', 'all')));
        $statusFilter = in_array($statusFilter, ['all', 'pending', 'needs_revision', 'rejected'], true)
            ? $statusFilter
            : 'all';

        $verificationDrivers = $this->service->adminQueue([
            'search' => $search,
            'queue_filter' => $statusFilter,
            'page' => $request->query('page', 1),
            'per_page' => AdminPagination::PER_PAGE,
        ]);

        $summaryCounts = $this->service->queueSummaryCounts();

        return view('admin.drivers.verification.index', [
            'verificationDrivers' => $verificationDrivers,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'pendingCount' => $summaryCounts['pending'] ?? 0,
            'needsRevisionCount' => $summaryCounts['needs_revision'] ?? 0,
            'rejectedCount' => $summaryCounts['rejected'] ?? 0,
        ]);
    }

    public function show(int $driverId): View
    {
        try {
            $detail = $this->service->adminShow($driverId);

            return view('admin.drivers.verification.show', [
                'detail' => $detail,
            ]);
        } catch (ApiException $exception) {
            abort(404, $exception->getMessage());
        }
    }

    public function review(AdminReviewDriverDocumentsRequest $request, int $driverId): RedirectResponse
    {
        try {
            $this->service->reviewDocuments($request->user(), $driverId, $request->validated());

            return redirect()
                ->route('admin.verification.show', ['driverId' => $driverId])
                ->with('success', 'Keputusan verifikasi berhasil disimpan.');
        } catch (ApiException $exception) {
            return redirect()
                ->route('admin.verification.show', ['driverId' => $driverId])
                ->with('error', $exception->getMessage())
                ->withInput();
        }
    }

    public function deleteDocument(Request $request, int $driverId, string $documentType): RedirectResponse
    {
        try {
            $this->service->deleteDocument($request->user(), $driverId, $documentType);

            return redirect()
                ->route('admin.verification.show', ['driverId' => $driverId])
                ->with('success', 'File dokumen berhasil dihapus. Data verifikasi tetap tersimpan.');
        } catch (ApiException $exception) {
            return redirect()
                ->route('admin.verification.show', ['driverId' => $driverId])
                ->with('error', $exception->getMessage());
        }
    }

    public function previewDocument(
        Request $request,
        int $driverId,
        string $documentType
    ): StreamedResponse {
        try {
            return $this->service->adminPreviewDocument(
                $request->user(),
                $driverId,
                $documentType
            );
        } catch (ApiException $exception) {
            abort($exception->status(), $exception->getMessage());
        }
    }
}
