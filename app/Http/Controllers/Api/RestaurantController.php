<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListRestaurantsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Catalog\RestaurantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RestaurantService $restaurantService) {}

    public function index(ListRestaurantsRequest $request): JsonResponse
    {
        $paginator = $this->restaurantService->paginate($request->validated());

        return $this->success(
            $paginator->items(),
            'Daftar restoran berhasil diambil.',
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]
        );
    }

    public function show(string $restaurantIdOrSlug): JsonResponse
    {
        try {
            $restaurant = $this->restaurantService->findByIdOrSlug($restaurantIdOrSlug);

            return $this->success(
                $this->restaurantService->detailPayload($restaurant),
                'Detail restoran berhasil diambil.'
            );
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function menus(Request $request, string $restaurantIdOrSlug): JsonResponse
    {
        try {
            $request->validate([
                'category_id' => ['nullable', 'integer', 'exists:menu_categories,id'],
                'search' => ['nullable', 'string', 'max:100'],
                'only_available' => ['nullable', 'boolean'],
            ]);

            $restaurant = $this->restaurantService->findByIdOrSlug($restaurantIdOrSlug);
            $payload = $this->restaurantService->menusPayload(
                $restaurant,
                $request->integer('category_id') ?: null,
                $request->string('search')->toString(),
                $request->boolean('only_available', true)
            );

            return $this->success($payload, 'Daftar menu berhasil diambil.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }
}
