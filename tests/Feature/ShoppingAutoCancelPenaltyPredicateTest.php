<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderLocation;
use App\Models\OrderLog;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingFailedTripCompensationService;
use App\Services\Shopping\ShoppingReplacementProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mengunci Persamaan (6) versi baru:
 *
 *   0                          jika g < 3
 *   0,5 x O(D)                 jika g >= 3 dan pesanan LANJUT
 *   max(0,5xBp , 0,5xO(D))     jika g >= 3 dan pesanan BATAL
 *
 * dengan g = jumlah kegagalan pickup, D = total jarak perjalanan gagal
 * terverifikasi, Bp = ongkir terdaftar.
 *
 * Regresi yang dijaga: sebelumnya kompensasi perjalanan gagal C(o) mendahului
 * penalti, sehingga pembatalan 3 resto berdekatan hanya menagih Rp2.500
 * walau ongkir terdaftarnya Rp32.500.
 */
class ShoppingAutoCancelPenaltyPredicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_fee_below_three_failures(): void
    {
        $order = $this->orderWithFailedTrips([1200, 800], deliveryFee: 32500);

        $this->assertSame(2, app(ShoppingPricingService::class)->failedAttemptCount($order));
        $this->assertFalse(app(ShoppingPricingService::class)->isCancellationPenaltyEligible($order));
        $this->assertSame(0.0, app(ShoppingPricingService::class)->calculateCancellationPenalty($order));
        $this->assertSame(0.0, app(ShoppingFailedTripCompensationService::class)->amount($order));
    }

    public function test_cancelled_order_uses_registered_fee_base_when_failed_route_is_short(): void
    {
        // 3 kunjungan gagal berdekatan: 120 + 542 + 818 = 1.480 m -> K(d)=0 -> O=5.000
        $order = $this->orderWithFailedTrips([120, 542, 818], deliveryFee: 32500);

        $penalty = app(ShoppingPricingService::class)->calculateCancellationPenalty($order);
        $compensation = app(ShoppingFailedTripCompensationService::class)->amount($order);

        $this->assertSame(3, app(ShoppingPricingService::class)->failedAttemptCount($order));
        $this->assertSame(16250.0, $penalty, '0,5 x ongkir terdaftar');
        $this->assertSame(2500.0, $compensation, '0,5 x O(1.480 m) = 0,5 x 5.000');

        // Persamaan (5): mode penaltyOnly memakai yang terbesar.
        $pricing = app(ShoppingPricingService::class)->calculateForItems(
            (int) $order->service_type_id,
            [],
            0.0,
            cancellationPenalty: $penalty,
            penaltyOnly: true,
            failedTripCompensation: $compensation,
        );

        $this->assertSame(16250.0, $pricing['total_price']);
    }

    public function test_cancelled_order_uses_failed_route_when_it_exceeds_half_of_registered_fee(): void
    {
        // Total 15 km -> O = 5.000 + 15 x 2.500 = 42.500 -> kompensasi 21.250
        $order = $this->orderWithFailedTrips([5000, 5000, 5000], deliveryFee: 32500);

        $penalty = app(ShoppingPricingService::class)->calculateCancellationPenalty($order);
        $compensation = app(ShoppingFailedTripCompensationService::class)->amount($order);

        $this->assertSame(16250.0, $penalty);
        $this->assertSame(21250.0, $compensation);

        $pricing = app(ShoppingPricingService::class)->calculateForItems(
            (int) $order->service_type_id,
            [],
            0.0,
            cancellationPenalty: $penalty,
            penaltyOnly: true,
            failedTripCompensation: $compensation,
        );

        $this->assertSame(21250.0, $pricing['total_price'], 'perjalanan gagal lebih besar dari separuh ongkir');
    }

    public function test_penalty_no_longer_collapses_to_the_failed_route_amount(): void
    {
        // Regresi utama: dulu C(o) mendahului sehingga hasilnya Rp2.500.
        $order = $this->orderWithFailedTrips([120, 542, 818], deliveryFee: 32500);

        $compensation = app(ShoppingFailedTripCompensationService::class)->amount($order);
        $penalty = app(ShoppingPricingService::class)->calculateCancellationPenalty($order);

        $this->assertSame(2500.0, $compensation);
        $this->assertNotSame($compensation, $penalty, 'penalti tidak boleh ikut nilai kompensasi');
        $this->assertSame(16250.0, $penalty);
    }

    /**
     * @param  array<int, int>  $failedDistances  jarak tiap perjalanan gagal (meter)
     */
    private function orderWithFailedTrips(array $failedDistances, float $deliveryFee, bool $verified = true): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $order = Order::query()->create([
            'order_number' => 'BD-P6-'.strtoupper(substr(md5((string) random_int(1, 999999)), 0, 8)),
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => (int) ServiceType::query()->where('code', 'SHOPPING')->value('id'),
            'driver_id' => null,
            'address_id' => null,
            'delivery_address' => 'Jl. Persamaan Enam No. 6',
            'delivery_latitude' => -7.001234,
            'delivery_longitude' => 110.401234,
            'subtotal' => 0,
            'delivery_fee' => $deliveryFee,
            'service_fee' => 0,
            'total_amount' => $deliveryFee,
            'total_price' => $deliveryFee,
            'status_id' => (int) OrderStatus::query()->where('code', 'ARRIVED_MERCHANT')->value('id'),
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);

        foreach ($failedDistances as $index => $distanceMeters) {
            $pickup = OrderLocation::query()->create([
                'order_id' => $order->id,
                'restaurant_id' => null,
                'location_role' => 'PICKUP',
                'label' => 'Tempat Gagal '.($index + 1),
                'full_address' => 'Jl. Tempat Gagal '.($index + 1),
                'latitude' => -7.001 - ($index / 1000),
                'longitude' => 110.401 + ($index / 1000),
                'sequence_no' => $index + 1,
                'fulfillment_status' => 'FAILED',
                'failed_attempt_count' => 1,
            ]);

            OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => ShoppingReplacementProjectionService::FAILED_TRIP_EVENT,
                'trigger_type' => 'SHOPPING_FAILED_TRIP_RECORDED',
                'note' => 'Tempat tutup saat driver tiba.',
                'metadata' => [
                    'pickup_location_id' => $pickup->id,
                    'chain_id' => 'pickup:'.$pickup->id,
                    'distance_meters' => $distanceMeters,
                    'verified_for_compensation' => $verified,
                ],
            ]);
        }

        return $order->refresh();
    }
}
