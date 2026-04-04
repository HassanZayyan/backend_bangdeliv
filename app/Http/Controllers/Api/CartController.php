<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AddCartItemRequest;
use App\Http\Requests\Api\UpdateCartItemRequest;
use App\Http\Responses\ApiResponse;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CartService $cartService)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $cart = $this->cartService->getActiveCart($request->user());

        return $this->success(
            $this->cartService->cartPayload($cart),
            'Cart berhasil diambil.'
        );
    }

    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        try {
            $result = $this->cartService->addItem($request->user(), $request->validated());

            return $this->success(
                $result['cart'],
                'Item berhasil ditambahkan ke cart.',
                $result['created'] ? 201 : 200
            );
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function updateItem(UpdateCartItemRequest $request, int $itemId): JsonResponse
    {
        try {
            $payload = $this->cartService->updateItem($request->user(), $itemId, $request->validated());

            return $this->success($payload, 'Item cart berhasil diperbarui.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function removeItem(Request $request, int $itemId): JsonResponse
    {
        try {
            $payload = $this->cartService->removeItem($request->user(), $itemId);

            return $this->success($payload, 'Item cart berhasil dihapus.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function clear(Request $request): JsonResponse
    {
        $payload = $this->cartService->clear($request->user());

        return $this->success($payload, 'Cart berhasil dikosongkan.');
    }
}
