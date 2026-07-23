<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;

class OrderService
{
    public function __construct(
        private readonly OrderPaymentProofService $orderPaymentProofService,
        private readonly DeliveryFeeNegotiationOrchestrator $deliveryFeeNegotiationOrchestrator,
        private readonly ShoppingOrderItemEditService $shoppingOrderItemEditService,
        private readonly ShoppingNegotiationOrchestrator $shoppingNegotiationOrchestrator,
        private readonly DriverOrderWorkflowService $driverOrderWorkflowService,
        private readonly CustomerOrderService $customerOrderService
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateCustomerOrders(User $user, array $filters): LengthAwarePaginator
    {
        return $this->customerOrderService->paginateCustomerOrders($user, $filters);
    }

    public function customerOrderDetail(User $user, int $orderId): Order
    {
        return $this->customerOrderService->customerOrderDetail($user, $orderId);
    }

    public function cancelByCustomer(User $user, int $orderId, string $reason): Order
    {
        return $this->customerOrderService->cancelByCustomer($user, $orderId, $reason);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updatePaymentMethodByCustomer(User $user, int $orderId, array $payload): Order
    {
        return $this->customerOrderService->updatePaymentMethodByCustomer($user, $orderId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function uploadTransferEvidenceByCustomer(User $user, int $orderId, array $payload): Order
    {
        return $this->customerOrderService->uploadTransferEvidenceByCustomer($user, $orderId, $payload);
    }

    public function recordFailedAttempt(
        User $actor,
        int $orderId,
        string $failureType,
        string $reason,
        ?int $pickupLocationId = null,
        ?UploadedFile $merchantClosedPhoto = null,
    ): Order {
        return $this->customerOrderService->recordFailedAttempt($actor, $orderId, $failureType, $reason, $pickupLocationId, $merchantClosedPhoto);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordCodPaymentByDriver(User $actor, int $orderId, array $payload): Order
    {
        return $this->orderPaymentProofService->recordCodPaymentByDriver($actor, $orderId, $payload);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function codSettlementReport(User $actor, array $filters): array
    {
        return $this->orderPaymentProofService->codSettlementReport($actor, $filters);
    }

    /**
     * @return array<string, mixed>
     */
    public function driverAvailability(User $actor): array
    {
        return $this->driverOrderWorkflowService->driverAvailability($actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateDriverAvailability(User $actor, bool $isOnline): array
    {
        return $this->driverOrderWorkflowService->updateDriverAvailability($actor, $isOnline);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateCurrentDriverLocation(User $actor, array $payload): array
    {
        return $this->driverOrderWorkflowService->updateCurrentDriverLocation($actor, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function listDriverOrders(User $actor): array
    {
        return $this->driverOrderWorkflowService->listDriverOrders($actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function listDriverHistory(User $actor): array
    {
        return $this->driverOrderWorkflowService->listDriverHistory($actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function driverOrderDetail(User $actor, int $orderId): array
    {
        return $this->driverOrderWorkflowService->driverOrderDetail($actor, $orderId);
    }

    /**
     * @return array<string, mixed>
     */
    public function acceptByDriver(User $actor, int $orderId): array
    {
        return $this->driverOrderWorkflowService->acceptByDriver($actor, $orderId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function decideUnavailableShoppingItemsByDriver(
        User $actor,
        int $orderId,
        int $pickupLocationId,
        array $payload,
    ): array {
        return $this->shoppingNegotiationOrchestrator->decideUnavailableShoppingItemsByDriver($actor, $orderId, $pickupLocationId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateDriverLocation(User $actor, int $orderId, array $payload): array
    {
        return $this->driverOrderWorkflowService->updateDriverLocation($actor, $orderId, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function rejectByDriver(User $actor, int $orderId, ?string $reason): array
    {
        return $this->driverOrderWorkflowService->rejectByDriver($actor, $orderId, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function transitionStatusByDriver(
        User $actor,
        int $orderId,
        string $actionCode,
        ?string $targetStatusCode = null,
        ?string $note = null,
        ?float $cancellationPenaltyBaseDeliveryFee = null,
    ): array {
        return $this->driverOrderWorkflowService->transitionStatusByDriver($actor, $orderId, $actionCode, $targetStatusCode, $note, $cancellationPenaltyBaseDeliveryFee);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submitShoppingPriceQuoteByDriver(User $actor, int $orderId, array $payload): array
    {
        return $this->shoppingNegotiationOrchestrator->submitShoppingPriceQuoteByDriver($actor, $orderId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function bypassShoppingPriceQuoteByDriver(User $actor, int $orderId, array $payload): array
    {
        return $this->shoppingNegotiationOrchestrator->bypassShoppingPriceQuoteByDriver($actor, $orderId, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function bypassUnavailableShoppingItemsByDriver(
        User $actor,
        int $orderId,
        int $pickupLocationId,
    ): array {
        return $this->shoppingNegotiationOrchestrator->bypassUnavailableShoppingItemsByDriver($actor, $orderId, $pickupLocationId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function replaceUnavailableShoppingItemsByDriver(
        User $actor,
        int $orderId,
        int $pickupLocationId,
        array $payload,
        string $idempotencyKey,
    ): array {
        return $this->shoppingNegotiationOrchestrator->replaceUnavailableShoppingItemsByDriver($actor, $orderId, $pickupLocationId, $payload, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function respondShoppingPriceQuoteByCustomer(User $actor, int $orderId, array $payload): Order
    {
        return $this->shoppingNegotiationOrchestrator->respondShoppingPriceQuoteByCustomer($actor, $orderId, $payload);
    }

    public function cancelShoppingOrderByCustomer(User $actor, int $orderId): Order
    {
        return $this->shoppingNegotiationOrchestrator->cancelShoppingOrderByCustomer($actor, $orderId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function requestShoppingItemChange(User $actor, int $orderId, array $payload): Order
    {
        return $this->shoppingNegotiationOrchestrator->requestShoppingItemChange($actor, $orderId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function respondShoppingItemChangeByDriver(User $actor, int $orderId, array $payload): array
    {
        return $this->shoppingNegotiationOrchestrator->respondShoppingItemChangeByDriver($actor, $orderId, $payload);
    }

    public function markShoppingMerchantOpen(User $actor, int $orderId, int $pickupLocationId): array
    {
        return $this->shoppingNegotiationOrchestrator->markShoppingMerchantOpen($actor, $orderId, $pickupLocationId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateShoppingItemsByDriver(User $actor, int $orderId, array $payload): array
    {
        return $this->shoppingOrderItemEditService->updateShoppingItemsByDriver($actor, $orderId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateDeliveryFeeOverride(User $actor, int $orderId, array $payload): array
    {
        return $this->deliveryFeeNegotiationOrchestrator->updateDeliveryFeeOverride($actor, $orderId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function bypassDeliveryFeeOverrideByDriver(User $actor, int $orderId, array $payload): array
    {
        return $this->deliveryFeeNegotiationOrchestrator->bypassDeliveryFeeOverrideByDriver($actor, $orderId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function acceptDeliveryFeeCounterByDriver(User $actor, int $orderId, array $payload): array
    {
        return $this->deliveryFeeNegotiationOrchestrator->acceptDeliveryFeeCounterByDriver($actor, $orderId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function respondDeliveryFeeOverrideByCustomer(User $actor, int $orderId, array $payload): Order
    {
        return $this->deliveryFeeNegotiationOrchestrator->respondDeliveryFeeOverrideByCustomer($actor, $orderId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function uploadProof(User $actor, int $orderId, array $payload): array
    {
        return $this->orderPaymentProofService->uploadProof($actor, $orderId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateShoppingCheckout(User $actor, int $orderId, array $payload): array
    {
        return $this->shoppingOrderItemEditService->updateShoppingCheckout($actor, $orderId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function confirmTransferPaymentByDriver(User $actor, int $orderId, array $payload): array
    {
        return $this->orderPaymentProofService->confirmTransferPaymentByDriver($actor, $orderId, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function bypassRejectedTransferPaymentByDriver(User $actor, int $orderId): array
    {
        return $this->orderPaymentProofService->bypassRejectedTransferPaymentByDriver($actor, $orderId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function rejectTransferPaymentByDriver(User $actor, int $orderId, array $payload): array
    {
        return $this->orderPaymentProofService->rejectTransferPaymentByDriver($actor, $orderId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addShoppingItem(User $user, int $orderId, array $payload): Order
    {
        return $this->shoppingOrderItemEditService->addShoppingItem($user, $orderId, $payload);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function addShoppingItems(User $user, int $orderId, array $items): Order
    {
        return $this->shoppingOrderItemEditService->addShoppingItems($user, $orderId, $items);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateShoppingItem(User $user, int $orderId, int $itemId, array $payload): Order
    {
        return $this->shoppingOrderItemEditService->updateShoppingItem($user, $orderId, $itemId, $payload);
    }

    public function removeShoppingItem(User $user, int $orderId, int $itemId): Order
    {
        return $this->shoppingOrderItemEditService->removeShoppingItem($user, $orderId, $itemId);
    }
}
