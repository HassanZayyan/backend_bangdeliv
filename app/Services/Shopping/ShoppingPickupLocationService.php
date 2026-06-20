<?php

namespace App\Services\Shopping;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLocation;
use App\Models\Restaurant;
use App\Support\GeoDistance;

class ShoppingPickupLocationService
{
    /**
     * @return array<string, mixed>
     */
    public function merchantPayloadFromPickup(OrderLocation $pickup): array
    {
        if ($pickup->restaurant_id !== null) {
            return ['merchant_id' => (int) $pickup->restaurant_id];
        }

        return [
            'merchant_place' => [
                'place_id' => null,
                'name' => $this->pickupMerchantName($pickup),
                'address' => (string) $pickup->full_address,
                'latitude' => (float) $pickup->latitude,
                'longitude' => (float) $pickup->longitude,
                'types' => [],
            ],
        ];
    }

    public function pickupById(Order $order, int $pickupLocationId): OrderLocation
    {
        $order->loadMissing('orderLocations');

        $pickup = $order->orderLocations->first(
            fn (OrderLocation $location): bool => (int) $location->id === $pickupLocationId
                && strtoupper((string) $location->location_role) === 'PICKUP'
        );

        if (! $pickup instanceof OrderLocation) {
            throw new ApiException('Merchant/pickup order tidak valid.', 422);
        }

        return $pickup;
    }

    public function hasActivePickupWithAvailableItems(Order $order): bool
    {
        $order->loadMissing(['orderLocations', 'items']);

        return $order->orderLocations
            ->filter(function (OrderLocation $location): bool {
                if (strtoupper((string) $location->location_role) !== 'PICKUP') {
                    return false;
                }

                return ! in_array(
                    strtoupper((string) ($location->fulfillment_status ?? 'PENDING')),
                    ['FAILED', 'SKIPPED', 'REPLACED', 'CANCELLED'],
                    true
                );
            })
            ->contains(function (OrderLocation $pickup) use ($order): bool {
                $pickupId = (int) $pickup->id;

                return $order->items->contains(
                    fn (OrderItem $item): bool => (int) ($item->pickup_location_id ?? 0) === $pickupId
                        && (bool) ($item->is_available ?? true)
                );
            });
    }

    public function activePickupCount(Order $order): int
    {
        return OrderLocation::query()
            ->where('order_id', $order->id)
            ->where('location_role', 'PICKUP')
            ->whereNotIn('fulfillment_status', ['FAILED', 'SKIPPED', 'REPLACED', 'CANCELLED'])
            ->count();
    }

    public function resolveForCandidate(Order $order, ShoppingMerchantCandidate $candidate): ?OrderLocation
    {
        if ($candidate->restaurant instanceof Restaurant) {
            return $this->resolveForMerchant($order, $candidate->restaurant);
        }

        return $this->resolveForExternalPlace($order, $candidate);
    }

    public function createForCandidate(Order $order, ShoppingMerchantCandidate $candidate): OrderLocation
    {
        if ($candidate->restaurant instanceof Restaurant) {
            return $this->createForMerchant($order, $candidate->restaurant);
        }

        return $this->createForExternalPlace($order, $candidate);
    }

    private function resolveForMerchant(Order $order, Restaurant $merchant): ?OrderLocation
    {
        $order->loadMissing(['orderLocations.restaurant']);

        $pickup = $order->orderLocations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->first(function (OrderLocation $location) use ($merchant): bool {
                if ((int) $location->restaurant_id !== (int) $merchant->id) {
                    return false;
                }

                return ! in_array(
                    strtoupper((string) ($location->fulfillment_status ?? 'PENDING')),
                    ['FAILED', 'SKIPPED', 'REPLACED'],
                    true
                );
            });

        if ($pickup instanceof OrderLocation) {
            return $pickup;
        }

        if ((int) $order->restaurant_id === (int) $merchant->id) {
            $legacyPickup = $order->orderLocations
                ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
                ->first(fn (OrderLocation $location): bool => $location->restaurant_id === null);

            if ($legacyPickup instanceof OrderLocation) {
                $legacyPickup->update([
                    'restaurant_id' => $merchant->id,
                    'label' => $legacyPickup->label ?: 'Merchant',
                    'contact_name' => $legacyPickup->contact_name ?: $merchant->name,
                    'contact_phone' => $legacyPickup->contact_phone ?: $merchant->phone,
                    'full_address' => $legacyPickup->full_address ?: $merchant->address,
                    'latitude' => $legacyPickup->latitude ?: $merchant->latitude,
                    'longitude' => $legacyPickup->longitude ?: $merchant->longitude,
                ]);

                return $legacyPickup->refresh();
            }
        }

        return null;
    }

    private function resolveForExternalPlace(Order $order, ShoppingMerchantCandidate $candidate): ?OrderLocation
    {
        $order->loadMissing(['orderLocations.restaurant']);

        return $order->orderLocations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->first(function (OrderLocation $location) use ($candidate): bool {
                if ($location->restaurant_id !== null) {
                    return false;
                }

                if (in_array(
                    strtoupper((string) ($location->fulfillment_status ?? 'PENDING')),
                    ['FAILED', 'SKIPPED', 'REPLACED'],
                    true
                )) {
                    return false;
                }

                $sameName = $this->normalizeLocationText((string) ($location->contact_name ?? ''))
                    === $candidate->normalizedName();
                if (! $sameName) {
                    return false;
                }

                return GeoDistance::meters(
                    (float) $location->latitude,
                    (float) $location->longitude,
                    $candidate->latitude,
                    $candidate->longitude,
                ) <= 30;
            });
    }

    private function createForMerchant(Order $order, Restaurant $merchant): OrderLocation
    {
        $maxSequence = (int) $order->orderLocations()->max('sequence_no');

        return $order->orderLocations()->create([
            'restaurant_id' => $merchant->id,
            'location_role' => 'PICKUP',
            'label' => 'Merchant',
            'contact_name' => $merchant->name,
            'contact_phone' => $merchant->phone,
            'full_address' => $merchant->address,
            'latitude' => $merchant->latitude,
            'longitude' => $merchant->longitude,
            'sequence_no' => max(1, $maxSequence + 1),
        ]);
    }

    private function createForExternalPlace(Order $order, ShoppingMerchantCandidate $candidate): OrderLocation
    {
        $maxSequence = (int) $order->orderLocations()->max('sequence_no');

        return $order->orderLocations()->create([
            'restaurant_id' => null,
            'location_role' => 'PICKUP',
            'label' => 'Merchant',
            'contact_name' => $candidate->name,
            'contact_phone' => null,
            'full_address' => $candidate->address,
            'latitude' => $candidate->latitude,
            'longitude' => $candidate->longitude,
            'sequence_no' => max(1, $maxSequence + 1),
        ]);
    }

    private function pickupMerchantName(OrderLocation $pickup): string
    {
        foreach ([$pickup->contact_name, $pickup->label] as $candidate) {
            $name = trim((string) ($candidate ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return 'Merchant';
    }

    private function normalizeLocationText(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
