<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AddShoppingOrderItemRequest;
use App\Http\Requests\Api\AddShoppingOrderItemsRequest;
use App\Http\Requests\Api\CancelOrderRequest;
use App\Http\Requests\Api\CheckoutOrderRequest;
use App\Http\Requests\Api\CreateRideOrderRequest;
use App\Http\Requests\Api\RecordCodPaymentRequest;
use App\Http\Requests\Api\RecordFailedAttemptRequest;
use App\Http\Requests\Api\UpdateDriverShoppingItemsRequest;
use App\Http\Requests\Api\UpdateShoppingOrderItemRequest;
use App\Http\Requests\Api\ValidateRideDestinationRequest;
use App\Http\Responses\ApiResponse;
use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\RideOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly OrderService $orderService,
        private readonly RideOrderService $rideOrderService
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

    public function createRideOrder(CreateRideOrderRequest $request): JsonResponse
    {
        try {
            $order = $this->rideOrderService->create($request->user(), $request->validated());

            return $this->success($order, 'Order Antar Jemput berhasil dibuat.', 201);
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function validateRideDestination(ValidateRideDestinationRequest $request): JsonResponse
    {
        try {
            $destination = $this->rideOrderService->validateDestination(
                (string) $request->input('destination_address')
            );

            return $this->success($destination, 'Alamat tujuan valid.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
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

    public function addShoppingItem(AddShoppingOrderItemRequest $request, int $orderId): JsonResponse
    {
        try {
            $order = $this->orderService->addShoppingItem($request->user(), $orderId, $request->validated());

            return $this->success($order, 'Item belanja berhasil ditambahkan.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function addShoppingItems(AddShoppingOrderItemsRequest $request, int $orderId): JsonResponse
    {
        try {
            $validated = $request->validated();
            $order = $this->orderService->addShoppingItems(
                $request->user(),
                $orderId,
                $validated['items'] ?? []
            );

            return $this->success($order, 'Item belanja berhasil ditambahkan.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function updateShoppingItem(UpdateShoppingOrderItemRequest $request, int $orderId, int $itemId): JsonResponse
    {
        try {
            $order = $this->orderService->updateShoppingItem($request->user(), $orderId, $itemId, $request->validated());

            return $this->success($order, 'Item belanja berhasil diperbarui.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function removeShoppingItem(Request $request, int $orderId, int $itemId): JsonResponse
    {
        try {
            $order = $this->orderService->removeShoppingItem($request->user(), $orderId, $itemId);

            return $this->success($order, 'Item belanja berhasil dihapus.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function updateDriverShoppingItems(UpdateDriverShoppingItemsRequest $request, int $orderId): JsonResponse
    {
        try {
            $payload = $this->orderService->updateShoppingItemsByDriver(
                $request->user(),
                $orderId,
                $request->validated()
            );

            return $this->success($payload, 'Item belanja berhasil diperbarui dari nota.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function driverHistory(Request $request): JsonResponse
    {
        try {
            $payload = $this->orderService->listDriverHistory($request->user());

            return $this->success($payload, 'Riwayat order driver berhasil diambil.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function driverAvailability(Request $request): JsonResponse
    {
        try {
            $payload = $this->orderService->driverAvailability($request->user());

            return $this->success($payload, 'Status kerja driver berhasil diambil.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function updateDriverAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_online' => ['required', 'boolean'],
        ]);

        try {
            $payload = $this->orderService->updateDriverAvailability(
                $request->user(),
                (bool) $validated['is_online']
            );

            return $this->success($payload, 'Status kerja driver berhasil diperbarui.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function driverOrders(Request $request): JsonResponse
    {
        try {
            $payload = $this->orderService->listDriverOrders($request->user());

            return $this->success($payload, 'Daftar order driver berhasil diambil.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function driverOrderDetail(Request $request, int $orderId): JsonResponse
    {
        try {
            $order = $this->orderService->driverOrderDetail($request->user(), $orderId);

            return $this->success($order, 'Detail order driver berhasil diambil.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function acceptByDriver(Request $request, int $orderId): JsonResponse
    {
        try {
            $order = $this->orderService->acceptByDriver($request->user(), $orderId);

            return $this->success($order, 'Order berhasil diterima driver.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function rejectByDriver(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $order = $this->orderService->rejectByDriver(
                $request->user(),
                $orderId,
                isset($validated['reason']) ? (string) $validated['reason'] : null
            );

            return $this->success($order, 'Order berhasil ditolak driver.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function transitionStatusByDriver(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'action_code' => ['required', 'string', 'max:60'],
            'target_status_code' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        try {
            $order = $this->orderService->transitionStatusByDriver(
                $request->user(),
                $orderId,
                (string) $validated['action_code'],
                isset($validated['target_status_code'])
                    ? (string) $validated['target_status_code']
                    : null,
                isset($validated['note']) ? (string) $validated['note'] : null,
                isset($validated['latitude']) ? (float) $validated['latitude'] : null,
                isset($validated['longitude']) ? (float) $validated['longitude'] : null,
            );

            return $this->success($order, 'Status order berhasil diperbarui.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function recordFailedAttemptByDriver(RecordFailedAttemptRequest $request, int $orderId): JsonResponse
    {
        try {
            $order = $this->orderService->recordFailedAttempt(
                $request->user(),
                $orderId,
                (string) $request->input('failure_type'),
                (string) $request->input('reason')
            );

            return $this->success($order, 'Failed attempt berhasil dicatat.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function recordFailedAttemptByAdmin(RecordFailedAttemptRequest $request, int $orderId): JsonResponse
    {
        try {
            $order = $this->orderService->recordFailedAttempt(
                $request->user(),
                $orderId,
                (string) $request->input('failure_type'),
                (string) $request->input('reason')
            );

            return $this->success($order, 'Failed attempt berhasil dicatat oleh admin.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function recordCodCollectionByDriver(RecordCodPaymentRequest $request, int $orderId): JsonResponse
    {
        try {
            $order = $this->orderService->recordCodPaymentByDriver($request->user(), $orderId, $request->validated());

            return $this->success($order, 'Pembayaran COD berhasil dicatat.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function recordCodCollectionByAdmin(RecordCodPaymentRequest $request, int $orderId): JsonResponse
    {
        try {
            $order = $this->orderService->recordCodPaymentByAdmin($request->user(), $orderId, $request->validated());

            return $this->success($order, 'Pembayaran COD berhasil dicatat oleh admin.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function codSettlementReport(Request $request): JsonResponse
    {
        $request->validate([
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        try {
            $report = $this->orderService->codSettlementReport($request->user(), $request->all());

            return $this->success($report, 'Laporan settlement COD berhasil diambil.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }
}
