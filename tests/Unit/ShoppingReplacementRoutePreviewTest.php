<?php

namespace Tests\Unit;

use App\Services\Geo\BangDelivServiceAreaService;
use App\Services\Maps\GoogleMapsDistanceMatrixService;
use App\Services\Pricing\DeliveryPricingService;
use App\Services\Shopping\ShoppingDeliveryFeeLockResolver;
use App\Services\Shopping\ShoppingRouteService;
use App\Support\GeoDistance;
use Tests\TestCase;

/**
 * Preview ongkir saat ganti toko/resto Nitip HARUS memakai rute yang
 * dioptimasi, sama seperti rute nyata (applyRouteToOrder). Sebelumnya preview
 * memakai calculateForPoints() dengan kandidat pengganti ditaruh di urutan
 * TERAKHIR tanpa optimasi, sehingga bila pengganti lebih dekat customer
 * daripada merchant tersisa, rutenya bolak-balik dan over-estimate. Ongkir
 * naif itu lalu dikunci dan menimpa rute optimal -> customer ditagih ~2x.
 *
 * Kasus lapangan BD-230726-007: pengganti (Panarama) 6 km ke utara, merchant
 * tersisa (Warung Soto) dekat customer. Naif = Rp30.000+, optimal = Rp17.000.
 */
class ShoppingReplacementRoutePreviewTest extends TestCase
{
    /** Panarama ~6 km utara klaster; Warung Soto dekat customer. */
    private const PANARAMA = ['label' => 'Panarama Resto', 'latitude' => -7.272, 'longitude' => 110.468];

    private const WARUNG_SOTO = ['label' => 'Warung Soto Mami Yolla', 'latitude' => -7.318, 'longitude' => 110.462];

    private const CUSTOMER = ['label' => 'Titik Antar', 'latitude' => -7.32006027, 'longitude' => 110.47065206];

    public function test_naive_order_overestimates_when_replacement_is_closer_to_customer(): void
    {
        // Urutan naif yang dipakai routePreview lama: merchant tersisa dulu,
        // kandidat pengganti di akhir -> Warung Soto -> Panarama -> customer.
        $naive = $this->routeService()->calculateForPoints(
            [self::WARUNG_SOTO, self::PANARAMA],
            self::CUSTOMER,
        );

        $this->assertSame(30000.0, round((float) $naive['delivery_fee'], 2), 'rute bolak-balik ~10,5 km');
    }

    public function test_optimized_preview_picks_the_shorter_ordering(): void
    {
        // Titik yang SAMA, tetapi dioptimasi -> Panarama -> Warung Soto ->
        // customer. Inilah yang kini dipakai routePreview.
        $optimized = $this->routeService()->calculateOptimizedForPoints(
            [self::WARUNG_SOTO, self::PANARAMA],
            self::CUSTOMER,
        );

        $this->assertSame(17000.0, round((float) $optimized['delivery_fee'], 2), 'rute searah ~6,14 km');
    }

    public function test_optimized_is_never_more_expensive_than_naive_for_the_same_points(): void
    {
        $service = $this->routeService();
        $points = [self::WARUNG_SOTO, self::PANARAMA];

        $naive = $service->calculateForPoints($points, self::CUSTOMER);
        $optimized = $service->calculateOptimizedForPoints($points, self::CUSTOMER);

        $this->assertLessThan(
            (float) $naive['delivery_fee'],
            (float) $optimized['delivery_fee'],
            'preview optimal tidak boleh lebih mahal dari urutan naif'
        );
        $this->assertLessThanOrEqual(
            (int) $naive['distance_meters'],
            (int) $optimized['distance_meters'],
        );
    }

    public function test_single_merchant_preview_is_unchanged(): void
    {
        // Dengan satu merchant, tidak ada urutan untuk dioptimasi: preview
        // optimal harus identik dengan perhitungan langsung.
        $service = $this->routeService();

        $optimized = $service->calculateOptimizedForPoints([self::PANARAMA], self::CUSTOMER);
        $direct = $service->calculateForPoints([self::PANARAMA], self::CUSTOMER);

        $this->assertSame(
            round((float) $direct['delivery_fee'], 2),
            round((float) $optimized['delivery_fee'], 2),
        );
    }

    private function routeService(): ShoppingRouteService
    {
        return new ShoppingRouteService(
            new HaversineOptimizingMaps,
            new DeliveryPricingService,
            new ShoppingDeliveryFeeLockResolver,
            new BangDelivServiceAreaService,
        );
    }
}

/**
 * Maps palsu: jarak = haversine. resolveRoute untuk segmen berurutan
 * (dipakai calculateForPoints naif); resolveOptimizedShoppingRoute mencoba
 * setiap urutan pickup dan memilih total jarak terpendek (meniru optimizer
 * nyata tanpa memanggil Google).
 */
class HaversineOptimizingMaps extends GoogleMapsDistanceMatrixService
{
    public function resolveRoute(float $originLat, float $originLng, float $destinationLat, float $destinationLng): array
    {
        $meters = (int) round(GeoDistance::meters($originLat, $originLng, $destinationLat, $destinationLng));

        return $this->routePayload($meters);
    }

    public function resolveOptimizedShoppingRoute(array $pickupPoints, array $dropoffPoint, int $maxOriginCandidates): array
    {
        $best = null;
        $bestOrder = [];
        foreach ($this->permutations($pickupPoints) as $order) {
            $sequence = array_merge($order, [$dropoffPoint]);
            $total = 0;
            for ($i = 0; $i < count($sequence) - 1; $i++) {
                $total += GeoDistance::meters(
                    (float) $sequence[$i]['latitude'], (float) $sequence[$i]['longitude'],
                    (float) $sequence[$i + 1]['latitude'], (float) $sequence[$i + 1]['longitude'],
                );
            }
            if ($best === null || $total < $best) {
                $best = $total;
                $bestOrder = $order;
            }
        }

        return $this->routePayload((int) round((float) $best)) + [
            'ordered_pickup_location_ids' => array_values(array_filter(array_map(
                static fn (array $p): ?int => isset($p['id']) ? (int) $p['id'] : null,
                $bestOrder,
            ))),
            'segments' => [],
            'first_stop_label' => $bestOrder[0]['label'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function permutations(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }
        $result = [];
        foreach ($items as $index => $item) {
            $rest = $items;
            unset($rest[$index]);
            foreach ($this->permutations(array_values($rest)) as $permutation) {
                $result[] = array_merge([$item], $permutation);
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function routePayload(int $meters): array
    {
        return [
            'distance_meters' => $meters,
            'distance_km' => round($meters / 1000, 2),
            'distance_text' => number_format($meters / 1000, 2, ',', '.').' km',
            'duration_seconds' => $meters, // proksi durasi = jarak, cukup untuk tiebreak
            'duration_text' => '',
            'route_provider' => 'routes_api',
            'route_status' => 'OK',
            'encoded_polyline' => null,
        ];
    }
}
