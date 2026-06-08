<?php

namespace App\Services;

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
        $limitCategories = (int) ($filters['limit_categories'] ?? 8);
        $latitude = isset($filters['latitude']) ? (float) $filters['latitude'] : null;
        $longitude = isset($filters['longitude']) ? (float) $filters['longitude'] : null;
        $hasLocation = $latitude !== null && $longitude !== null;

        $restaurantsQuery = Restaurant::query()
            ->where('status', 'active')
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
                'menuCategories' => function ($query): void {
                    $query->orderBy('sort_order')->orderBy('id');
                },
                'menus' => function ($query): void {
                    $query->where('is_available', true)
                        ->with('category')
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
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
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
                'distance_km' => $this->distanceKm($restaurant, $latitude, $longitude),
            ])
            ->values()
            ->all();

        $categoryMap = [];
        foreach ($restaurants as $restaurant) {
            foreach ($restaurant->menuCategories as $category) {
                $key = strtolower(trim((string) $category->name));
                if ($key === '' || isset($categoryMap[$key])) {
                    continue;
                }

                $categoryMap[$key] = [
                    'id' => $category->id,
                    'name' => $category->name,
                ];

                if (count($categoryMap) >= $limitCategories) {
                    break 2;
                }
            }
        }

        $popularMenus = [];
        foreach ($restaurants as $restaurant) {
            foreach ($restaurant->menus as $menu) {
                $popularMenus[] = [
                    'id' => $menu->id,
                    'menu_category_id' => $menu->menu_category_id,
                    'category_name' => $menu->category?->name,
                    'name' => $menu->name,
                    'description' => $menu->description,
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
            'categories' => array_values($categoryMap),
            'popular_menus' => $popularMenus,
            'nearby_merchants' => $nearbyMerchants,
        ];
    }

    private function distanceKm(Restaurant $restaurant, ?float $latitude, ?float $longitude): ?float
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        $restaurantLatitude = (float) $restaurant->latitude;
        $restaurantLongitude = (float) $restaurant->longitude;

        if ($restaurantLatitude === 0.0 && $restaurantLongitude === 0.0) {
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
}
