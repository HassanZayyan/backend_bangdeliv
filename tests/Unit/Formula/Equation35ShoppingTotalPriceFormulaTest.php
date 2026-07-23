<?php

namespace Tests\Unit\Formula;

use App\Services\Pricing\ShoppingPricingService;
use Tests\TestCase;

/**
 * Persamaan (5) model revisi: H = round2(S + Oe + F), dengan F = P (fee
 * pembatalan tunggal). Suku max(P, C) dihapus -- tidak ada lagi kompensasi
 * perjalanan gagal sebagai komponen terpisah.
 */
class Equation35ShoppingTotalPriceFormulaTest extends TestCase
{
    public function test_total_is_subtotal_plus_delivery_fee_plus_cancellation_fee(): void
    {
        $pricing = app(ShoppingPricingService::class)->calculateForItems(
            1,
            [
                ['quantity' => 2, 'unit_price' => 10000, 'is_available' => true],
                ['quantity' => 1, 'unit_price' => 5000, 'is_available' => true],
                ['quantity' => 1, 'unit_price' => 9000, 'is_available' => false],
            ],
            13000,
            cancellationPenalty: 1500,
        );

        $this->assertSame(25000.0, $pricing['subtotal']);
        $this->assertSame(13000.0, $pricing['delivery_fee']);
        $this->assertSame(1500.0, $pricing['service_fee'], 'F = P');
        $this->assertSame(39500.0, $pricing['total_price']);
    }

    public function test_penalty_only_zeroes_subtotal_and_delivery_fee_and_charges_p(): void
    {
        $pricing = app(ShoppingPricingService::class)->calculateForItems(
            1,
            [
                ['quantity' => 2, 'unit_price' => 10000, 'is_available' => true],
            ],
            7000,
            cancellationPenalty: 3000,
            penaltyOnly: true,
        );

        $this->assertSame(0.0, $pricing['subtotal']);
        $this->assertSame(0.0, $pricing['delivery_fee']);
        $this->assertSame(3000.0, $pricing['service_fee'], 'F = P, bukan max(P, C)');
        $this->assertSame(3000.0, $pricing['total_price']);
    }
}
