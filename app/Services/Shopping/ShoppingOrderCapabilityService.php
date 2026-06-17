<?php

namespace App\Services\Shopping;

use App\Models\Order;

class ShoppingOrderCapabilityService
{
    /**
     * @return array<string, bool>
     */
    public function capabilities(Order $order): array
    {
        $status = $this->statusCode($order);
        $isShopping = $this->isShopping($order);
        $hasPendingItemChangeRequest = $this->hasPendingItemChangeRequest($order);
        $canRequestAddStop = $isShopping
            && $status === 'DRIVER_ASSIGNED'
            && ! $hasPendingItemChangeRequest;
        $canEditUnavailableItems = $isShopping
            && $status === 'ARRIVED_MERCHANT'
            && ! $hasPendingItemChangeRequest
            && $this->hasUnavailableActiveItems($order);
        $canDriverSubmitQuote = $isShopping
            && $status === 'ARRIVED_MERCHANT'
            && ! $hasPendingItemChangeRequest;
        $canDriverUploadReceipt = $canDriverSubmitQuote
            && app(ShoppingPriceNegotiationService::class)->isApproved($order);

        return [
            'can_customer_direct_edit_items' => $isShopping && $status === 'PENDING',
            'can_customer_request_item_change' => $canRequestAddStop || $canEditUnavailableItems,
            'can_customer_request_add_stop' => $canRequestAddStop,
            'can_customer_edit_unavailable_items' => $canEditUnavailableItems,
            'can_customer_resolve_failed_merchant' => $isShopping && in_array($status, ['DRIVER_ASSIGNED', 'ARRIVED_MERCHANT'], true),
            'can_driver_update_item_availability' => $isShopping
                && $status === 'ARRIVED_MERCHANT'
                && ! $hasPendingItemChangeRequest,
            'can_driver_submit_shopping_quote' => $canDriverSubmitQuote,
            'can_driver_submit_merchant_quote' => $canDriverSubmitQuote,
            'can_driver_upload_receipt' => $canDriverUploadReceipt,
            'has_pending_item_change_request' => $hasPendingItemChangeRequest,
        ];
    }

    public function canCustomerDirectEditItems(Order $order): bool
    {
        return $this->capabilities($order)['can_customer_direct_edit_items'];
    }

    public function canCustomerRequestItemChange(Order $order): bool
    {
        return $this->capabilities($order)['can_customer_request_item_change'];
    }

    public function canCustomerRequestAddStop(Order $order): bool
    {
        return $this->capabilities($order)['can_customer_request_add_stop'];
    }

    public function canCustomerEditUnavailableItems(Order $order): bool
    {
        return $this->capabilities($order)['can_customer_edit_unavailable_items'];
    }

    public function canCustomerResolveFailedMerchant(Order $order): bool
    {
        return $this->capabilities($order)['can_customer_resolve_failed_merchant'];
    }

    public function canDriverUpdateItemAvailability(Order $order): bool
    {
        return $this->capabilities($order)['can_driver_update_item_availability'];
    }

    public function canDriverSubmitShoppingQuote(Order $order): bool
    {
        return $this->capabilities($order)['can_driver_submit_shopping_quote'];
    }

    public function canDriverUploadReceipt(Order $order): bool
    {
        return $this->capabilities($order)['can_driver_upload_receipt'];
    }

    public function isShopping(Order $order): bool
    {
        return strtoupper((string) ($order->serviceType?->code ?? '')) === 'SHOPPING';
    }

    public function statusCode(Order $order): string
    {
        return strtoupper((string) ($order->statusRef?->code ?? ''));
    }

    private function hasPendingItemChangeRequest(Order $order): bool
    {
        return app(ShoppingItemChangeRequestService::class)->hasPending($order);
    }

    private function hasUnavailableActiveItems(Order $order): bool
    {
        $order->loadMissing('items');

        return $order->items->contains(fn ($item): bool => ! (bool) ($item->is_available ?? true));
    }
}
