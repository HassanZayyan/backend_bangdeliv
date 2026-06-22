<?php

namespace App\Services\Geo;

use App\Exceptions\ApiException;
use App\Support\GeoDistance;

class BangDelivServiceAreaService
{
    public const ERROR_DISTANCE_LIMIT = 'SERVICE_AREA_DISTANCE_LIMIT';

    public function centerLatitude(): float
    {
        return (float) config('bangdeliv.service_area.center.latitude', -7.319916770351389);
    }

    public function centerLongitude(): float
    {
        return (float) config('bangdeliv.service_area.center.longitude', 110.46393594806243);
    }

    public function radiusKm(): float
    {
        return (float) config('bangdeliv.service_area.radius_km', 50);
    }

    public function radiusMeters(): float
    {
        return $this->radiusKm() * 1000;
    }

    public function centerName(): string
    {
        return trim((string) config('bangdeliv.service_area.name', 'Angkringan 54'));
    }

    public function centerAddress(): string
    {
        return trim((string) config('bangdeliv.service_area.address', ''));
    }

    public function distanceFromCenterMeters(float $latitude, float $longitude): float
    {
        return GeoDistance::meters(
            $this->centerLatitude(),
            $this->centerLongitude(),
            $latitude,
            $longitude
        );
    }

    public function isWithinRadius(float $latitude, float $longitude): bool
    {
        return $this->distanceFromCenterMeters($latitude, $longitude) <= $this->radiusMeters();
    }

    /**
     * @param  array<int, array{role: string, label?: string|null, latitude: mixed, longitude: mixed}>  $points
     */
    public function assertPointsWithinRadius(array $points): void
    {
        foreach ($points as $point) {
            $this->assertPointWithinRadius(
                $point['latitude'] ?? null,
                $point['longitude'] ?? null,
                (string) ($point['role'] ?? 'titik'),
                isset($point['label']) ? (string) $point['label'] : null
            );
        }
    }

    public function assertPointWithinRadius(
        mixed $latitude,
        mixed $longitude,
        string $role,
        ?string $label = null
    ): void {
        if (! GeoDistance::isValidCoordinatePair($latitude, $longitude)) {
            throw new ApiException('Koordinat '.$role.' tidak valid.', 422, [
                'code' => 'SERVICE_AREA_INVALID_COORDINATE',
                'point_role' => $role,
                'point_label' => $label,
            ]);
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;
        $distanceMeters = $this->distanceFromCenterMeters($lat, $lng);

        if ($distanceMeters <= $this->radiusMeters()) {
            return;
        }

        $distanceKm = round($distanceMeters / 1000, 2);
        $radiusKm = $this->radiusKm();
        $pointName = $label !== null && trim($label) !== ''
            ? sprintf('%s (%s)', $role, trim($label))
            : $role;

        throw new ApiException(sprintf(
            'Titik %s berjarak %.2f km dari pusat Pelanggan 15, melebihi batas layanan %.2f km.',
            $pointName,
            $distanceKm,
            $radiusKm
        ), 422, [
            'code' => self::ERROR_DISTANCE_LIMIT,
            'point_role' => $role,
            'point_label' => $label,
            'distance_from_center_km' => $distanceKm,
            'service_area_radius_km' => $radiusKm,
            'service_area_center' => [
                'name' => $this->centerName(),
                'address' => $this->centerAddress(),
                'latitude' => $this->centerLatitude(),
                'longitude' => $this->centerLongitude(),
            ],
        ]);
    }
}
