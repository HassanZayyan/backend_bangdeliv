<?php

namespace App\Services\Order;

use App\Enums\OrderStatusCode;
use App\Enums\ProofType;
use App\Enums\ServiceTypeCode;
use App\Exceptions\ApiException;

class OrderProofPolicyService
{
    /**
     * Status order yang mengizinkan driver mengunggah bukti foto.
     *
     * Kurir: bukti pengambilan baru masuk akal setelah driver tiba di titik
     * jemput, dan bukti diterima setelah driver tiba di tujuan. Layanan lain
     * belum dibatasi status (array kosong = tanpa batasan).
     *
     * @return array<int, string>
     */
    public function allowedStatusesForDriverProof(string $serviceCode, string $type): array
    {
        if (ServiceTypeCode::normalize($serviceCode) !== ServiceTypeCode::Courier->value) {
            return [];
        }

        return match ($type) {
            ProofType::Pickup->value => [
                OrderStatusCode::ArrivedPickup->value,
                OrderStatusCode::PickedUp->value,
                OrderStatusCode::OnTheWay->value,
                OrderStatusCode::ArrivedDropoff->value,
                OrderStatusCode::Delivered->value,
                OrderStatusCode::Completed->value,
            ],
            ProofType::Delivery->value => [
                OrderStatusCode::ArrivedDropoff->value,
                OrderStatusCode::Delivered->value,
                OrderStatusCode::Completed->value,
            ],
            default => [],
        };
    }

    public function isDriverProofAllowedForStatus(string $serviceCode, string $type, string $statusCode): bool
    {
        $allowed = $this->allowedStatusesForDriverProof($serviceCode, $type);
        if ($allowed === []) {
            return true;
        }

        return in_array(OrderStatusCode::normalize($statusCode), $allowed, true);
    }

    public function proofNotAllowedYetMessage(string $type): string
    {
        return match ($type) {
            ProofType::Pickup->value => 'Bukti pengambilan bisa diunggah setelah kamu menekan "Tiba di Titik Pickup".',
            ProofType::Delivery->value => 'Bukti diterima bisa diunggah setelah kamu menekan "Tiba di Tujuan".',
            default => 'Bukti foto belum bisa diunggah pada status order saat ini.',
        };
    }

    public function supportsDriverProofType(string $serviceCode, string $type): bool
    {
        return match (ServiceTypeCode::normalize($serviceCode)) {
            ServiceTypeCode::Courier->value => in_array($type, [ProofType::Pickup->value, ProofType::Delivery->value], true),
            ServiceTypeCode::Shopping->value => in_array($type, [ProofType::Receipt->value, ProofType::StoreClosed->value], true),
            default => false,
        };
    }

    public function unsupportedProofMessage(string $type, string $serviceCode): string
    {
        $service = ServiceTypeCode::normalize($serviceCode);

        if ($service === ServiceTypeCode::Shopping->value && in_array($type, [ProofType::Pickup->value, ProofType::Delivery->value], true)) {
            return 'Bukti pengambilan dan diterima hanya tersedia untuk order kurir.';
        }

        if ($service === ServiceTypeCode::Courier->value && in_array($type, [ProofType::Receipt->value, ProofType::StoreClosed->value], true)) {
            return 'Bukti struk dan toko tutup hanya tersedia untuk order Nitip.';
        }

        return 'Bukti foto order tidak tersedia untuk layanan ini.';
    }

    public function isLifecycleProofType(string $type): bool
    {
        return in_array($type, [ProofType::Pickup->value, ProofType::Delivery->value, ProofType::Receipt->value, ProofType::StoreClosed->value], true);
    }

    public function normalizeProofType(string $type): string
    {
        $normalized = strtolower(str_replace('-', '_', trim($type)));
        $allowed = array_map(
            static fn (ProofType $proofType): string => $proofType->value,
            ProofType::cases(),
        );

        if (! in_array($normalized, $allowed, true)) {
            throw new ApiException('Tipe bukti tidak valid.', 422);
        }

        return $normalized;
    }

    public function evidenceTypeForProof(string $type): string
    {
        return match ($type) {
            ProofType::Pickup->value => 'PICKUP_PHOTO',
            ProofType::Delivery->value => 'DELIVERY_PHOTO',
            ProofType::Receipt->value => 'SHOPPING_RECEIPT',
            ProofType::StoreClosed->value => 'STORE_CLOSED_PHOTO',
            ProofType::PaymentTransfer->value => 'PAYMENT_TRANSFER_PHOTO',
            default => throw new ApiException('Tipe bukti tidak valid.', 422),
        };
    }

    /**
     * @return array<int, string>
     */
    public function evidenceTypesForProof(string $type): array
    {
        return match ($type) {
            ProofType::Pickup->value => ['PICKUP_PHOTO'],
            ProofType::Delivery->value => ['DELIVERY_PHOTO', 'COURIER_DELIVERY_PHOTO', 'COURIER_RECEIVER_PHOTO'],
            ProofType::Receipt->value => ['SHOPPING_RECEIPT'],
            ProofType::StoreClosed->value => ['STORE_CLOSED_PHOTO'],
            ProofType::PaymentTransfer->value => ['PAYMENT_TRANSFER_PHOTO'],
            default => [],
        };
    }
}
