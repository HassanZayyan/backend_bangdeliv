<?php

namespace Tests\Feature\Formula;

use App\Models\Order;
use App\Models\OrderLocation;
use App\Models\OrderLog;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Geo\BangDelivServiceAreaService;
use App\Services\Maps\GoogleMapsDistanceMatrixService;
use App\Services\Order\DeliveryFeeNegotiationService;
use App\Services\Pricing\DeliveryPricingService;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingDeliveryFeeLockResolver;
use App\Services\Shopping\ShoppingReplacementProjectionService;
use App\Services\Shopping\ShoppingRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saat semua merchant Nitip gugur, tidak ada rute pengantaran, sehingga O(x)
 * pada Persamaan (1) tak terdefinisi. Ongkir carry-over yang sebelumnya tetap
 * dipertahankan (mis. Rp30.000) membuat customer melihat tagihan transit yang
 * tak akan pernah tertagih -- lalu terjun begitu order dibatalkan.
 *
 * Aturannya sekarang: bila tak ada merchant aktif, ongkir dinolkan kecuali
 * memang dikunci manual driver. Basis penalti tidak terpengaruh karena
 * tersimpan terpisah di event penalty_base_delivery_fee.
 */
class ShoppingEmptyRouteFeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_merchants_failed_zeroes_the_delivery_fee(): void
    {
        $order = $this->shoppingOrder(deliveryFee: 30000);
        $this->pickup($order, 'Cumi Mbledoss Kesongo', 'FAILED');
        $this->pickup($order, 'Baloeng Gajah', 'FAILED');
        $this->pickup($order, 'Gecok JOGO ROSO TLOGO', 'FAILED');
        $this->dropoff($order);

        // Basis penalti terekam sebelum ongkir dinolkan, seperti di lapangan.
        $this->recordPenaltyBaseEvent($order, 30000);

        $result = $this->routeService()->applyRouteToOrder($order, 30000.0, 'FAILED_ATTEMPT_PICKUP');

        $this->assertNull($result);
        $this->assertSame(0.0, round((float) $order->refresh()->delivery_fee, 2));
        $this->assertSame('NO_ACTIVE_PICKUPS', data_get($order->route_snapshot, 'route_status'));
    }

    public function test_fee_survives_the_zeroed_delivery_fee(): void
    {
        $order = $this->shoppingOrder(deliveryFee: 30000);
        $this->dropoff($order);
        // Tiga toko gagal terverifikasi; jarak rute customer->toko 1.200/2.000/3.000.
        foreach ([1200, 2000, 3000] as $index => $meters) {
            $pickup = $this->pickup($order, 'Toko '.($index + 1), 'FAILED');
            $this->failedTripEvent($order, $pickup, $meters);
        }
        // Basis ongkir committed dibekukan saat toko-toko gagal (= ongkir rute
        // penuh 30.000 sebelum semua gugur).
        $this->recordPenaltyBaseEvent($order, 30000);

        $this->routeService()->applyRouteToOrder($order, 30000.0, 'FAILED_ATTEMPT_PICKUP');
        $order->update(['status_id' => $this->statusId('CANCELLED_WITH_FEE')]);

        // Ongkir kolom dinolkan, tetapi fee pembatalan tetap = 0,5 x ongkir rute
        // committed penuh yang dibekukan: 0,5 x 30.000 = 15.000.
        $penalty = app(ShoppingPricingService::class)->calculateCancellationPenalty($order->refresh());
        $this->assertSame(15000.0, $penalty);
    }

    private function failedTripEvent(Order $order, OrderLocation $pickup, int $routeMeters): void
    {
        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => ShoppingReplacementProjectionService::FAILED_TRIP_EVENT,
            'trigger_type' => 'SHOPPING_FAILED_TRIP_RECORDED',
            'note' => 'Toko tutup.',
            'metadata' => [
                'pickup_location_id' => $pickup->id,
                'customer_route_distance_meters' => $routeMeters,
                'verified_for_compensation' => true,
            ],
        ]);
    }

    public function test_route_is_not_zeroed_while_a_completed_merchant_still_has_items(): void
    {
        // Skenario A tutup, B tutup, C buka & dibelanjai, D tutup.
        $order = $this->shoppingOrder(deliveryFee: 30000);
        $this->pickup($order, 'Merchant A', 'FAILED');
        $this->pickup($order, 'Merchant B', 'FAILED');
        $merchantC = $this->pickup($order, 'Merchant C', 'COMPLETED');
        $this->pickup($order, 'Merchant D', 'FAILED');
        $this->dropoff($order);
        // C punya item tersedia -> tetap terhitung aktif, harus diantar.
        $this->availableItem($order, $merchantC);

        $result = $this->routeService()->applyRouteToOrder($order, 30000.0, 'FAILED_ATTEMPT_PICKUP');

        $this->assertNotNull($result, 'masih ada merchant aktif -> rute dihitung');
        $this->assertNotSame('NO_ACTIVE_PICKUPS', data_get($order->refresh()->route_snapshot, 'route_status'));
        $this->assertGreaterThan(0.0, round((float) $order->delivery_fee, 2));
    }

    public function test_manual_driver_locked_fee_is_kept_even_when_no_merchant_is_active(): void
    {
        $order = $this->shoppingOrder(deliveryFee: 18000);
        foreach (['Cumi', 'Baloeng', 'Gecok'] as $name) {
            $this->pickup($order, $name, 'FAILED');
        }
        $this->dropoff($order);
        // Driver sudah mengunci ongkir manual Rp18.000 -> harus dipertahankan.
        $this->lockManualDeliveryFee($order, 18000);

        $this->routeService()->applyRouteToOrder($order, null, null);

        // Ongkir manual driver dipertahankan, tidak ikut dinolkan seperti
        // nilai carry-over biasa.
        $this->assertSame(18000.0, round((float) $order->refresh()->delivery_fee, 2));
    }

    public function test_committed_route_includes_verified_failed_merchant(): void
    {
        // Formula terpadu (Fase 1): toko yang GAGAL tapi benar-benar didatangi
        // driver (kegagalan terverifikasi) tetap dihitung di rute ongkir --
        // tidak lagi dikeluarkan sehingga ongkir menciut. Fake rute = 1 km per
        // titik committed, jadi 2 titik -> 2 km -> Rp9.000; kalau toko gagal
        // diabaikan (perilaku lama) hanya 1 titik -> Rp5.000.
        $order = $this->shoppingOrder(deliveryFee: 30000);
        $near = $this->pickup($order, 'Toko Dekat', 'PRICE_APPROVED');
        $farFailed = $this->pickup($order, 'Toko Jauh Tutup', 'FAILED');
        $this->dropoff($order);
        $this->availableItem($order, $near);
        $this->failedTripEvent($order, $farFailed, 6000);

        $service = new ShoppingRouteService(
            new CountingShoppingRoute,
            new DeliveryPricingService,
            new ShoppingDeliveryFeeLockResolver,
            new BangDelivServiceAreaService,
        );
        $service->applyRouteToOrder($order);

        $this->assertSame(9000.0, round((float) $order->refresh()->delivery_fee, 2));
    }

    public function test_unverified_failed_merchant_is_excluded_from_committed_route(): void
    {
        // Anti-gaming: toko gagal yang TIDAK terverifikasi didatangi (mis.
        // dilaporkan tutup dari jauh) tidak dihitung -> hanya 1 titik committed
        // (toko dekat) -> Rp5.000.
        $order = $this->shoppingOrder(deliveryFee: 30000);
        $near = $this->pickup($order, 'Toko Dekat', 'PRICE_APPROVED');
        $this->pickup($order, 'Toko Jauh Klaim Tutup', 'FAILED'); // tanpa event verified
        $this->dropoff($order);
        $this->availableItem($order, $near);

        $service = new ShoppingRouteService(
            new CountingShoppingRoute,
            new DeliveryPricingService,
            new ShoppingDeliveryFeeLockResolver,
            new BangDelivServiceAreaService,
        );
        $service->applyRouteToOrder($order);

        $this->assertSame(5000.0, round((float) $order->refresh()->delivery_fee, 2));
    }

    public function test_replacing_a_merchant_recomputes_the_route_instead_of_zeroing(): void
    {
        $order = $this->shoppingOrder(deliveryFee: 30000);
        $this->pickup($order, 'Cumi Mbledoss Kesongo', 'REPLACED');
        $replacement = $this->pickup($order, 'Bakmi Remaja 3', 'PENDING');
        $this->dropoff($order);
        $this->availableItem($order, $replacement);

        $result = $this->routeService()->applyRouteToOrder($order, 30000.0, 'MERCHANT_REPLACEMENT');

        $this->assertNotNull($result);
        $this->assertNotSame('NO_ACTIVE_PICKUPS', data_get($order->refresh()->route_snapshot, 'route_status'));
        $this->assertGreaterThan(0.0, round((float) $order->delivery_fee, 2));
    }

    private function routeService(): ShoppingRouteService
    {
        return new ShoppingRouteService(
            new FixedShoppingRoute,
            new DeliveryPricingService,
            new ShoppingDeliveryFeeLockResolver,
            new BangDelivServiceAreaService,
        );
    }

    private function shoppingOrder(float $deliveryFee): Order
    {
        return Order::query()->create([
            'order_number' => 'BD-EMPTY-'.strtoupper(substr(md5((string) random_int(1, 999999)), 0, 8)),
            'user_id' => User::factory()->create(['role' => 'customer'])->id,
            'service_type_id' => (int) ServiceType::query()->where('code', 'SHOPPING')->value('id'),
            'delivery_address' => 'rt 1 rw 3, Bejalen, Kec. Ambarawa',
            'delivery_latitude' => -7.32006027,
            'delivery_longitude' => 110.47065206,
            'subtotal' => 0,
            'delivery_fee' => $deliveryFee,
            'service_fee' => 0,
            'total_amount' => $deliveryFee,
            'total_price' => $deliveryFee,
            'status_id' => $this->statusId('ARRIVED_MERCHANT'),
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);
    }

    private function pickup(Order $order, string $label, string $fulfillmentStatus): OrderLocation
    {
        static $sequence = 0;
        $sequence++;

        return OrderLocation::query()->create([
            'order_id' => $order->id,
            'location_role' => 'PICKUP',
            'label' => $label,
            'full_address' => $label,
            // Titik-titik nyata di Tuntang, aman di dalam area layanan.
            'latitude' => -7.290 - ($sequence / 500),
            'longitude' => 110.465 + ($sequence / 500),
            'sequence_no' => $sequence,
            'fulfillment_status' => $fulfillmentStatus,
            // Tiap tempat yang gugur menyumbang satu ke ambang penalti.
            'failed_attempt_count' => $fulfillmentStatus === 'FAILED' ? 1 : 0,
        ]);
    }

    private function dropoff(Order $order): void
    {
        OrderLocation::query()->create([
            'order_id' => $order->id,
            'location_role' => 'DROPOFF',
            'label' => 'Titik Antar',
            'full_address' => 'rt 1 rw 3, Bejalen',
            'latitude' => -7.32006027,
            'longitude' => 110.47065206,
            'sequence_no' => 99,
            'fulfillment_status' => 'PENDING',
        ]);
    }

    private function availableItem(Order $order, OrderLocation $pickup): void
    {
        $order->items()->create([
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Item '.$pickup->label,
            'quantity' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
            'is_available' => true,
        ]);
        $order->load('items');
    }

    private function recordPenaltyBaseEvent(Order $order, float $base): void
    {
        OrderLog::query()->create([
            'order_id' => $order->id,
            'log_type' => 'SYSTEM_EVENT',
            'trigger_type' => 'FAILED_ATTEMPT_PICKUP',
            'note' => 'Tempat tutup.',
            'metadata' => ['penalty_base_delivery_fee' => $base],
        ]);
    }

    private function lockManualDeliveryFee(Order $order, float $amount): void
    {
        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => DeliveryFeeNegotiationService::EVENT_TYPE,
            'trigger_type' => DeliveryFeeNegotiationService::DRIVER_FEE_APPROVED_BY_DRIVER_BYPASS,
            'note' => 'Driver mengunci ongkir manual.',
            'metadata' => [
                'approved_amount' => $amount,
                'pricing_scope' => DeliveryFeeNegotiationService::PRICING_SCOPE_SHOPPING_TOTAL_TRANSPORT,
            ],
        ]);
    }

    private function statusId(string $code): int
    {
        return (int) OrderStatus::query()->where('code', $code)->value('id');
    }
}

