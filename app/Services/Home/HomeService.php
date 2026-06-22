<?php

namespace App\Services\Home;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Builder;

class HomeService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payload(array $filters = []): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $limitMerchants = (int) ($filters['limit_merchants'] ?? 10);
        $limitMenus = (int) ($filters['limit_menus'] ?? 8);
        $latitude = isset($filters['latitude']) ? (float) $filters['latitude'] : null;
        $longitude = isset($filters['longitude']) ? (float) $filters['longitude'] : null;
        $hasLocation = $latitude !== null && $longitude !== null;

        $restaurantsQuery = Restaurant::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhereHas('menus', function (Builder $menuQuery) use ($search): void {
                            $menuQuery->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->with([
                'menus' => function ($query): void {
                    $query->where('is_available', true)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
            ]);

        if ($hasLocation) {
            $restaurantsQuery
                ->select('restaurants.*')
                ->selectRaw(
                    '((latitude - ?) * (latitude - ?) + (longitude - ?) * (longitude - ?)) as distance_sort',
                    [$latitude, $latitude, $longitude, $longitude]
                )
                ->orderByRaw('CASE WHEN latitude IS NULL OR longitude IS NULL THEN 1 ELSE 0 END')
                ->orderBy('distance_sort')
                ->orderBy('name');
        } else {
            $restaurantsQuery
                ->orderBy('name');
        }

        $restaurants = $restaurantsQuery
            ->limit(max($limitMerchants, 5))
            ->get();

        $nearbyMerchants = $restaurants
            ->take($limitMerchants)
            ->map(fn (Restaurant $restaurant): array => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'merchant_type' => $restaurant->merchant_type,
                'banner_image' => $restaurant->banner_image,
                'gallery_images' => $this->galleryImages($restaurant),
                'distance_km' => $this->distanceKm($restaurant, $latitude, $longitude),
            ])
            ->values()
            ->all();

        $popularMenus = [];
        foreach ($restaurants as $restaurant) {
            foreach ($restaurant->menus as $menu) {
                $popularMenus[] = [
                    'id' => $menu->id,
                    'menu_category_id' => null,
                    'category_name' => null,
                    'name' => $menu->name,
                    'price' => (float) $menu->price,
                    'image' => $menu->image,
                    'is_available' => (bool) $menu->is_available,
                    'sort_order' => (int) $menu->sort_order,
                    'restaurant_name' => $restaurant->name,
                ];

                if (count($popularMenus) >= $limitMenus) {
                    break 2;
                }
            }
        }

        return [
            'categories' => [],
            'popular_menus' => $popularMenus,
            'nearby_merchants' => $nearbyMerchants,
        ];
    }

    private function distanceKm(Restaurant $restaurant, ?float $latitude, ?float $longitude): ?float
    {
        $restaurantLatitude = $this->nullableFloat($restaurant->latitude);
        $restaurantLongitude = $this->nullableFloat($restaurant->longitude);

        if (
            $latitude === null ||
            $longitude === null ||
            $restaurantLatitude === null ||
            $restaurantLongitude === null
        ) {
            return null;
        }

        $earthRadiusKm = 6371;
        $latFrom = deg2rad($latitude);
        $lonFrom = deg2rad($longitude);
        $latTo = deg2rad($restaurantLatitude);
        $lonTo = deg2rad($restaurantLongitude);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
                cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return round($earthRadiusKm * $angle, 2);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * @return array<int, string>
     */
    private function galleryImages(Restaurant $restaurant): array
    {
        return collect($restaurant->gallery_images ?? [])
            ->filter(fn ($image): bool => is_string($image) && trim($image) !== '')
            ->values()
            ->all();
    }
}
