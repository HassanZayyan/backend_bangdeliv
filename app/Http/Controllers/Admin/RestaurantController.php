<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRestaurantRequest;
use App\Http\Requests\Admin\UpdateRestaurantRequest;
use App\Models\Restaurant;
use App\Services\Admin\AdminMediaUrlResolver;
use App\Services\Admin\AdminPagination;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RestaurantController extends Controller
{
    public function __construct(private readonly AdminMediaUrlResolver $media) {}

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

        $restaurants->getCollection()->each(function (Restaurant $restaurant): void {
            $restaurant->setAttribute('admin_banner_url', $this->media->publicUrl($restaurant->banner_image));
        });

        return view('admin.restaurants.index', compact('restaurants', 'search'));
    }

    public function create(): View
    {
        return view('admin.restaurants.create');
    }

    public function store(StoreRestaurantRequest $request): RedirectResponse
    {
        $payload = $request->validated();
        $bannerImage = $request->file('banner_image');
        unset($payload['banner_image']);

        $payload['slug'] = $this->resolveUniqueSlug(
            (string) $payload['name'],
            $payload['slug'] ?? null
        );

        $restaurant = Restaurant::create($payload);

        if ($bannerImage instanceof UploadedFile) {
            $restaurant->update([
                'banner_image' => $this->storeRestaurantBanner($restaurant, $bannerImage),
            ]);
        }

        return redirect()
            ->route('admin.restaurants.index')
            ->with('success', 'Restoran berhasil ditambahkan.');
    }

    public function edit(Restaurant $restaurant): View
    {
        $restaurant->setAttribute('admin_banner_url', $this->media->publicUrl($restaurant->banner_image));

        return view('admin.restaurants.edit', compact('restaurant'));
    }

    public function update(UpdateRestaurantRequest $request, Restaurant $restaurant): RedirectResponse
    {
        $payload = $request->validated();
        $bannerImage = $request->file('banner_image');
        $removeBannerImage = (bool) ($payload['remove_banner_image'] ?? false);
        $oldBannerImage = (string) ($restaurant->banner_image ?? '');
        unset($payload['banner_image'], $payload['remove_banner_image']);

        $payload['slug'] = $this->resolveUniqueSlug(
            (string) $payload['name'],
            $payload['slug'] ?? null,
            $restaurant->id
        );

        $newBannerImage = null;
        if ($bannerImage instanceof UploadedFile) {
            $newBannerImage = $this->storeRestaurantBanner($restaurant, $bannerImage);
            $payload['banner_image'] = $newBannerImage;
        } elseif ($removeBannerImage) {
            $payload['banner_image'] = null;
        }

        $restaurant->update($payload);

        if (($bannerImage instanceof UploadedFile || $removeBannerImage) && $oldBannerImage !== (string) $newBannerImage) {
            $this->deleteAdminUploadedBanner($restaurant, $oldBannerImage);
        }

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

    private function resolveUniqueSlug(string $name, mixed $slug = null, ?int $ignoreId = null): string
    {
        $source = trim((string) ($slug ?? ''));
        if ($source === '') {
            $source = $name;
        }

        $baseSlug = Str::slug($source);
        if ($baseSlug === '') {
            $baseSlug = 'restoran';
        }

        $candidate = $baseSlug;
        $suffix = 2;

        while (
            Restaurant::withTrashed()
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $candidate = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function storeRestaurantBanner(Restaurant $restaurant, UploadedFile $file): string
    {
        return $file->store('restaurants/uploads/'.$restaurant->id, 'public');
    }

    private function deleteAdminUploadedBanner(Restaurant $restaurant, string $path): void
    {
        $path = trim($path);
        if ($path === '' || $this->media->isRemoteUrl($path)) {
            return;
        }

        $normalizedPath = ltrim($path, '/');
        $allowedPrefix = 'restaurants/uploads/'.$restaurant->id.'/';

        if (
            str_starts_with($normalizedPath, $allowedPrefix) &&
            Storage::disk('public')->exists($normalizedPath)
        ) {
            Storage::disk('public')->delete($normalizedPath);
        }
    }
}
