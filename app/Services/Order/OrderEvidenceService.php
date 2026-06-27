<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class OrderEvidenceService
{
    public function __construct(
        private readonly OrderProofPolicyService $proofPolicyService,
    ) {}

    public function storeOrderPhoto(
        UploadedFile $photo,
        int $orderId,
        string $folder,
        string $failureMessage = 'Upload foto gagal disimpan.',
    ): string {
        $path = $photo->store('orders/'.$orderId.'/'.$folder, 'public');
        if (! is_string($path) || $path === '') {
            throw new ApiException($failureMessage, 500);
        }

        return Storage::disk('public')->url($path);
    }

    public function publicDiskPathForEvidence(Order $order, OrderEvidence $evidence): ?string
    {
        $rawUrl = trim((string) $evidence->file_url);
        if ($rawUrl === '') {
            return null;
        }

        $parsedPath = parse_url($rawUrl, PHP_URL_PATH);
        $path = is_string($parsedPath) && trim($parsedPath) !== ''
            ? $parsedPath
            : $rawUrl;

        $path = ltrim(rawurldecode(str_replace('\\', '/', $path)), '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        $path = preg_replace('#/+#', '/', $path);
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return null;
        }

        $allowedPrefix = 'orders/'.(int) $order->id.'/payments/';

        return str_starts_with($path, $allowedPrefix) ? $path : null;
    }

    public function deletePublicEvidenceFile(Order $order, OrderEvidence $evidence): bool
    {
        $path = $this->publicDiskPathForEvidence($order, $evidence);
        if ($path === null) {
            return false;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return true;
        }

        return $disk->delete($path);
    }

    public function publicEvidenceFileExists(Order $order, OrderEvidence $evidence): bool
    {
        $path = $this->publicDiskPathForEvidence($order, $evidence);
        if ($path === null) {
            return false;
        }

        return Storage::disk('public')->exists($path);
    }

    public function recordDriverEvidence(
        Order $order,
        int $userId,
        string $evidenceType,
        string $fileUrl,
        ?string $notes = null,
    ): OrderEvidence {
        return OrderEvidence::query()->create([
            'order_id' => $order->id,
            'user_id' => $userId,
            'evidence_type' => $evidenceType,
            'file_url' => $fileUrl,
            'uploaded_at' => now(),
            'notes' => $notes,
        ]);
    }

    public function storeAndRecordDriverEvidence(
        Order $order,
        UploadedFile $photo,
        int $userId,
        string $evidenceType,
        string $folder,
        ?string $notes = null,
    ): OrderEvidence {
        return $this->recordDriverEvidence(
            $order,
            $userId,
            $evidenceType,
            $this->storeOrderPhoto($photo, (int) $order->id, $folder),
            $notes,
        );
    }

    public function hasProof(Order $order, string $type): bool
    {
        $evidenceTypes = $this->proofPolicyService->evidenceTypesForProof($type);

        if ($evidenceTypes === []) {
            return false;
        }

        if ($order->relationLoaded('evidences')) {
            return $order->evidences->contains(
                fn (OrderEvidence $evidence): bool => in_array(strtoupper((string) $evidence->evidence_type), $evidenceTypes, true)
            );
        }

        return $order->evidences()
            ->whereIn('evidence_type', $evidenceTypes)
            ->exists();
    }
}
