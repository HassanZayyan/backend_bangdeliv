<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AddShoppingOrderItemRequest;
use App\Http\Requests\Api\AddShoppingOrderItemsRequest;
use App\Http\Requests\Api\CancelOrderRequest;
use App\Http\Requests\Api\CreateRideOrderRequest;
use App\Http\Requests\Api\RecordCodPaymentRequest;
use App\Http\Requests\Api\RecordFailedAttemptRequest;
use App\Http\Requests\Api\UpdateDriverShoppingItemsRequest;
use App\Http\Requests\Api\UpdateShoppingOrderItemRequest;
use App\Http\Requests\Api\ValidateRideDestinationRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Order\OrderService;
use App\Services\Order\RideOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OrderService $orderService,
        private readonly RideOrderService $rideOrderService
    ) {}

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

    public function updatePaymentMethod(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:COD,TRANSFER,cod,transfer'],
        ]);

        try {
            $order = $this->orderService->updatePaymentMethodByCustomer(
                $request->user(),
                $orderId,
                $validated
            );

            return $this->success($order, 'Metode pembayaran berhasil diperbarui.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function uploadTransferEvidence(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'photo' => ['required', 'image', 'max:5120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $validated['photo'] = $request->file('photo');

        try {
            $order = $this->orderService->uploadTransferEvidenceByCustomer(
                $request->user(),
                $orderId,
                $validated
            );

            return $this->success($order, 'Bukti QRIS berhasil diupload.', 201);
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function addShoppingItem(AddShoppingOrderItemRequest $request, int $orderId): JsonResponse
    {
        try {
            $validated = $request->validated();
            $order = $this->orderService->addShoppingItem(
                $request->user(),
                $orderId,
                $validated
            );

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

    public function requestShoppingItemChange(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:ADD,UPDATE,REMOVE,CANCEL_MERCHANT'],
            'request_kind' => ['nullable', 'string', 'in:EDIT_UNAVAILABLE'],
            'target_pickup_location_id' => ['nullable', 'integer', 'min:1'],
            'item_id' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array', 'max:30'],
            'items.*.merchant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'items.*.merchant_place' => ['nullable', 'array'],
            'items.*.merchant_place.place_id' => ['nullable', 'string', 'max:255'],
            'items.*.merchant_place.name' => ['required_with:items.*.merchant_place', 'string', 'max:255'],
            'items.*.merchant_place.address' => ['required_with:items.*.merchant_place', 'string', 'max:1000'],
            'items.*.merchant_place.latitude' => ['required_with:items.*.merchant_place', 'numeric', 'between:-90,90'],
            'items.*.merchant_place.longitude' => ['required_with:items.*.merchant_place', 'numeric', 'between:-180,180'],
            'items.*.merchant_place.types' => ['nullable', 'array', 'max:12'],
            'items.*.merchant_place.types.*' => ['string', 'max:80'],
            'items.*.item_source' => ['required_with:items', 'in:MANUAL,MENU_DB'],
            'items.*.menu_id' => ['nullable', 'integer', 'exists:menus,id'],
            'items.*.menu_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $order = $this->orderService->requestShoppingItemChange(
                $request->user(),
                $orderId,
                $validated
            );

            $message = strtoupper((string) ($validated['action'] ?? '')) === 'CANCEL_MERCHANT'
                ? 'Merchant Nitip berhasil dibatalkan.'
                : 'Request perubahan item berhasil dikirim.';

            return $this->success($order, $message);
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function skipFailedShoppingStop(Request $request, int $orderId, int $pickupLocationId): JsonResponse
    {
        return $this->error('Endpoint skip merchant sudah deprecated pada flow Nitip baru.', 410);
    }

    public function respondShoppingPriceQuote(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:APPROVE,CANCEL_MERCHANT'],
            'pickup_location_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $order = $this->orderService->respondShoppingPriceQuoteByCustomer(
                $request->user(),
                $orderId,
                $validated
            );

            return $this->success($order, 'Respons harga Nitip berhasil diproses.');
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

    public function submitShoppingPriceQuote(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'pickup_location_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $payload = $this->orderService->submitShoppingPriceQuoteByDriver(
                $request->user(),
                $orderId,
                $validated
            );

            return $this->success($payload, 'Quote harga Nitip berhasil dikirim.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function acceptShoppingCounter(Request $request, int $orderId): JsonResponse
    {
        return $this->error('Endpoint accept counter harga merchant sudah deprecated pada flow Nitip baru.', 410);
    }

    public function openShoppingStop(Request $request, int $orderId, int $pickupLocationId): JsonResponse
    {
        try {
            $payload = $this->orderService->markShoppingMerchantOpen(
                $request->user(),
                $orderId,
                $pickupLocationId
            );

            return $this->success($payload, 'Merchant ditandai buka.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function bypassShoppingPriceQuote(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'pickup_location_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $payload = $this->orderService->bypassShoppingPriceQuoteByDriver(
                $request->user(),
                $orderId,
                $validated
            );

            return $this->success($payload, 'Harga merchant dilanjutkan oleh driver.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function respondShoppingItemChange(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:APPROVE,REJECT'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $payload = $this->orderService->respondShoppingItemChangeByDriver(
                $request->user(),
                $orderId,
                $validated
            );

            return $this->success($payload, 'Respons perubahan item berhasil diproses.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function updateDeliveryFeeOverride(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:1', 'max:99999999'],
            'reason' => ['nullable', 'string', 'max:1000', 'required_with:amount'],
        ]);

        try {
            $payload = $this->orderService->updateDeliveryFeeOverride(
                $request->user(),
                $orderId,
                $validated
            );

            return $this->success($payload, 'Proposal revisi ongkir berhasil dikirim.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function respondDeliveryFeeOverride(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:APPROVE,COUNTER,CANCEL_ORDER'],
            'counter_amount' => ['nullable', 'numeric', 'min:1', 'max:99999999'],
        ]);

        try {
            $order = $this->orderService->respondDeliveryFeeOverrideByCustomer(
                $request->user(),
                $orderId,
                $validated
            );

            return $this->success($order, 'Respons revisi ongkir berhasil diproses.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function acceptDeliveryFeeCounter(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $payload = $this->orderService->acceptDeliveryFeeCounterByDriver(
                $request->user(),
                $orderId,
                $validated
            );

            return $this->success($payload, 'Tawaran ongkir customer berhasil disetujui.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function uploadDriverProof(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:pickup,delivery,receipt,store_closed,payment_transfer'],
            'photo' => ['required', 'image', 'max:5120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'pickup_location_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $validated['photo'] = $request->file('photo');

        try {
            $payload = $this->orderService->uploadProof(
                $request->user(),
                $orderId,
                $validated
            );

            return $this->success($payload, 'Bukti foto berhasil diupload.', 201);
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function updateShoppingCheckout(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'shopping_total_amount' => ['nullable', 'numeric', 'min:1', 'max:99999999'],
            'delivery_fee_override' => ['nullable', 'numeric', 'min:1', 'max:99999999'],
            'receipt_photo' => ['nullable', 'image', 'max:5120'],
            'items' => ['nullable'],
        ]);

        $items = $request->input('items', []);
        if (is_string($items)) {
            $decodedItems = json_decode($items, true);
            $items = is_array($decodedItems) ? $decodedItems : [];
        }
        if (! is_array($items)) {
            $items = [];
        }

        $validated['items'] = $items;
        $validated['receipt_photo'] = $request->file('receipt_photo');

        try {
            $payload = $this->orderService->updateShoppingCheckout(
                $request->user(),
                $orderId,
                $validated
            );

            return $this->success($payload, 'Checkout nitip berhasil disimpan.');
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

    public function updateCurrentDriverLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'updated_at' => ['nullable', 'date'],
        ]);

        try {
            $payload = $this->orderService->updateCurrentDriverLocation(
                $request->user(),
                $validated
            );

            return $this->success($payload, 'Lokasi standby driver berhasil diperbarui.');
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
            );

            return $this->success($order, 'Status order berhasil diperbarui.');
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), $exception->status(), $exception->errors());
        }
    }

    public function updateDriverLocation(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'updated_at' => ['nullable', 'date'],
        ]);

        try {
            $payload = $this->orderService->updateDriverLocation(
                $request->user(),
                $orderId,
                $validated
            );

            return $this->success($payload, 'Lokasi driver berhasil diperbarui.');
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
                (string) $request->input('reason'),
                $request->filled('pickup_location_id')
                    ? $request->integer('pickup_location_id')
                    : null
            );

            return $this->success($order, 'Failed attempt berhasil dicatat.');
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

    public function recordTransferPaymentByDriver(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'paid_at' => ['nullable', 'date'],
        ]);

        try {
            $payload = $this->orderService->confirmTransferPaymentByDriver(
                $request->user(),
                $orderId,
                $validated
            );

            return $this->success($payload, 'Pembayaran QRIS berhasil dicatat.');
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
