<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CancelOrderRequest;
use App\Http\Requests\Api\CheckoutOrderRequest;
use App\Http\Responses\ApiResponse;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly OrderService $orderService
    ) {
    }

    public function checkout(CheckoutOrderRequest $request): JsonResponse
    {
        try {
            $order = $this->checkoutService->checkout($request->user(), $request->validated());

            return $this->success($order, 'Checkout berhasil.', 201);
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:confirmed,driver_assigned,item_unavailable,picking_up,on_delivery,delivered,completed,cancelled'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $paginator = $this->orderService->paginateCustomerOrders($request->user(), $request->all());

        return $this->success(
            $paginator->items(),
            'Daftar order berhasil diambil.',
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]
        );
    }

    public function show(Request $request, int $orderId): JsonResponse
    {
        try {
            $order = $this->orderService->customerOrderDetail($request->user(), $orderId);

            return $this->success($order, 'Detail order berhasil diambil.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function cancel(CancelOrderRequest $request, int $orderId): JsonResponse
    {
        try {
            $order = $this->orderService->cancelByCustomer(
                $request->user(),
                $orderId,
                (string) $request->input('reason')
            );

            return $this->success($order, 'Order berhasil dibatalkan.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }
}
