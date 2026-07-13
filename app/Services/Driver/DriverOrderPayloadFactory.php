<?php

namespace App\Services\Driver;

use App\Enums\DriverActionCode;
use App\Enums\PaymentMethod;
use App\Enums\ProofType;
use App\Enums\ServiceTypeCode;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderStatusHistory;
use App\Services\Admin\AdminPaymentProofStatusService;
use App\Services\Driver\Dispatch\OrderPickupPointResolver;
use App\Services\Order\DeliveryFeeNegotiationService;
use App\Services\Order\OrderProofPolicyService;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingItemChangeRequestService;
use App\Services\Shopping\ShoppingOrderCapabilityService;
use App\Services\Shopping\ShoppingUnavailableItemDecisionService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Storage;

class DriverOrderPayloadFactory
{
    public function __construct(
        private readonly ShoppingPricingService $shoppingPricingService,
        private readonly DriverIncomeFeeCalculator $driverIncomeFeeCalculator,
        private readonly OrderProofPolicyService $proofPolicyService,
        private readonly OrderPickupPointResolver $pickupPointResolver,
        private readonly DeliveryFeeNegotiationService $deliveryFeeNegotiationService,
        private readonly ShoppingOrderCapabilityService $shoppingOrderCapabilityService,
        private readonly ShoppingItemChangeRequestService $shoppingItemChangeRequestService,
        private readonly ShoppingUnavailableItemDecisionService $shoppingUnavailableItemDecisionService,
    ) {}

