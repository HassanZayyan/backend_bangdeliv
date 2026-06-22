<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuRequest;
use App\Http\Requests\Admin\UpdateMenuRequest;
use App\Models\Menu;
use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RestaurantMenuController extends Controller
{
    public function index(Restaurant $restaurant): View
    {
        $restaurant->load([
            'menus' => fn ($query) => $query->orderBy('sort_order')->orderBy('name'),
        ]);

        return view('admin.restaurants.menus.index', [
            'restaurant' => $restaurant,
            'menus' => $restaurant->menus,
        ]);
    }

    public function store(StoreMenuRequest $request, Restaurant $restaurant): RedirectResponse
    {
        $payload = $request->validated();

        $restaurant->menus()->create([
            'name' => $payload['name'],
            'price' => $payload['price'],
            'is_available' => (bool) $payload['is_available'],
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ]);

        return redirect()
            ->route('admin.restaurants.menus.index', $restaurant)
            ->with('success', 'Menu berhasil ditambahkan.');
    }

    public function update(UpdateMenuRequest $request, Restaurant $restaurant, Menu $menu): RedirectResponse
    {
        abort_if((int) $menu->restaurant_id !== (int) $restaurant->id, 404);

        $payload = $request->validated();

        $menu->update([
            'name' => $payload['name'],
            'price' => $payload['price'],
            'is_available' => (bool) $payload['is_available'],
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ]);

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
}
