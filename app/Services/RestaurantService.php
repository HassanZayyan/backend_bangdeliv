<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Restaurant;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class RestaurantService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Restaurant::query()
            ->where('status', 'active')
            ->with('operatingHours');

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['merchant_type'])) {
            $query->where('merchant_type', (string) $filters['merchant_type']);
        }

        $sort = (string) ($filters['sort'] ?? 'newest');

        if ($sort === 'rating') {
            $query->orderByDesc('avg_rating')->orderByDesc('total_reviews');
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
                'banner_image' => $restaurant->banner_image,
                'avg_rating' => (float) $restaurant->avg_rating,
                'total_reviews' => (int) $restaurant->total_reviews,
                'estimated_prep_time' => (int) $restaurant->estimated_prep_time,
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
            ->where('status', 'active')
            ->where(function (Builder $query) use ($restaurantIdOrSlug): void {
                $query->where('id', $restaurantIdOrSlug)
                    ->orWhere('slug', $restaurantIdOrSlug);
            })
            ->with(['operatingHours', 'menuCategories'])
            ->first();

        if (!$restaurant) {
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
            'description' => $restaurant->description,
            'address' => $restaurant->address,
            'phone' => $restaurant->phone,
            'latitude' => (float) $restaurant->latitude,
            'longitude' => (float) $restaurant->longitude,
            'banner_image' => $restaurant->banner_image,
            'avg_rating' => (float) $restaurant->avg_rating,
            'total_reviews' => (int) $restaurant->total_reviews,
            'estimated_prep_time' => (int) $restaurant->estimated_prep_time,
            'is_open_now' => $this->isOpenNow($restaurant),
            'operating_hours' => $restaurant->operatingHours->map(fn ($item): array => [
                'day_of_week' => (int) $item->day_of_week,
                'open_time' => $item->open_time,
                'close_time' => $item->close_time,
                'is_closed' => (bool) $item->is_closed,
            ])->values()->all(),
            'total_categories' => $restaurant->menuCategories->count(),
            'total_menus' => $restaurant->menus()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function menusPayload(Restaurant $restaurant, ?int $categoryId = null, ?string $search = null, bool $onlyAvailable = true): array
    {
        $categoryQuery = $restaurant->menuCategories()->orderBy('sort_order')->orderBy('id');
        $menuQuery = $restaurant->menus()->with('category')->orderBy('sort_order')->orderBy('id');

        if ($onlyAvailable) {
            $menuQuery->where('is_available', true);
        }

        if ($categoryId !== null) {
            $menuQuery->where('menu_category_id', $categoryId);
        }

        if ($search !== null && $search !== '') {
            $menuQuery->where('name', 'like', '%'.$search.'%');
        }

        $menus = $menuQuery->get();
        $categories = $categoryQuery->get();

        return [
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'merchant_type' => $restaurant->merchant_type,
            ],
            'categories' => $categories->map(fn ($category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'sort_order' => (int) $category->sort_order,
            ])->values()->all(),
            'menus' => $menus->map(fn ($menu): array => [
                'id' => $menu->id,
                'menu_category_id' => $menu->menu_category_id,
                'category_name' => $menu->category?->name,
                'name' => $menu->name,
                'description' => $menu->description,
                'price' => (float) $menu->price,
                'image' => $menu->image,
                'is_available' => (bool) $menu->is_available,
                'sort_order' => (int) $menu->sort_order,
            ])->values()->all(),
        ];
    }

    private function isOpenNow(Restaurant $restaurant): bool
    {
        $today = (int) Carbon::now()->dayOfWeek;
        $currentTime = Carbon::now()->format('H:i:s');

        $schedule = $restaurant->operatingHours
            ->where('day_of_week', $today)
            ->first();

        if (!$schedule || $schedule->is_closed) {
            return false;
        }

        return $currentTime >= $schedule->open_time && $currentTime <= $schedule->close_time;
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
