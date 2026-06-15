<?php

namespace App\Services;

use App\Enums\ProofType;
use App\Enums\ServiceTypeCode;
use App\Exceptions\ApiException;

class OrderProofPolicyService
{
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
