<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class OrderTransferEvidenceService
{
    public function storeAndRecord(
        Order $order,
        UploadedFile $photo,
        ?int $driverId = null,
        ?string $note = null,
    ): OrderEvidence {
        $path = $photo->store('orders/'.$order->id.'/payments', 'public');
        if (! is_string($path) || $path === '') {
            throw new ApiException('Upload bukti QRIS gagal disimpan.', 500);
        }

        return $this->recordFromUrl(
            $order,
            Storage::disk('public')->url($path),
            $driverId,
            $note
        );
    }

    public function recordFromUrl(
        Order $order,
        string $fileUrl,
        ?int $driverId = null,
        ?string $note = null,
    ): OrderEvidence {
        return OrderEvidence::query()->create([
            'order_id' => $order->id,
            'driver_id' => $driverId,
            'evidence_type' => 'PAYMENT_TRANSFER_PHOTO',
            'file_url' => $fileUrl,
            'uploaded_at' => now(),
            'notes' => $note !== null && trim($note) !== ''
                ? trim($note)
                : 'Bukti QRIS menunggu verifikasi.',
        ]);
    }
}
