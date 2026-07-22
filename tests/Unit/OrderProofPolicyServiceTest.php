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

    public function test_courier_pickup_proof_is_locked_before_driver_arrives_at_pickup(): void
    {
        $policy = new OrderProofPolicyService;
        $courier = ServiceTypeCode::Courier->value;
        $pickup = ProofType::Pickup->value;

        $this->assertFalse($policy->isDriverProofAllowedForStatus($courier, $pickup, 'PENDING'));
        $this->assertFalse($policy->isDriverProofAllowedForStatus($courier, $pickup, 'DRIVER_ASSIGNED'));

        $this->assertTrue($policy->isDriverProofAllowedForStatus($courier, $pickup, 'ARRIVED_PICKUP'));
        $this->assertTrue($policy->isDriverProofAllowedForStatus($courier, $pickup, 'PICKED_UP'));
        $this->assertTrue($policy->isDriverProofAllowedForStatus($courier, $pickup, 'ON_THE_WAY'));
        $this->assertTrue($policy->isDriverProofAllowedForStatus($courier, $pickup, 'DELIVERED'));
    }

    public function test_courier_delivery_proof_is_locked_before_driver_arrives_at_dropoff(): void
    {
        $policy = new OrderProofPolicyService;
        $courier = ServiceTypeCode::Courier->value;
        $delivery = ProofType::Delivery->value;

        $this->assertFalse($policy->isDriverProofAllowedForStatus($courier, $delivery, 'DRIVER_ASSIGNED'));
        $this->assertFalse($policy->isDriverProofAllowedForStatus($courier, $delivery, 'ARRIVED_PICKUP'));
        $this->assertFalse($policy->isDriverProofAllowedForStatus($courier, $delivery, 'ON_THE_WAY'));

        $this->assertTrue($policy->isDriverProofAllowedForStatus($courier, $delivery, 'ARRIVED_DROPOFF'));
        $this->assertTrue($policy->isDriverProofAllowedForStatus($courier, $delivery, 'DELIVERED'));
        $this->assertTrue($policy->isDriverProofAllowedForStatus($courier, $delivery, 'COMPLETED'));
    }

    public function test_non_courier_proofs_keep_unrestricted_status(): void
    {
        $policy = new OrderProofPolicyService;

        $this->assertSame([], $policy->allowedStatusesForDriverProof(
            ServiceTypeCode::Shopping->value,
            ProofType::Receipt->value,
        ));
        $this->assertTrue($policy->isDriverProofAllowedForStatus(
            ServiceTypeCode::Shopping->value,
            ProofType::Receipt->value,
            'DRIVER_ASSIGNED',
        ));
    }

    public function test_locked_reason_mentions_the_required_driver_action(): void
    {
        $policy = new OrderProofPolicyService;

        $this->assertStringContainsString(
            'Tiba di Titik Pickup',
            $policy->proofNotAllowedYetMessage(ProofType::Pickup->value),
        );
        $this->assertStringContainsString(
            'Tiba di Tujuan',
            $policy->proofNotAllowedYetMessage(ProofType::Delivery->value),
        );
    }
}
