<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRestaurantRequest;
use App\Http\Requests\Admin\UpdateRestaurantRequest;
use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RestaurantController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $statusFilter = (string) $request->query('status', 'all');

        $restaurants = Restaurant::query()
            ->withCount(['menus', 'orders'])
            ->when(in_array($statusFilter, ['active', 'inactive'], true), function ($query) use ($statusFilter): void {
                $query->where('status', $statusFilter);
            })
            ->when($statusFilter === 'closed', function ($query): void {
                // Placeholder filter: saat ini tidak ada status "closed" di schema.
                $query->whereRaw('1 = 0');
            })
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
            ->paginate(10)
            ->withQueryString();

        $openCount = Restaurant::where('status', 'active')->count();
        $suspendedCount = Restaurant::where('status', 'inactive')->count();
        $closedCount = max($restaurants->total() - $openCount - $suspendedCount, 0);

        return view('admin.restaurants.index', compact(
            'restaurants',
            'openCount',
            'suspendedCount',
            'closedCount',
            'search',
            'statusFilter'
        ));
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

    public function toggleStatus(Restaurant $restaurant): RedirectResponse
    {
        $restaurant->update([
            'status' => $restaurant->status === 'active' ? 'inactive' : 'active',
        ]);

        return redirect()
            ->route('admin.restaurants.index')
            ->with('success', 'Status restoran berhasil diperbarui.');
    }
}
