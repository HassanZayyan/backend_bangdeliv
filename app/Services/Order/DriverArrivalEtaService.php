<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Services\Maps\GoogleMapsDistanceMatrixService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DriverArrivalEtaService
{
    public function __construct(
        private readonly GoogleMapsDistanceMatrixService $distanceMatrixService,
        private readonly OrderEtaTargetResolver $targetResolver
    ) {}

    /**
     * @return array{
     *     target: string,
     *     target_label: string,
     *     duration_seconds: int,
     *     duration_text: string,
     *     distance_meters: int,
     *     distance_text: string,
     *     estimated_arrival_at: string,
     *     location_fresh: bool,
     *     route_provider: string|null
     * }|null
     */
    public function forCustomerTracking(Order $order): ?array
    {
        $target = $this->targetResolver->resolve($order);
        if ($target === null) {
            return null;
        }

        $driver = $order->driver;
        if ($driver === null || ! $this->isLocationFresh($driver->location_updated_at)) {
            return null;
        }

        $driverLatitude = $this->targetResolver->coordinateOrNull($driver->latitude, min: -90, max: 90);
        $driverLongitude = $this->targetResolver->coordinateOrNull($driver->longitude, min: -180, max: 180);
        if ($driverLatitude === null || $driverLongitude === null) {
            return null;
        }

        $cacheKey = $this->cacheKey(
            orderId: (int) $order->id,
            driverId: (int) $driver->id,
            statusCode: (string) ($order->statusRef->code ?? ''),
            target: $target,
            driverLatitude: $driverLatitude,
            driverLongitude: $driverLongitude,
            locationUpdatedAt: $driver->location_updated_at,
        );

        /** @var array{
         *     target: string,
         *     target_label: string,
         *     duration_seconds: int,
         *     duration_text: string,
         *     distance_meters: int,
         *     distance_text: string,
         *     estimated_arrival_at: string,
         *     location_fresh: bool,
         *     route_provider: string|null
         * }|null $eta
         */
        $eta = Cache::remember(
            $cacheKey,
            now()->addSeconds(45),
            fn (): ?array => $this->resolveEta(
                driverLatitude: $driverLatitude,
                driverLongitude: $driverLongitude,
                target: $target,
                orderId: (int) $order->id,
            ),
        );

        return $eta;
    }

    /**
     * @param  array{target: string, target_label: string, latitude: float, longitude: float}  $target
     * @return array{
     *     target: string,
     *     target_label: string,
     *     duration_seconds: int,
     *     duration_text: string,
     *     distance_meters: int,
     *     distance_text: string,
     *     estimated_arrival_at: string,
     *     location_fresh: bool,
     *     route_provider: string|null
     * }|null
     */
    private function resolveEta(
        float $driverLatitude,
        float $driverLongitude,
        array $target,
        int $orderId,
    ): ?array {
        try {
            $route = $this->distanceMatrixService->resolveRoute(
                $driverLatitude,
                $driverLongitude,
                $target['latitude'],
                $target['longitude'],
            );
        } catch (\Throwable $exception) {
            Log::warning('Driver arrival ETA route calculation failed.', [
                'order_id' => $orderId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $durationSeconds = max(0, (int) ($route['duration_seconds'] ?? 0));
        if ($durationSeconds <= 0) {
            return null;
        }

        $distanceMeters = max(0, (int) ($route['distance_meters'] ?? 0));

        return [
            'target' => $target['target'],
            'target_label' => $target['target_label'],
            'duration_seconds' => $durationSeconds,
            'duration_text' => $this->firstNonEmptyString([
                $route['duration_text'] ?? null,
                $this->formatDurationText($durationSeconds),
            ]),
            'distance_meters' => $distanceMeters,
            'distance_text' => $this->firstNonEmptyString([
                $route['distance_text'] ?? null,
                $this->formatDistanceText($distanceMeters),
            ]),
            'estimated_arrival_at' => now()->addSeconds($durationSeconds)->toIso8601String(),
            'location_fresh' => true,
            'route_provider' => isset($route['route_provider']) ? (string) $route['route_provider'] : null,
        ];
    }

    /**
     * @param  array{target: string, target_label: string, latitude: float, longitude: float}  $target
     */
    private function cacheKey(
        int $orderId,
        int $driverId,
        string $statusCode,
        array $target,
        float $driverLatitude,
        float $driverLongitude,
        ?CarbonInterface $locationUpdatedAt,
    ): string {
        $payload = json_encode([
            'order_id' => $orderId,
            'driver_id' => $driverId,
            'status_code' => strtoupper(trim($statusCode)),
            'target' => $target['target'],
            'target_latitude' => round($target['latitude'], 7),
            'target_longitude' => round($target['longitude'], 7),
            'driver_latitude' => round($driverLatitude, 7),
            'driver_longitude' => round($driverLongitude, 7),
            'location_updated_at' => $locationUpdatedAt?->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        return 'order-driver-eta:'.hash('sha256', is_string($payload) ? $payload : '');
    }

    private function isLocationFresh(?CarbonInterface $updatedAt): bool
    {
        if ($updatedAt === null) {
            return false;
        }

        $freshMinutes = max(1, (int) config('bangdeliv.dispatch.fresh_location_minutes', 10));

        return $updatedAt->greaterThanOrEqualTo(now()->subMinutes($freshMinutes));
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function firstNonEmptyString(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '-';
    }

    private function formatDurationText(int $durationSeconds): string
    {
        $minutes = (int) max(1, ceil($durationSeconds / 60));

        if ($minutes < 60) {
            return $minutes.' menit';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes === 0
            ? $hours.' jam'
            : $hours.' jam '.$remainingMinutes.' menit';
    }

    private function formatDistanceText(int $distanceMeters): string
    {
        if ($distanceMeters < 1000) {
            return $distanceMeters.' m';
        }

        return number_format($distanceMeters / 1000, 1, ',', '.').' km';
    }
}
