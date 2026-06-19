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
        $canEditUnavailableItems = $isShopping
            && $status === 'ARRIVED_MERCHANT'
            && ! $hasPendingItemChangeRequest
            && $this->hasUnavailableActiveItems($order);
        $canDriverSubmitQuote = $isShopping
            && $status === 'ARRIVED_MERCHANT'
            && ! $hasPendingItemChangeRequest
            && $this->hasMerchantReadyForQuote($order);
        $canDriverUploadReceipt = $isShopping
            && $status === 'ARRIVED_MERCHANT'
            && ! $hasPendingItemChangeRequest
            && app(ShoppingPriceNegotiationService::class)->isApproved($order);

        return [
            'can_customer_direct_edit_items' => $isShopping && $status === 'PENDING',
            'can_customer_request_item_change' => $canEditUnavailableItems,
            'can_customer_request_add_stop' => false,
            'can_customer_edit_unavailable_items' => $canEditUnavailableItems,
            'can_customer_resolve_failed_merchant' => false,
            'can_driver_mark_merchant_open' => $isShopping
                && in_array($status, ['DRIVER_ASSIGNED', 'ARRIVED_MERCHANT'], true)
                && $this->hasPendingMerchant($order),
            'can_driver_mark_merchant_closed' => $isShopping
                && in_array($status, ['DRIVER_ASSIGNED', 'ARRIVED_MERCHANT'], true)
                && $this->hasNonTerminalMerchant($order),
            'can_driver_update_item_availability' => $isShopping
                && $status === 'ARRIVED_MERCHANT'
                && ! $hasPendingItemChangeRequest
                && $this->hasOpenMerchant($order),
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

    private function hasPendingMerchant(Order $order): bool
    {
        return $this->hasMerchantWithStatus($order, ['PENDING']);
    }

    private function hasOpenMerchant(Order $order): bool
    {
        return $this->hasMerchantWithStatus($order, ['OPEN_CONFIRMED', 'ITEMS_PENDING_CUSTOMER', 'ITEMS_CONFIRMED']);
    }

    private function hasMerchantReadyForQuote(Order $order): bool
    {
        return $this->hasMerchantWithStatus($order, ['ITEMS_CONFIRMED']);
    }

    private function hasNonTerminalMerchant(Order $order): bool
    {
        $order->loadMissing('orderLocations');

        return $order->orderLocations->contains(function ($location): bool {
            if (strtoupper((string) $location->location_role) !== 'PICKUP') {
                return false;
            }

            $status = strtoupper((string) ($location->fulfillment_status ?? 'PENDING'));

            return ! in_array($status, ['FAILED', 'SKIPPED', 'REPLACED', 'COMPLETED'], true);
        });
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function hasMerchantWithStatus(Order $order, array $statuses): bool
    {
        $order->loadMissing('orderLocations');
        $allowed = array_map('strtoupper', $statuses);

        return $order->orderLocations->contains(function ($location) use ($allowed): bool {
            if (strtoupper((string) $location->location_role) !== 'PICKUP') {
                return false;
            }

            return in_array(strtoupper((string) ($location->fulfillment_status ?? 'PENDING')), $allowed, true);
        });
    }
}
