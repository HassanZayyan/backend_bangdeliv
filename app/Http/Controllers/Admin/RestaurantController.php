<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRestaurantRequest;
use App\Http\Requests\Admin\UpdateRestaurantRequest;
use App\Models\Restaurant;
use App\Services\Admin\AdminPagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RestaurantController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $restaurants = Restaurant::query()
            ->withCount(['menus', 'orders'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($subQuery) use ($search): void {
                    $subQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->latest('created_at')
            ->paginate(AdminPagination::PER_PAGE)
            ->withQueryString();

        return view('admin.restaurants.index', compact('restaurants', 'search'));
    }

    public function create(): View
    {
        return view('admin.restaurants.create');
    }

    public function store(StoreRestaurantRequest $request): RedirectResponse
    {
        $payload = $request->validated();
        $payload['slug'] = $payload['slug'] ?: Str::slug($payload['name']);

        Restaurant::create($payload);

        return redirect()
            ->route('admin.restaurants.index')
            ->with('success', 'Restoran berhasil ditambahkan.');
    }

    public function edit(Restaurant $restaurant): View
    {
        return view('admin.restaurants.edit', compact('restaurant'));
    }

    public function update(UpdateRestaurantRequest $request, Restaurant $restaurant): RedirectResponse
    {
        $payload = $request->validated();
        $payload['slug'] = $payload['slug'] ?: Str::slug($payload['name']);

        $restaurant->update($payload);

        return redirect()
            ->route('admin.restaurants.index')
            ->with('success', 'Restoran berhasil diperbarui.');
    }

    public function destroy(Restaurant $restaurant): RedirectResponse
    {
        $restaurant->delete();

        return redirect()
            ->route('admin.restaurants.index')
            ->with('success', 'Restoran berhasil dihapus.');
    }

}