    /**
     * @return array<int|string, mixed>
     */
    public function relations(): array
    {
        return [
            'user:id,name,phone,avatar',
            'serviceType:id,code,display_name',
            'statusRef:id,code,display_name',
            'shoppingReceipt',
            'restaurant',
            'rideOrder:id,order_id,picked_up_at,arrived_at',
            'courierOrder:id,order_id,package_description',
            'items:id,order_id,menu_id,pickup_location_id,item_source,menu_name,quantity,unit_price,subtotal,notes,metadata,is_available',
            'orderLocations:id,order_id,restaurant_id,location_role,label,full_address,latitude,longitude,sequence_no,fulfillment_status,failed_attempt_count',
            'orderLocations.restaurant:id,name,address,latitude,longitude,phone,merchant_type',
            'payment:id,order_id,payment_method,payment_status,amount,recorded_by_user_id,driver_id,paid_at',
            'evidences:id,order_id,user_id,evidence_type,file_url,uploaded_at,notes,created_at',
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
        $serviceCode = ServiceTypeCode::normalize((string) ($order->serviceType->code ?? ''));
        $statusCode = strtoupper((string) ($order->statusRef->code ?? ''));
        $paymentStatus = strtolower((string) ($order->payment_status ?? 'unpaid'));
        $paymentMethod = PaymentMethod::normalize((string) ($order->payment_method ?? PaymentMethod::Cod->value));

        $pickup = $this->pickupPointResolver->resolve($order);
        $dropoff = $this->resolveDropoffPoint($order);
        $proofs = $this->serializeProofs($order);
        $paymentProofFeedback = app(AdminPaymentProofStatusService::class)->feedbackForOrder($order);
        $proofStatus = $this->proofStatus($proofs);
        $hasDriverShoppingTotal = $serviceCode === 'SHOPPING' && $this->shoppingPricingService->hasShoppingReceipt($order);
        $hasPendingShoppingPrices = $serviceCode === 'SHOPPING' && $this->hasPendingManualShoppingPrices($order);
        $shoppingNegotiation = $serviceCode === 'SHOPPING'
            ? $order->shopping_negotiation
            : null;
        $shoppingQuoteApproved = is_array($shoppingNegotiation)
            && (bool) ($shoppingNegotiation['checkout_allowed'] ?? false);
        $deliveryFeeNegotiation = $this->deliveryFeeNegotiationService->snapshot($order);
        $hasPendingDeliveryFeeNegotiation = is_array($deliveryFeeNegotiation)
            && (bool) ($deliveryFeeNegotiation['is_pending'] ?? false);
        $canCancelShoppingWithFee = $serviceCode === 'SHOPPING'
            && $this->shoppingPricingService->isCancellationPenaltyEligible($order);
        $availableActions = $this->resolveAvailableDriverActions(
            $serviceCode,
            $statusCode,
            $paymentStatus,
            $paymentMethod,
            $hasPendingShoppingPrices,
            $hasDriverShoppingTotal,
            $shoppingQuoteApproved,
            $canCancelShoppingWithFee,
            $hasPendingDeliveryFeeNegotiation,
            $proofStatus,
        );

        $acceptedAt = $order->assigned_at;
        if ($acceptedAt === null) {
            $acceptedAt = $order->statusHistories
                ->first(fn (OrderStatusHistory $history): bool => strtoupper((string) ($history->statusRef->code ?? '')) === 'DRIVER_ASSIGNED')
                ?->created_at;
        }

        $itemCount = (int) $order->items->sum('quantity');
        if ($itemCount < 1) {
            $itemCount = 1;
        }

        $pricingSnapshot = $this->pricingSnapshot($order);
        $deliveryFee = round((float) $order->delivery_fee, 2);
        $driverFee = $serviceCode === ServiceTypeCode::Shopping->value && $statusCode === 'CANCELLED_WITH_FEE'
            ? $this->shoppingPricingService->cancellationDriverFeeAmount($order)
            : $deliveryFee;
        $incomeBreakdown = $this->driverIncomeFeeCalculator->breakdown($driverFee);

        $payload = [
            'id' => (string) $order->id,
            'order_number' => $order->order_number,
            'service_type_code' => $serviceCode,
            'service_type_name' => $order->serviceType?->display_name,
            'customer_name' => $order->user->name ?? '-',
            'customer_phone' => $order->user?->phone,
            'customer_avatar_url' => $this->resolveAvatarUrl($order->user?->avatar),
            'pickup_address' => $pickup['address'],
            'pickup_latitude' => $pickup['latitude'],
            'pickup_longitude' => $pickup['longitude'],
            'dropoff_address' => $dropoff['address'],
            'dropoff_latitude' => $dropoff['latitude'],
            'dropoff_longitude' => $dropoff['longitude'],
            'fee' => (int) round($driverFee),
            'driver_income_gross' => $incomeBreakdown['gross_income'],
            'driver_admin_fee_percent' => $incomeBreakdown['admin_fee_percent'],
            'driver_admin_fee' => $incomeBreakdown['admin_fee'],
            'driver_income_net' => $incomeBreakdown['net_income'],
            'delivery_distance_km' => $order->delivery_distance_km !== null ? round((float) $order->delivery_distance_km, 2) : null,
            'delivery_distance_text' => $order->delivery_distance_text,
            'delivery_fee' => $deliveryFee,
            'delivery_fee_source' => $order->delivery_fee_source ?: 'system',
            'pricing_snapshot' => $pricingSnapshot,
            'fee_breakdown' => $this->feeBreakdown($order, $pricingSnapshot),
            'proofs' => $proofs,
            'payment_proof_feedback' => $paymentProofFeedback,
            'total_price' => round((float) $order->total_price, 2),
            'item_count' => $itemCount,
            'eta_minutes' => 0,
            'accepted_at' => $acceptedAt?->format('H:i'),
            'status_code' => $statusCode,
            'status_display_name' => $order->statusRef?->display_name,
            'payment_status' => $paymentStatus,
            'payment_method' => $order->payment_method,
            'available_actions' => $availableActions,
            'route' => $this->orderRouteSnapshot($order),
            'delivery_fee_negotiation' => $deliveryFeeNegotiation,
        ];

        if ($serviceCode === ServiceTypeCode::Courier->value && $order->courierOrder !== null) {
            $payload['package_description'] = $order->courierOrder->package_description;
        }

        if ($serviceCode === ServiceTypeCode::Shopping->value) {
            $shoppingCapabilities = $this->shoppingOrderCapabilityService->capabilities($order);
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
            $payload['shopping_negotiation'] = $shoppingNegotiation;
            $payload['shopping_item_change_request'] = $this->shoppingItemChangeRequestService->snapshot($order);
            $payload['shopping_capabilities'] = $shoppingCapabilities;
            $payload['pricing'] = [
                'subtotal' => round((float) $order->subtotal, 2),
                'delivery_fee' => round((float) $order->delivery_fee, 2),
                'service_fee' => round((float) $order->service_fee, 2),
                'total_price' => round((float) $order->total_price, 2),
                'item_surcharge' => 0.0,
                'overweight_surcharge' => 0.0,
                'cancellation_penalty' => $this->shoppingPricingService->feeLineAmount($order, 'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS'),
                'cancellation_penalty_base_delivery_fee' => $this->shoppingPricingService->cancellationPenaltyBaseAmount($order),
                'cancellation_penalty_percent' => $this->shoppingPricingService->cancellationPenaltyPercent(),
                'failed_trip_compensation' => $this->shoppingPricingService->feeLineAmount($order, 'FAILED_TRIP_COMPENSATION'),
                'recalculation_version' => $this->shoppingPricingService->latestRecalculationVersion($order),
                'has_pending_manual_prices' => $hasPendingShoppingPrices,
                'failed_attempt_count' => $this->shoppingPricingService->failedAttemptCount($order),
                'failed_attempt_threshold' => $this->shoppingPricingService->cancellationFailedAttemptThreshold(
                    (int) $order->service_type_id
                ),
                'can_cancel_with_fee' => $canCancelShoppingWithFee,
                'fee_breakdown' => $this->shoppingPricingService->feeBreakdownForOrder($order),
            ];
            $payload['has_pending_shopping_prices'] = $hasPendingShoppingPrices;
        }

        if ($includeTimeline) {
            $payload['status_timeline'] = $this->serializeStatusTimeline($order);
        }

        return $payload;
    }

    private function resolveAvatarUrl(?string $avatar): ?string
    {
        $avatar = trim((string) ($avatar ?? ''));
        if ($avatar === '') {
            return null;
        }

        if (str_starts_with($avatar, 'http://') || str_starts_with($avatar, 'https://')) {
            return $avatar;
        }

        $path = ltrim($avatar, '/');
        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function driverActionRules(string $serviceCode): array
    {
        return match (strtoupper($serviceCode)) {
            ServiceTypeCode::Ride->value => $this->rideActionRules(),
            ServiceTypeCode::Shopping->value => $this->shoppingActionRules(),
            default => $this->courierActionRules(),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeProofs(Order $order): array
    {
        $order->loadMissing('evidences', 'payment');
        $proofStatuses = app(AdminPaymentProofStatusService::class);
        $logs = $proofStatuses->decisionLogsForOrder($order);
        $payment = $order->payment;

        return $order->evidences
            ->sortByDesc(fn ($evidence): int => $evidence->uploaded_at?->getTimestamp() ?? $evidence->created_at?->getTimestamp() ?? 0)
            ->map(function ($evidence) use ($proofStatuses, $logs, $payment): array {
                $type = $this->canonicalProofType((string) $evidence->evidence_type);
                $decision = strtoupper((string) $evidence->evidence_type) === AdminPaymentProofStatusService::PAYMENT_TRANSFER_EVIDENCE_TYPE
                    ? $proofStatuses->decisionFor($evidence, $payment, $logs)
                    : null;

                return [
                    'id' => (int) $evidence->id,
                    'type' => $type,
                    'evidence_type' => strtoupper((string) $evidence->evidence_type),
                    'photo_url' => $evidence->file_url,
                    'file_url' => $evidence->file_url,
                    'status' => $decision['status'] ?? 'pending',
                    'verification_status' => $decision['status'] ?? 'pending',
                    'rejection_reason' => $decision['reason'] ?? null,
                    'decision_note' => $decision['note'] ?? null,
                    'decided_by' => $decision['decided_by'] ?? null,
                    'decided_at' => $decision['decided_at'] ?? null,
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
        return ProofType::fromEvidenceType($evidenceType);
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
            'route' => $route,
            'shopping_pricing' => [
                'receipt_total_amount' => $order->shoppingReceipt?->total_amount !== null
                    ? round((float) $order->shoppingReceipt->total_amount, 2)
                    : null,
                'fee_breakdown' => $this->shoppingPricingService->feeBreakdownForOrder($order),
            ],
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

        foreach ($this->shoppingPricingService->feeBreakdownForOrder($order) as $feeLine) {
            $breakdown[] = $feeLine;
        }

        if (($order->delivery_fee_source ?: 'system') === 'driver_manual') {
            $breakdown[] = [
                'code' => 'manual_override',
                'label' => 'Ongkir manual driver',
                'amount' => round((float) $order->delivery_fee, 2),
            ];
        }

        return array_values($breakdown);
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
        bool $shoppingQuoteApproved = false,
        bool $canCancelShoppingWithFee = false,
        bool $hasPendingDeliveryFeeNegotiation = false,
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

            if (
                $serviceCode === 'SHOPPING' &&
                $statusCode === 'CANCELLED_WITH_FEE' &&
                $requiresPaid &&
                $paymentStatus !== 'paid'
            ) {
                continue;
            }

            $blockedReasons = [];
            if ($requiresPaid && $paymentStatus !== 'paid') {
                $blockedReasons[] = 'Pembayaran belum dicatat.';
            }

            if (
                $this->supportsDriverProofType($serviceCode, 'pickup') &&
                in_array($actionCode, [DriverActionCode::BoardPassenger->value, DriverActionCode::ConfirmPickedUp->value], true) &&
                ! (bool) ($proofStatus['pickup'] ?? false)
            ) {
                $blockedReasons[] = 'Bukti foto pickup belum diupload.';
            }

            if (
                $serviceCode === 'SHOPPING' &&
                $actionCode === DriverActionCode::ConfirmPickedUp->value &&
                ! $shoppingQuoteApproved
            ) {
                $blockedReasons[] = 'Harga Nitip belum disetujui customer.';
            }

            if (
                $hasPendingDeliveryFeeNegotiation &&
                $this->deliveryFeeNegotiationService->blocksDriverProgressFor($serviceCode, $actionCode)
            ) {
                $blockedReasons[] = 'Revisi ongkir belum disetujui customer.';
            }

            if (
                $serviceCode === 'SHOPPING' &&
                $actionCode === DriverActionCode::ConfirmPickedUp->value &&
                ! $hasDriverShoppingTotal
            ) {
                $blockedReasons[] = 'Checkout Nitip belum disimpan.';
            }

            if (
                $this->supportsDriverProofType($serviceCode, 'delivery') &&
                $actionCode === DriverActionCode::CompleteOrder->value &&
                ! (bool) ($proofStatus['delivery'] ?? false)
            ) {
                $blockedReasons[] = 'Bukti foto selesai pengantaran belum diupload.';
            }

            if (
                $serviceCode === 'SHOPPING' &&
                $actionCode === DriverActionCode::ConfirmPickedUp->value &&
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
        return $this->proofPolicyService->supportsDriverProofType($serviceCode, $type);
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
     * SHOPPING - Nitip (5 active steps).
     * Uses ARRIVED_MERCHANT instead of ARRIVED_PICKUP for the first arrival.
     *
     * @return array<string, array<string, mixed>>
     */
    private function shoppingActionRules(): array
    {
        return [
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
                'from' => ['DELIVERED', 'CANCELLED_WITH_FEE'],
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
        $availabilityConfirmedPickupIds = $this->availabilityConfirmedPickupIds($order);
        $pickups = $order->orderLocations
            ->filter(fn ($location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
            ->values();

        if ($pickups->isEmpty()) {
            return [];
        }

        $firstPickupId = (int) $pickups->first()->id;

        return $pickups
            ->map(function ($pickup) use ($order, $firstPickupId, $availabilityConfirmedPickupIds): array {
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
                $unavailableItemActions = $this->shoppingUnavailableItemDecisionService
                    ->actionsForPickup($order, $pickupId);

                return [
                    'pickup_location_id' => $pickupId,
                    'sequence_no' => (int) $pickup->sequence_no,
                    'fulfillment_status' => strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING')),
                    'failed_attempt_count' => (int) ($pickup->failed_attempt_count ?? 0),
                    'chain_id' => $unavailableItemActions['chain_id'] ?? 'pickup:'.$pickupId,
                    'chain_attempt_no' => (int) ($unavailableItemActions['chain_attempt_no'] ?? 1),
                    'chain_failed_attempt_count' => (int) ($unavailableItemActions['chain_failed_attempt_count'] ?? 0),
                    'chain_failed_attempt_limit' => (int) ($unavailableItemActions['chain_failed_attempt_limit'] ?? 3),
                    'order_failed_trip_count' => (int) ($unavailableItemActions['order_failed_trip_count'] ?? 0),
                    'verified_failed_trip_count' => (int) ($unavailableItemActions['verified_failed_trip_count'] ?? 0),
                    'compensation_eligible' => (bool) ($unavailableItemActions['compensation_eligible'] ?? false),
                    'state_version' => (int) ($unavailableItemActions['state_version'] ?? 0),
                    'availability_confirmed' => isset($availabilityConfirmedPickupIds[$pickupId]),
                    'unavailable_item_actions' => $unavailableItemActions,
                    'merchant' => [
                        'id' => $restaurantId,
                        'name' => $pickup->restaurant?->name ?? $pickup->label,
                        'merchant_type' => $pickup->restaurant?->merchant_type,
                        'address' => $pickup->full_address,
                        'phone' => $pickup->restaurant?->phone,
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
     * @return array<int, true>
     */
    private function availabilityConfirmedPickupIds(Order $order): array
    {
        return OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'SHOPPING_ITEM_AVAILABILITY')
            ->where('trigger_type', 'DRIVER_CONFIRMED_ITEM_AVAILABILITY')
            ->get()
            ->reduce(function (array $carry, OrderLog $event): array {
                $pickupLocationId = data_get($event->metadata, 'pickup_location_id');
                if (is_numeric($pickupLocationId) && (int) $pickupLocationId > 0) {
                    $carry[(int) $pickupLocationId] = true;
                }

                return $carry;
            }, []);
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

        return null;
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
