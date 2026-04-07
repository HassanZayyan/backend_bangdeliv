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

        $restaurants = Restaurant::query()
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
            ])
            ->orderByDesc('avg_rating')
            ->orderByDesc('total_reviews')
            ->limit(max($limitMerchants, 12))
            ->get();

        $nearbyMerchants = $restaurants
            ->take($limitMerchants)
            ->map(fn (Restaurant $restaurant): array => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'banner_image' => $restaurant->banner_image,
                'avg_rating' => (float) $restaurant->avg_rating,
                'total_reviews' => (int) $restaurant->total_reviews,
                'estimated_prep_time' => (int) $restaurant->estimated_prep_time,
                'distance_km' => null,
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
                    'restaurant_rating' => (float) $restaurant->avg_rating,
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
}