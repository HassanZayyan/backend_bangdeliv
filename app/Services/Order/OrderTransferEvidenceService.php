<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderEvidence;
use Illuminate\Http\UploadedFile;

class OrderTransferEvidenceService
{
    public function __construct(
        private readonly OrderEvidenceService $orderEvidenceService,
    ) {}

    public function storeAndRecord(
        Order $order,
        UploadedFile $photo,
        int $userId,
        ?string $note = null,
    ): OrderEvidence {
        return $this->recordFromUrl(
            $order,
            $this->orderEvidenceService->storeOrderPhoto(
                $photo,
                (int) $order->id,
                'payments',
                'Upload bukti QRIS gagal disimpan.',
            ),
            $userId,
            $note
        );
    }

    public function recordFromUrl(
        Order $order,
        string $fileUrl,
        int $userId,
        ?string $note = null,
    ): OrderEvidence {
        return OrderEvidence::query()->create([
            'order_id' => $order->id,
            'user_id' => $userId,
            'evidence_type' => 'PAYMENT_TRANSFER_PHOTO',
            'file_url' => $fileUrl,
            'uploaded_at' => now(),
            'notes' => $note !== null && trim($note) !== ''
                ? trim($note)
                : 'Bukti QRIS menunggu verifikasi.',
        ]);
    }
}
