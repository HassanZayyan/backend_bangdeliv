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
        $latitude = isset($filters['latitude']) ? (float) $filters['latitude'] : null;
        $longitude = isset($filters['longitude']) ? (float) $filters['longitude'] : null;

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

        if ($sort === 'nearest' && $latitude !== null && $longitude !== null) {
            $query
                ->select('restaurants.*')
                ->selectRaw(
                    '((latitude - ?) * (latitude - ?) + (longitude - ?) * (longitude - ?)) as distance_sort',
                    [$latitude, $latitude, $longitude, $longitude]
                )
                ->orderByRaw('CASE WHEN latitude IS NULL OR longitude IS NULL THEN 1 ELSE 0 END')
                ->orderBy('distance_sort')
                ->orderBy('name')
                ->orderBy('id');
        } elseif ($sort === 'name') {
            $query->orderBy('name')->orderBy('id');
        } else {
            $query->latest('id');
        }

        $perPage = (int) ($filters['per_page'] ?? 10);
        $paginator = $query->paginate($perPage);

        $transformed = $paginator->getCollection()->map(function (Restaurant $restaurant) use ($latitude, $longitude): array {
            return [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'merchant_type' => $restaurant->merchant_type,
                'address' => $restaurant->address,
                'latitude' => $this->nullableFloat($restaurant->latitude),
                'longitude' => $this->nullableFloat($restaurant->longitude),
                'banner_image' => $restaurant->banner_image,
                'gallery_images' => $this->galleryImages($restaurant),
                'is_open_now' => $this->isOpenNow($restaurant),
                'distance_km' => $this->distanceKm($restaurant, $latitude, $longitude),
            ];
        });

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
            'latitude' => $this->nullableFloat($restaurant->latitude),
            'longitude' => $this->nullableFloat($restaurant->longitude),
            'banner_image' => $restaurant->banner_image,
            'gallery_images' => $this->galleryImages($restaurant),
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
