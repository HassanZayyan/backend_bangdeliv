<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Restaurant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class RestaurantService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Restaurant::query();

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['merchant_type'])) {
            $query->where('merchant_type', (string) $filters['merchant_type']);
        }

        $sort = (string) ($filters['sort'] ?? 'newest');

        if ($sort === 'name') {
            $query->orderBy('name')->orderBy('id');
        } else {
            $query->latest('id');
        }

        $perPage = (int) ($filters['per_page'] ?? 10);
        $paginator = $query->paginate($perPage);

        $latitude = isset($filters['latitude']) ? (float) $filters['latitude'] : null;
        $longitude = isset($filters['longitude']) ? (float) $filters['longitude'] : null;

        $transformed = $paginator->getCollection()->map(function (Restaurant $restaurant) use ($latitude, $longitude): array {
            return [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'merchant_type' => $restaurant->merchant_type,
                'address' => $restaurant->address,
                'latitude' => (float) $restaurant->latitude,
                'longitude' => (float) $restaurant->longitude,
                'banner_image' => $restaurant->banner_image,
                'is_open_now' => $this->isOpenNow($restaurant),
                'distance_km' => $this->distanceKm($restaurant, $latitude, $longitude),
            ];
        });

        if ($sort === 'nearest' && $latitude !== null && $longitude !== null) {
            $transformed = $transformed->sortBy('distance_km')->values();
        }

        $paginator->setCollection($transformed);

        return $paginator;
    }

    public function findByIdOrSlug(string $restaurantIdOrSlug): Restaurant
    {
        $restaurant = Restaurant::query()
            ->where(function (Builder $query) use ($restaurantIdOrSlug): void {
                $query->where('id', $restaurantIdOrSlug)
                    ->orWhere('slug', $restaurantIdOrSlug);
            })
            ->first();

        if (! $restaurant) {
            throw new ApiException('Restoran tidak ditemukan.', 404);
        }

        return $restaurant;
    }

    /**
     * @return array<string, mixed>
     */
    public function detailPayload(Restaurant $restaurant): array
    {
        return [
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'slug' => $restaurant->slug,
            'merchant_type' => $restaurant->merchant_type,
            'address' => $restaurant->address,
            'phone' => $restaurant->phone,
            'latitude' => (float) $restaurant->latitude,
            'longitude' => (float) $restaurant->longitude,
            'banner_image' => $restaurant->banner_image,
            'is_open_now' => $this->isOpenNow($restaurant),
            'operating_hours' => [],
            'total_categories' => 0,
            'total_menus' => $restaurant->menus()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function menusPayload(Restaurant $restaurant, ?string $search = null, bool $onlyAvailable = true): array
    {
        $menuQuery = $restaurant->menus()->orderBy('sort_order')->orderBy('id');

        if ($onlyAvailable) {
            $menuQuery->where('is_available', true);
        }

        if ($search !== null && $search !== '') {
            $menuQuery->where('name', 'like', '%'.$search.'%');
        }

        $menus = $menuQuery->get();

        return [
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'merchant_type' => $restaurant->merchant_type,
            ],
            'categories' => [],
            'menus' => $menus->map(fn ($menu): array => [
                'id' => $menu->id,
                'menu_category_id' => null,
                'category_name' => null,
                'name' => $menu->name,
                'price' => (float) $menu->price,
                'image' => $menu->image,
                'is_available' => (bool) $menu->is_available,
                'sort_order' => (int) $menu->sort_order,
            ])->values()->all(),
        ];
    }

    private function isOpenNow(Restaurant $restaurant): bool
    {
        return true;
    }

    private function distanceKm(Restaurant $restaurant, ?float $latitude, ?float $longitude): ?float
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        $earthRadiusKm = 6371;
        $latFrom = deg2rad($latitude);
        $lonFrom = deg2rad($longitude);
        $latTo = deg2rad((float) $restaurant->latitude);
        $lonTo = deg2rad((float) $restaurant->longitude);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
                cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return round($earthRadiusKm * $angle, 2);
    }
}
