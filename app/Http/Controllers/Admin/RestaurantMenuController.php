<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuRequest;
use App\Http\Requests\Admin\UpdateMenuRequest;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RestaurantMenuController extends Controller
{
    public function index(Restaurant $restaurant): View
    {
        $restaurant->load([
            'menuCategories' => fn ($query) => $query->orderBy('sort_order')->orderBy('name'),
            'menus' => fn ($query) => $query->with('category')->orderBy('sort_order')->orderBy('name'),
        ]);

        return view('admin.restaurants.menus.index', [
            'restaurant' => $restaurant,
            'categories' => $restaurant->menuCategories,
            'menus' => $restaurant->menus,
        ]);
    }

    public function store(StoreMenuRequest $request, Restaurant $restaurant): RedirectResponse
    {
        $payload = $request->validated();

        DB::transaction(function () use ($restaurant, $payload): void {
            $categoryId = $this->resolveCategoryId($restaurant, $payload);

            $restaurant->menus()->create([
                'menu_category_id' => $categoryId,
                'name' => $payload['name'],
                'description' => $payload['description'] ?? null,
                'price' => $payload['price'],
                'is_available' => (bool) $payload['is_available'],
                'sort_order' => (int) ($payload['sort_order'] ?? 0),
            ]);
        });

        return redirect()
            ->route('admin.restaurants.menus.index', $restaurant)
            ->with('success', 'Menu berhasil ditambahkan.');
    }

    public function update(UpdateMenuRequest $request, Restaurant $restaurant, Menu $menu): RedirectResponse
    {
        abort_if((int) $menu->restaurant_id !== (int) $restaurant->id, 404);

        $payload = $request->validated();

        DB::transaction(function () use ($restaurant, $menu, $payload): void {
            $categoryId = $this->resolveCategoryId($restaurant, $payload);

            $menu->update([
                'menu_category_id' => $categoryId,
                'name' => $payload['name'],
                'description' => $payload['description'] ?? null,
                'price' => $payload['price'],
                'is_available' => (bool) $payload['is_available'],
                'sort_order' => (int) ($payload['sort_order'] ?? 0),
            ]);
        });

        return redirect()
            ->route('admin.restaurants.menus.index', $restaurant)
            ->with('success', 'Menu berhasil diperbarui.');
    }

    public function destroy(Restaurant $restaurant, Menu $menu): RedirectResponse
    {
        abort_if((int) $menu->restaurant_id !== (int) $restaurant->id, 404);

        $menu->delete();

        return redirect()
            ->route('admin.restaurants.menus.index', $restaurant)
            ->with('success', 'Menu berhasil dihapus.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCategoryId(Restaurant $restaurant, array $payload): ?int
    {
        $newCategory = trim((string) ($payload['new_category_name'] ?? ''));

        if ($newCategory !== '') {
            $category = MenuCategory::query()->firstOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'name' => $newCategory,
                ],
                [
                    'sort_order' => 0,
                ]
            );

            return (int) $category->id;
        }

        if (! empty($payload['menu_category_id'])) {
            return (int) $payload['menu_category_id'];
        }

        return null;
    }
}