/**
 * Rute tetap untuk merchant aktif, tanpa memanggil Google.
 */
class FixedShoppingRoute extends GoogleMapsDistanceMatrixService
{
    public function resolveRoute(float $originLat, float $originLng, float $destinationLat, float $destinationLng): array
    {
        return [
            'distance_meters' => 4200,
            'distance_km' => 4.2,
            'distance_text' => '4,2 km',
            'duration_seconds' => 600,
            'duration_text' => '10 menit',
            'route_provider' => 'routes_api',
            'route_status' => 'OK',
        ];
    }

    public function resolveOptimizedShoppingRoute(array $pickupPoints, array $dropoffPoint, int $maxOriginCandidates): array
    {
        $ids = [];
        foreach ($pickupPoints as $point) {
            if (isset($point['pickup_location_id'])) {
                $ids[] = (int) $point['pickup_location_id'];
            }
        }

        return [
            'distance_meters' => 4200,
            'distance_km' => 4.2,
            'distance_text' => '4,2 km',
            'duration_seconds' => 600,
            'duration_text' => '10 menit',
            'route_provider' => 'routes_api',
            'route_status' => 'OK',
            'ordered_pickup_location_ids' => $ids,
            'segments' => [],
        ];
    }
}

/**
 * Rute yang jaraknya sebanding dengan JUMLAH titik pickup committed (1 km per
 * titik), supaya bisa membedakan committed-set 1 vs 2 toko lewat ongkir.
 */
class CountingShoppingRoute extends GoogleMapsDistanceMatrixService
{
    public function resolveRoute(float $originLat, float $originLng, float $destinationLat, float $destinationLng): array
    {
        return [
            'distance_meters' => 1000,
            'distance_km' => 1.0,
            'distance_text' => '1 km',
            'duration_seconds' => 120,
            'duration_text' => '2 menit',
            'route_provider' => 'routes_api',
            'route_status' => 'OK',
        ];
    }

    public function resolveOptimizedShoppingRoute(array $pickupPoints, array $dropoffPoint, int $maxOriginCandidates): array
    {
        $meters = 1000 * max(1, count($pickupPoints));
        $ids = [];
        foreach ($pickupPoints as $point) {
            if (isset($point['id'])) {
                $ids[] = (int) $point['id'];
            }
        }

        return [
            'distance_meters' => $meters,
            'distance_km' => round($meters / 1000, 2),
            'distance_text' => round($meters / 1000, 2).' km',
            'duration_seconds' => 120 * max(1, count($pickupPoints)),
            'duration_text' => '2 menit',
            'route_provider' => 'routes_api',
            'route_status' => 'OK',
            'ordered_pickup_location_ids' => $ids,
            'segments' => [],
        ];
    }
}
