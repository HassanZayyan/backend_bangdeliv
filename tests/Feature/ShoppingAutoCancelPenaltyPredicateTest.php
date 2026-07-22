<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderLocation;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingFailedTripCompensationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mengunci predikat penagihan fee saat semua merchant Nitip gagal.
 *
 * Regresi: pembatalan otomatis dulu hanya melihat kompensasi trip gagal,
 * sehingga order yang sudah 3x gagal (tersebar di beberapa pickup hasil
 * penggantian merchant) batal TANPA fee. Akibatnya tidak ada tagihan yang
 * bisa diselesaikan driver dan order menggantung.
 */
class ShoppingAutoCancelPenaltyPredicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_failures_spread_across_replaced_pickups_are_penalty_eligible_without_trip_compensation(): void
    {
        // Tiap penggantian merchant membuat baris pickup baru, jadi masing-masing
        // hanya gagal sekali. Inilah kondisi nyata "Percobaan 3/3" di aplikasi.
        $order = $this->createShoppingOrderWithFailedPickups([1, 1, 1], deliveryFee: 110000);

        $compensation = app(ShoppingFailedTripCompensationService::class)->summary($order);
        $penalty = app(ShoppingPricingService::class)->calculateCancellationPenalty($order);

        // Predikat lama (dipakai sebelum perbaikan) -> tidak menagih apa pun,
        // karena tidak ada pickup ber-failed_attempt_count > 1 sehingga jalur
        // legacy tidak aktif dan trip terverifikasi belum mencapai ambang.
        $this->assertFalse(
            (bool) $compensation['eligible'],
            'Kompensasi trip gagal seharusnya belum eligible pada kondisi ini.'
        );

        // Predikat baru -> fee 50% tetap tertagih, sama dengan tombol driver
        // "Batalkan Order (Fee 50%)" yang memakai aturan >=3 percobaan gagal.
        $this->assertSame(3, app(ShoppingPricingService::class)->failedAttemptCount($order));
        $this->assertTrue(app(ShoppingPricingService::class)->isCancellationPenaltyEligible($order));
        $this->assertSame(55000.0, $penalty);
    }

    public function test_penalty_is_not_charged_before_reaching_the_attempt_threshold(): void
    {
        $order = $this->createShoppingOrderWithFailedPickups([1, 1], deliveryFee: 110000);

        $this->assertSame(2, app(ShoppingPricingService::class)->failedAttemptCount($order));
        $this->assertFalse(app(ShoppingPricingService::class)->isCancellationPenaltyEligible($order));
        $this->assertSame(0.0, app(ShoppingPricingService::class)->calculateCancellationPenalty($order));
    }

    /**
     * @param  array<int, int>  $failedAttemptCounts
     */
    private function createShoppingOrderWithFailedPickups(array $failedAttemptCounts, float $deliveryFee): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $shoppingTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');
        $statusId = (int) OrderStatus::query()->where('code', 'ARRIVED_MERCHANT')->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-AUTOCANCEL-'.strtoupper(substr(md5((string) random_int(1, 999999)), 0, 8)),
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $shoppingTypeId,
            'driver_id' => null,
            'address_id' => null,
            'delivery_address' => 'Jl. Auto Cancel No. 1',
            'delivery_latitude' => -7.001234,
            'delivery_longitude' => 110.401234,
            'subtotal' => 0,
            'delivery_fee' => $deliveryFee,
            'service_fee' => 0,
            'total_amount' => $deliveryFee,
            'total_price' => $deliveryFee,
            'status_id' => $statusId,
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);

        foreach ($failedAttemptCounts as $index => $failedAttemptCount) {
            OrderLocation::query()->create([
                'order_id' => $order->id,
                'restaurant_id' => null,
                'location_role' => 'PICKUP',
                'label' => 'Tempat Gagal '.($index + 1),
                'full_address' => 'Jl. Tempat Gagal '.($index + 1),
                'latitude' => -7.001 - ($index / 1000),
                'longitude' => 110.401 + ($index / 1000),
                'sequence_no' => $index + 1,
                'fulfillment_status' => 'FAILED',
                'failed_attempt_count' => $failedAttemptCount,
            ]);
        }

        return $order->refresh();
    }
}
