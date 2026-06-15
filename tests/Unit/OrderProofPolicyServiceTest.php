<?php

namespace Tests\Unit;

use App\Enums\ProofType;
use App\Enums\ServiceTypeCode;
use App\Exceptions\ApiException;
use App\Services\Order\OrderProofPolicyService;
use PHPUnit\Framework\TestCase;

class OrderProofPolicyServiceTest extends TestCase
{
    public function test_it_allows_only_service_specific_lifecycle_proofs(): void
    {
        $policy = new OrderProofPolicyService;

        $this->assertTrue($policy->supportsDriverProofType(ServiceTypeCode::Courier->value, ProofType::Pickup->value));
        $this->assertTrue($policy->supportsDriverProofType(ServiceTypeCode::Courier->value, ProofType::Delivery->value));
        $this->assertFalse($policy->supportsDriverProofType(ServiceTypeCode::Courier->value, ProofType::Receipt->value));

        $this->assertTrue($policy->supportsDriverProofType(ServiceTypeCode::Shopping->value, ProofType::Receipt->value));
        $this->assertTrue($policy->supportsDriverProofType(ServiceTypeCode::Shopping->value, ProofType::StoreClosed->value));
        $this->assertFalse($policy->supportsDriverProofType(ServiceTypeCode::Shopping->value, ProofType::Pickup->value));

        $this->assertFalse($policy->supportsDriverProofType(ServiceTypeCode::Ride->value, ProofType::Pickup->value));
    }

    public function test_it_normalizes_and_maps_proof_types(): void
    {
        $policy = new OrderProofPolicyService;

        $this->assertSame(ProofType::PaymentTransfer->value, $policy->normalizeProofType('payment-transfer'));
        $this->assertSame('SHOPPING_RECEIPT', $policy->evidenceTypeForProof(ProofType::Receipt->value));
        $this->assertSame(
            ['DELIVERY_PHOTO', 'COURIER_DELIVERY_PHOTO', 'COURIER_RECEIVER_PHOTO'],
            $policy->evidenceTypesForProof(ProofType::Delivery->value),
        );
    }

    public function test_it_rejects_unknown_proof_type(): void
    {
        $policy = new OrderProofPolicyService;

        $this->expectException(ApiException::class);

        $policy->normalizeProofType('unknown-proof');
    }
}
