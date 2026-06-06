<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Database\Eloquent\Relations\Relation;

class DriverOrderPayloadFactory
{
    public function __construct(private readonly ShoppingPricingService $shoppingPricingService) {}

    /**
     * @return array<int|string, mixed>
     */
    public function relations(): array
    {
        return [
            'user:id,name,phone',
            'serviceType:id,code,display_name',
            'statusRef:id,code,display_name',
            'restaurant:id,name,address,latitude,longitude,phone,merchant_type',
            'rideOrder:id,order_id,picked_up_at,arrived_at',
            'courierOrder:id,order_id,package_description,estimated_weight_kg,package_length_cm,package_width_cm,package_height_cm,package_size_class,package_safety_status,package_safety_flags,package_safety_reason,package_packing_note,requires_photo_evidence',
            'shoppingOrder:id,order_id,failed_attempt_count,item_surcharge,overweight_surcharge,cancellation_penalty,has_overweight_item,recalculation_version,last_recalculated_at,pricing_snapshot',
            'items:id,order_id,menu_id,pickup_location_id,item_source,menu_name,quantity,unit_price,subtotal,notes,metadata,is_available,is_heavy',
            'orderLocations:id,order_id,restaurant_id,location_role,label,contact_name,contact_phone,full_address,latitude,longitude,sequence_no,fulfillment_status,failed_attempt_count,failure_reason,failed_at,resolved_at',
            'orderLocations.restaurant:id,name,address,latitude,longitude,phone,merchant_type',
            'payments:id,order_id,payment_method,payment_status,amount,recorded_by_user_id,driver_id,paid_at',
            'evidences:id,order_id,driver_id,evidence_type,file_url,verification_status,uploaded_at,notes,created_at',
            'statusHistories' => function (Relation $query): void {
                $query
                    ->with('statusRef:id,code,display_name')
                    ->orderBy('created_at');
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Order $order, bool $includeTimeline = false): array
    {
        $serviceCode = strtoupper((string) ($order->serviceType->code ?? ''));
        $statusCode = strtoupper((string) ($order->statusRef->code ?? ''));
        $paymentStatus = strtolower((string) ($order->payment_status ?? 'unpaid'));
        $paymentMethod = strtoupper((string) ($order->payment_method ?? 'COD'));

        $pickup = $this->resolvePickupPoint($order, $serviceCode);
        $dropoff = $this->resolveDropoffPoint($order);
        $proofs = $this->serializeProofs($order);
        $proofStatus = $this->proofStatus($proofs);
        $hasDriverShoppingTotal = $serviceCode === 'SHOPPING' && $this->shoppingPricingService->hasDriverShoppingTotal($order);
        $hasPendingShoppingPrices = $serviceCode === 'SHOPPING' && $this->hasPendingManualShoppingPrices($order);
        $canCancelShoppingWithFee = $serviceCode === 'SHOPPING' && $order->shoppingOrder !== null
            && $this->shoppingPricingService->isCancellationPenaltyEligible($order, $order->shoppingOrder);
        $availableActions = $this->resolveAvailableDriverActions(
            $serviceCode,
            $statusCode,
            $paymentStatus,
            $paymentMethod,
            $hasPendingShoppingPrices,
            $hasDriverShoppingTotal,
            $canCancelShoppingWithFee,
            $proofStatus,
        );

        $acceptedAt = $order->statusHistories
            ->first(fn (OrderStatusHistory $history): bool => strtoupper((string) ($history->statusRef->code ?? '')) === 'DRIVER_ASSIGNED');

        $itemCount = (int) $order->items->sum('quantity');
        if ($itemCount < 1) {
            $itemCount = 1;
        }

        $pricingSnapshot = $this->pricingSnapshot($order);
        $deliveryFee = round((float) $order->delivery_fee, 2);

        $payload = [
            'id' => (string) $order->id,
            'order_number' => $order->order_number,
            'service_type_code' => $serviceCode,
            'service_type_name' => $order->serviceType?->display_name,
            'customer_name' => $order->user->name ?? '-',
            'customer_phone' => $order->user?->phone,
            'pickup_address' => $pickup['address'],
            'pickup_latitude' => $pickup['latitude'],
            'pickup_longitude' => $pickup['longitude'],
            'dropoff_address' => $dropoff['address'],
            'dropoff_latitude' => $dropoff['latitude'],
            'dropoff_longitude' => $dropoff['longitude'],
            'fee' => (int) round($deliveryFee),
            'delivery_distance_km' => $order->delivery_distance_km !== null ? round((float) $order->delivery_distance_km, 2) : null,
            'delivery_distance_text' => $order->delivery_distance_text,
            'delivery_fee' => $deliveryFee,
            'delivery_fee_source' => $order->delivery_fee_source ?: 'system',
            'manual_delivery_fee' => $order->manual_delivery_fee !== null ? round((float) $order->manual_delivery_fee, 2) : null,
            'manual_delivery_fee_reason' => $order->manual_delivery_fee_reason,
            'careful_carry_required' => (bool) ($order->careful_carry_required ?? false),
            'pricing_snapshot' => $pricingSnapshot,
            'fee_breakdown' => $this->feeBreakdown($order, $pricingSnapshot),
            'proofs' => $proofs,
            'total_price' => round((float) $order->total_price, 2),
            'item_count' => $itemCount,
            'eta_minutes' => $this->estimateEtaMinutes($order),
            'accepted_at' => $acceptedAt?->created_at?->format('H:i'),
            'status_code' => $statusCode,
            'status_display_name' => $order->statusRef?->display_name,
            'payment_status' => $paymentStatus,
            'payment_method' => $order->payment_method,
            'available_actions' => $availableActions,
            'route' => $this->orderRouteSnapshot($order),
        ];

        if ($serviceCode === 'COURIER' && $order->courierOrder !== null) {
            $payload['package_description'] = $order->courierOrder->package_description;
            $payload['package_estimated_weight_kg'] = $order->courierOrder->estimated_weight_kg !== null
                ? (float) $order->courierOrder->estimated_weight_kg
                : null;
            $payload['package_length_cm'] = $order->courierOrder->package_length_cm;
            $payload['package_width_cm'] = $order->courierOrder->package_width_cm;
            $payload['package_height_cm'] = $order->courierOrder->package_height_cm;
            $payload['package_size_class'] = $order->courierOrder->package_size_class;
            $payload['package_safety_status'] = $order->courierOrder->package_safety_status;
            $payload['package_safety_reason'] = $order->courierOrder->package_safety_reason;
            $payload['package_packing_note'] = $order->courierOrder->package_packing_note;
        }

        if ($serviceCode === 'SHOPPING') {
            $payload['merchant'] = [
                'id' => $order->restaurant?->id,
                'name' => $order->restaurant?->name,
                'merchant_type' => $order->restaurant?->merchant_type,
                'address' => $order->restaurant?->address,
                'phone' => $order->restaurant?->phone,
                'latitude' => $this->toFloatOrNull($order->restaurant?->latitude),
                'longitude' => $this->toFloatOrNull($order->restaurant?->longitude),
            ];
            $payload['shopping_items'] = $this->serializeShoppingItems($order);
            $payload['shopping_stops'] = $this->serializeShoppingStops($order);
            $payload['shopping_route'] = $payload['route'] ?? $this->shoppingRouteSnapshot($order);
            $payload['pricing'] = [
                'subtotal' => round((float) $order->subtotal, 2),
                'delivery_fee' => round((float) $order->delivery_fee, 2),
                'service_fee' => round((float) $order->service_fee, 2),
                'total_price' => round((float) $order->total_price, 2),
                'item_surcharge' => round((float) ($order->shoppingOrder?->item_surcharge ?? 0), 2),
                'overweight_surcharge' => round((float) ($order->shoppingOrder?->overweight_surcharge ?? 0), 2),
                'cancellation_penalty' => round((float) ($order->shoppingOrder?->cancellation_penalty ?? 0), 2),
                'recalculation_version' => (int) ($order->shoppingOrder?->recalculation_version ?? 0),
                'has_pending_manual_prices' => $hasPendingShoppingPrices,
                'failed_attempt_count' => (int) ($order->shoppingOrder?->failed_attempt_count ?? 0),
                'failed_attempt_threshold' => $this->shoppingPricingService->cancellationFailedAttemptThreshold(
                    (int) $order->service_type_id
                ),
                'can_cancel_with_fee' => $canCancelShoppingWithFee,
                'fee_breakdown' => $order->shoppingOrder?->fee_breakdown ?? [],
            ];
            $payload['has_pending_shopping_prices'] = $hasPendingShoppingPrices;
        }

        if ($includeTimeline) {
            $payload['status_timeline'] = $this->serializeStatusTimeline($order);
        }

        return $payload;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function driverActionRules(string $serviceCode): array
    {
        return match (strtoupper($serviceCode)) {
            'RIDE' => $this->rideActionRules(),
            'SHOPPING' => $this->shoppingActionRules(),
            default => $this->courierActionRules(),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeProofs(Order $order): array
    {
        $order->loadMissing('evidences');

        return $order->evidences
            ->sortByDesc(fn ($evidence): int => $evidence->uploaded_at?->getTimestamp() ?? $evidence->created_at?->getTimestamp() ?? 0)
            ->map(function ($evidence): array {
                $type = $this->canonicalProofType((string) $evidence->evidence_type);

                return [
                    'id' => (int) $evidence->id,
                    'type' => $type,
                    'evidence_type' => strtoupper((string) $evidence->evidence_type),
                    'photo_url' => $evidence->file_url,
                    'file_url' => $evidence->file_url,
                    'status' => strtolower((string) ($evidence->verification_status ?? 'pending')),
                    'uploaded_at' => $evidence->uploaded_at?->toIso8601String() ?? $evidence->created_at?->toIso8601String(),
                    'note' => $evidence->notes,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $proofs
     * @return array<string, bool>
     */
    private function proofStatus(array $proofs): array
    {
        $status = [
            'pickup' => false,
            'delivery' => false,
            'receipt' => false,
            'store_closed' => false,
            'payment_transfer' => false,
        ];

        foreach ($proofs as $proof) {
            $type = (string) ($proof['type'] ?? '');
            if (array_key_exists($type, $status)) {
                $status[$type] = true;
            }
        }

        return $status;
    }

    private function canonicalProofType(string $evidenceType): string
    {
        return match (strtoupper($evidenceType)) {
            'PICKUP_PHOTO' => 'pickup',
            'DELIVERY_PHOTO', 'COURIER_DELIVERY_PHOTO', 'COURIER_RECEIVER_PHOTO' => 'delivery',
            'SHOPPING_RECEIPT' => 'receipt',
            'STORE_CLOSED_PHOTO' => 'store_closed',
            'PAYMENT_TRANSFER_PHOTO' => 'payment_transfer',
            default => strtolower($evidenceType),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function pricingSnapshot(Order $order): array
    {
        $route = $this->orderRouteSnapshot($order);
        $deliveryPricing = is_array($route) && is_array($route['delivery_pricing'] ?? null)
            ? $route['delivery_pricing']
            : [];

        return [
            'delivery_pricing' => $deliveryPricing,
            'delivery_fee' => round((float) $order->delivery_fee, 2),
            'delivery_fee_source' => $order->delivery_fee_source ?: 'system',
            'manual_delivery_fee' => $order->manual_delivery_fee !== null ? round((float) $order->manual_delivery_fee, 2) : null,
            'manual_delivery_fee_reason' => $order->manual_delivery_fee_reason,
            'careful_carry_required' => (bool) ($order->careful_carry_required ?? false),
            'route' => $route,
            'shopping_pricing' => $order->shoppingOrder?->pricing_snapshot,
        ];
    }

    /**
     * @param  array<string, mixed>  $pricingSnapshot
     * @return array<int, array<string, mixed>>
     */
    private function feeBreakdown(Order $order, array $pricingSnapshot): array
    {
        $breakdown = [];
        $deliveryPricing = $pricingSnapshot['delivery_pricing'] ?? [];
        if (is_array($deliveryPricing) && is_array($deliveryPricing['fee_breakdown'] ?? null)) {
            $breakdown = $deliveryPricing['fee_breakdown'];
        }

        $serviceFee = round((float) $order->service_fee, 2);
        if ($serviceFee > 0) {
            $breakdown[] = [
                'code' => 'service_fee',
                'label' => 'Biaya layanan',
                'amount' => $serviceFee,
            ];
        }

        if ((bool) ($order->careful_carry_required ?? false)) {
            $breakdown[] = [
                'code' => 'careful_carry',
                'label' => 'Bawa hati-hati',
                'amount' => $this->carefulCarrySurchargeFromOrder($order),
            ];
        }

        if (($order->delivery_fee_source ?: 'system') === 'manual') {
            $breakdown[] = [
                'code' => 'manual_override',
                'label' => 'Ongkir manual driver',
                'amount' => round((float) ($order->manual_delivery_fee ?? $order->delivery_fee), 2),
                'reason' => $order->manual_delivery_fee_reason,
            ];
        }

        return array_values($breakdown);
    }

    private function carefulCarrySurchargeFromOrder(Order $order): float
    {
        if (($order->delivery_fee_source ?: 'system') === 'manual' && $order->manual_delivery_fee !== null) {
            return round(max(0.0, (float) $order->manual_delivery_fee) * 0.5, 2);
        }

        $route = $this->orderRouteSnapshot($order);
        $systemFee = is_array($route)
            ? (float) data_get($route, 'delivery_pricing.total_fee', $order->delivery_fee)
            : (float) $order->delivery_fee;

        return round($systemFee * 0.5, 2);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeStatusTimeline(Order $order): array
    {
        return $order->statusHistories
            ->sortBy('created_at')
            ->map(function (OrderStatusHistory $history): array {
                return [
                    'status_code' => strtoupper((string) ($history->statusRef->code ?? '')),
                    'status_display_name' => $history->statusRef?->display_name,
                    'event_type' => strtoupper((string) $history->event_type),
                    'note' => $history->note,
                    'created_at' => $history->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, float|string|null>
     */
    private function resolvePickupPoint(Order $order, string $serviceCode): array
    {
        if ($serviceCode === 'SHOPPING') {
            $pickup = $order->orderLocations
                ->filter(fn ($location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
                ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
                ->first();

            return [
                'address' => $pickup?->full_address ?? $order->restaurant->address ?? '-',
                'latitude' => $this->toFloatOrNull($pickup?->latitude ?? $order->restaurant?->latitude),
                'longitude' => $this->toFloatOrNull($pickup?->longitude ?? $order->restaurant?->longitude),
            ];
        }

        $pickup = $order->orderLocations
            ->first(fn ($location): bool => strtoupper((string) $location->location_role) === 'PICKUP');

        return [
            'address' => $pickup?->full_address ?? '-',
            'latitude' => $this->toFloatOrNull($pickup?->latitude),
            'longitude' => $this->toFloatOrNull($pickup?->longitude),
        ];
    }

    /**
     * @return array<string, float|string|null>
     */
    private function resolveDropoffPoint(Order $order): array
    {
        $dropoff = $order->orderLocations
            ->first(fn ($location): bool => strtoupper((string) $location->location_role) === 'DROPOFF');

        return [
            'address' => $dropoff?->full_address,
            'latitude' => $this->toFloatOrNull($dropoff?->latitude),
            'longitude' => $this->toFloatOrNull($dropoff?->longitude),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveAvailableDriverActions(
        string $serviceCode,
        string $statusCode,
        string $paymentStatus,
        string $paymentMethod,
        bool $hasPendingShoppingPrices = false,
        bool $hasDriverShoppingTotal = false,
        bool $canCancelShoppingWithFee = false,
        array $proofStatus = [],
    ): array {
        $actions = [];
        $rules = $this->driverActionRules($serviceCode);
        foreach ($rules as $actionCode => $rule) {
            if (! in_array($statusCode, (array) $rule['from'], true)) {
                continue;
            }

            $requiresPaid = (bool) ($rule['requires_paid'] ?? false);
            $requiresUnpaid = (bool) ($rule['requires_unpaid'] ?? false);
            if ($requiresUnpaid && $paymentStatus === 'paid') {
                continue;
            }

            $blockedReasons = [];
            if ($requiresPaid && $paymentStatus !== 'paid') {
                $blockedReasons[] = 'Pembayaran belum dicatat.';
            }

            if (
                $this->supportsDriverProofType($serviceCode, 'pickup') &&
                in_array($actionCode, ['BOARD_PASSENGER', 'CONFIRM_PICKED_UP'], true) &&
                ! (bool) ($proofStatus['pickup'] ?? false)
            ) {
                $blockedReasons[] = 'Bukti foto pickup belum diupload.';
            }

            if (
                $serviceCode === 'SHOPPING' &&
                $actionCode === 'CONFIRM_PICKED_UP' &&
                ! $hasDriverShoppingTotal
            ) {
                $blockedReasons[] = 'Total belanja di struk belum diisi.';
            }

            if (
                $this->supportsDriverProofType($serviceCode, 'receipt') &&
                $actionCode === 'CONFIRM_PICKED_UP' &&
                ! (bool) ($proofStatus['receipt'] ?? false)
            ) {
                $blockedReasons[] = 'Foto struk belanja belum diupload.';
            }

            if (
                $this->supportsDriverProofType($serviceCode, 'delivery') &&
                $actionCode === 'COMPLETE_ORDER' &&
                ! (bool) ($proofStatus['delivery'] ?? false)
            ) {
                $blockedReasons[] = 'Bukti foto selesai pengantaran belum diupload.';
            }

            if (
                $serviceCode === 'SHOPPING' &&
                $actionCode === 'CONFIRM_PICKED_UP' &&
                $hasPendingShoppingPrices &&
                ! $hasDriverShoppingTotal
            ) {
                $blockedReasons[] = 'Harga nota untuk item manual belum lengkap.';
            }

            if ($serviceCode === 'SHOPPING' && ($rule['requires_failed_attempt_threshold'] ?? false) && ! $canCancelShoppingWithFee) {
                continue;
            }

            $actions[] = [
                'action_code' => $actionCode,
                'label' => $rule['label'],
                'target_status_code' => $rule['to'],
                'blocked' => $blockedReasons !== [],
                'blocked_reason' => $blockedReasons !== [] ? implode(' ', $blockedReasons) : null,
            ];
        }

        $shouldCollectCourierAtPickup = $serviceCode === 'COURIER' &&
            $statusCode === 'ARRIVED_PICKUP' &&
            $paymentStatus !== 'paid' &&
            $paymentMethod === 'COD';
        $shouldCollectAtDelivered = $serviceCode !== 'COURIER' &&
            $statusCode === 'DELIVERED' &&
            $paymentStatus !== 'paid' &&
            $paymentMethod === 'COD';

        if ($shouldCollectCourierAtPickup || $shouldCollectAtDelivered) {
            $actions[] = [
                'action_code' => 'COLLECT_COD',
                'label' => $shouldCollectCourierAtPickup
                    ? 'Catat Pembayaran Pickup'
                    : 'Catat Pembayaran COD',
                'target_status_code' => null,
                'blocked' => false,
                'blocked_reason' => null,
            ];
        }

        return $actions;
    }

    private function supportsDriverProofType(string $serviceCode, string $type): bool
    {
        return match (strtoupper($serviceCode)) {
            'COURIER' => in_array($type, ['pickup', 'delivery'], true),
            'SHOPPING' => in_array($type, ['receipt', 'store_closed'], true),
            default => false,
        };
    }

    /**
     * RIDE - Antar Jemput Orang (4 active steps).
     * PICKED_UP is skipped: boarding a passenger means immediately on the way.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rideActionRules(): array
    {
        return [
            'ARRIVE_PICKUP' => [
                'label' => 'Tiba di Titik Jemput',
                'from' => ['DRIVER_ASSIGNED'],
                'to' => 'ARRIVED_PICKUP',
            ],
            'BOARD_PASSENGER' => [
                'label' => 'Penumpang Sudah Naik',
                'from' => ['ARRIVED_PICKUP'],
                'to' => 'ON_THE_WAY',
            ],
            'ARRIVE_DROPOFF' => [
                'label' => 'Tiba di Tujuan',
                'from' => ['ON_THE_WAY'],
                'to' => 'ARRIVED_DROPOFF',
            ],
            'CONFIRM_DELIVERED' => [
                'label' => 'Penumpang Turun',
                'from' => ['ARRIVED_DROPOFF'],
                'to' => 'DELIVERED',
            ],
            'COMPLETE_ORDER' => [
                'label' => 'Selesaikan Order',
                'from' => ['DELIVERED'],
                'to' => 'COMPLETED',
                'requires_paid' => true,
            ],
        ];
    }

    /**
     * COURIER - Antar Barang / Kurir (5 active steps).
     *
     * @return array<string, array<string, mixed>>
     */
    private function courierActionRules(): array
    {
        return [
            'ARRIVE_PICKUP' => [
                'label' => 'Tiba di Titik Pickup',
                'from' => ['DRIVER_ASSIGNED'],
                'to' => 'ARRIVED_PICKUP',
            ],
            'CONFIRM_PICKED_UP' => [
                'label' => 'Paket Diambil',
                'from' => ['ARRIVED_PICKUP'],
                'to' => 'PICKED_UP',
                'requires_paid' => true,
            ],
            'REPORT_PACKAGE_INVALID' => [
                'label' => 'Barang Tidak Sesuai',
                'from' => ['ARRIVED_PICKUP'],
                'to' => 'CANCELLED',
                'requires_unpaid' => true,
            ],
            'START_DELIVERY' => [
                'label' => 'Mulai Antar',
                'from' => ['PICKED_UP'],
                'to' => 'ON_THE_WAY',
            ],
            'ARRIVE_DROPOFF' => [
                'label' => 'Tiba di Tujuan',
                'from' => ['ON_THE_WAY'],
                'to' => 'ARRIVED_DROPOFF',
            ],
            'CONFIRM_DELIVERED' => [
                'label' => 'Paket Diserahkan',
                'from' => ['ARRIVED_DROPOFF'],
                'to' => 'DELIVERED',
            ],
            'COMPLETE_ORDER' => [
                'label' => 'Selesaikan Order',
                'from' => ['DELIVERED'],
                'to' => 'COMPLETED',
                'requires_paid' => true,
            ],
        ];
    }

    /**
     * SHOPPING - Titip Belanja (5 active steps).
     * Uses ARRIVED_MERCHANT instead of ARRIVED_PICKUP for the first arrival.
     *
     * @return array<string, array<string, mixed>>
     */
    private function shoppingActionRules(): array
    {
        return [
            'ARRIVE_PICKUP' => [
                'label' => 'Tiba di Toko / Merchant',
                'from' => ['DRIVER_ASSIGNED'],
                'to' => 'ARRIVED_MERCHANT',
            ],
            'CONFIRM_PICKED_UP' => [
                'label' => 'Belanja Selesai',
                'from' => ['ARRIVED_MERCHANT'],
                'to' => 'PICKED_UP',
            ],
            'START_DELIVERY' => [
                'label' => 'Menuju Customer',
                'from' => ['PICKED_UP'],
                'to' => 'ON_THE_WAY',
            ],
            'ARRIVE_DROPOFF' => [
                'label' => 'Tiba di Lokasi Customer',
                'from' => ['ON_THE_WAY'],
                'to' => 'ARRIVED_DROPOFF',
            ],
            'CONFIRM_DELIVERED' => [
                'label' => 'Barang Diserahkan',
                'from' => ['ARRIVED_DROPOFF'],
                'to' => 'DELIVERED',
            ],
            'COMPLETE_ORDER' => [
                'label' => 'Selesaikan Order',
                'from' => ['DELIVERED'],
                'to' => 'COMPLETED',
                'requires_paid' => true,
            ],
            'CANCEL_WITH_FEE' => [
                'label' => 'Batalkan Order (Fee 50%)',
                'from' => ['DRIVER_ASSIGNED', 'ARRIVED_MERCHANT'],
                'to' => 'CANCELLED_WITH_FEE',
                'requires_failed_attempt_threshold' => true,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeShoppingItems(Order $order): array
    {
        return $order->items
            ->map(function ($item): array {
                $isManual = strtoupper((string) $item->item_source) === 'MANUAL';
                $isAvailable = (bool) $item->is_available;
                $unitPrice = (float) $item->unit_price;

                return [
                    'id' => (int) $item->id,
                    'pickup_location_id' => $item->pickup_location_id !== null ? (int) $item->pickup_location_id : null,
                    'menu_id' => $item->menu_id !== null ? (int) $item->menu_id : null,
                    'item_source' => strtoupper((string) $item->item_source),
                    'name' => (string) $item->menu_name,
                    'menu_name' => (string) $item->menu_name,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => round($unitPrice, 2),
                    'subtotal' => round((float) $item->subtotal, 2),
                    'line_total' => round((float) $item->subtotal, 2),
                    'notes' => $item->notes,
                    'is_available' => $isAvailable,
                    'is_heavy' => (bool) $item->is_heavy,
                    'price_status' => $this->shoppingItemPriceStatus($item, $isManual, $isAvailable, $unitPrice),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeShoppingStops(Order $order): array
    {
        $pickups = $order->orderLocations
            ->filter(fn ($location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
            ->values();

        if ($pickups->isEmpty()) {
            return [];
        }

        $firstPickupId = (int) $pickups->first()->id;

        return $pickups
            ->map(function ($pickup) use ($order, $firstPickupId): array {
                $pickupId = (int) $pickup->id;
                $restaurantId = $pickup->restaurant_id !== null ? (int) $pickup->restaurant_id : null;

                $items = $order->items
                    ->filter(function ($item) use ($order, $pickupId, $firstPickupId, $restaurantId): bool {
                        if ($item->pickup_location_id !== null) {
                            return (int) $item->pickup_location_id === $pickupId;
                        }

                        return $pickupId === $firstPickupId
                            && $restaurantId !== null
                            && (int) $order->restaurant_id === $restaurantId;
                    })
                    ->map(fn ($item): array => [
                        ...$this->serializeShoppingItem($item),
                        'pickup_location_id' => $pickupId,
                    ])
                    ->values()
                    ->all();

                return [
                    'pickup_location_id' => $pickupId,
                    'sequence_no' => (int) $pickup->sequence_no,
                    'fulfillment_status' => strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING')),
                    'failed_attempt_count' => (int) ($pickup->failed_attempt_count ?? 0),
                    'failure_reason' => $pickup->failure_reason,
                    'failed_at' => $pickup->failed_at?->toIso8601String(),
                    'resolved_at' => $pickup->resolved_at?->toIso8601String(),
                    'merchant' => [
                        'id' => $restaurantId,
                        'name' => $pickup->restaurant?->name ?? $pickup->contact_name ?? $pickup->label,
                        'merchant_type' => $pickup->restaurant?->merchant_type,
                        'address' => $pickup->full_address,
                        'phone' => $pickup->contact_phone,
                        'latitude' => $this->toFloatOrNull($pickup->latitude),
                        'longitude' => $this->toFloatOrNull($pickup->longitude),
                    ],
                    'items' => $items,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function orderRouteSnapshot(Order $order): ?array
    {
        $route = $order->route_snapshot;

        return is_array($route) ? $route : $this->shoppingRouteSnapshot($order);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function shoppingRouteSnapshot(Order $order): ?array
    {
        $route = $order->route_snapshot;
        if (is_array($route)) {
            return $route;
        }

        $snapshot = $order->shoppingOrder?->pricing_snapshot;
        if (! is_array($snapshot)) {
            return null;
        }

        $route = $snapshot['shopping_route'] ?? null;

        return is_array($route) ? $route : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeShoppingItem($item): array
    {
        $isManual = strtoupper((string) $item->item_source) === 'MANUAL';
        $isAvailable = (bool) $item->is_available;
        $unitPrice = (float) $item->unit_price;

        return [
            'id' => (int) $item->id,
            'pickup_location_id' => $item->pickup_location_id !== null ? (int) $item->pickup_location_id : null,
            'menu_id' => $item->menu_id !== null ? (int) $item->menu_id : null,
            'item_source' => strtoupper((string) $item->item_source),
            'name' => (string) $item->menu_name,
            'menu_name' => (string) $item->menu_name,
            'quantity' => (int) $item->quantity,
            'unit_price' => round($unitPrice, 2),
            'subtotal' => round((float) $item->subtotal, 2),
            'line_total' => round((float) $item->subtotal, 2),
            'notes' => $item->notes,
            'is_available' => $isAvailable,
            'is_heavy' => (bool) $item->is_heavy,
            'price_status' => $this->shoppingItemPriceStatus($item, $isManual, $isAvailable, $unitPrice),
        ];
    }

    private function shoppingItemPriceStatus($item, bool $isManual, bool $isAvailable, float $unitPrice): string
    {
        $metadataStatus = (string) data_get($item->metadata, 'price_status', '');
        if ($metadataStatus !== '') {
            return $metadataStatus;
        }

        return $isManual && $isAvailable && $unitPrice <= 0
            ? 'PENDING_DRIVER_INPUT'
            : 'CONFIRMED';
    }

    private function hasPendingManualShoppingPrices(Order $order): bool
    {
        if ($this->shoppingPricingService->hasDriverShoppingTotal($order)) {
            return false;
        }

        return $order->items->contains(function ($item): bool {
            return strtoupper((string) $item->item_source) === 'MANUAL'
                && (bool) $item->is_available
                && (float) $item->unit_price <= 0;
        });
    }

    private function estimateEtaMinutes(Order $order): int
    {
        if ($order->estimated_delivery === null) {
            return 0;
        }

        $minutes = now()->diffInMinutes($order->estimated_delivery, false);

        return $minutes > 0 ? $minutes : 0;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
