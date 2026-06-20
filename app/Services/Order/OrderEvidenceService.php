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
