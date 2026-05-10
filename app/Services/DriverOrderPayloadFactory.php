<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Database\Eloquent\Relations\Relation;

class DriverOrderPayloadFactory
{
    /**
     * @return array<int|string, mixed>
     */
    public function relations(): array
    {
        return [
            'user:id,name,phone',
            'serviceType:id,code,display_name',
            'statusRef:id,code,display_name',
            'restaurant:id,name,address,latitude,longitude',
            'rideOrder:id,order_id,picked_up_at,arrived_at',
            'courierOrder:id,order_id,package_description,estimated_weight_kg,package_length_cm,package_width_cm,package_height_cm,package_size_class,package_safety_status,package_safety_flags,package_safety_reason,package_packing_note,requires_photo_evidence',
            'items:id,order_id,quantity',
            'orderLocations:id,order_id,location_role,full_address,latitude,longitude,sequence_no',
            'payments:id,order_id,payment_method,payment_status,amount,recorded_by_user_id,driver_id,paid_at',
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

        $pickup = $this->resolvePickupPoint($order, $serviceCode);
        $dropoff = $this->resolveDropoffPoint($order);
        $availableActions = $this->resolveAvailableDriverActions(
            $serviceCode,
            $statusCode,
            $paymentStatus,
        );

        $acceptedAt = $order->statusHistories
            ->first(fn (OrderStatusHistory $history): bool => strtoupper((string) ($history->statusRef->code ?? '')) === 'DRIVER_ASSIGNED');

        $itemCount = (int) $order->items->sum('quantity');
        if ($itemCount < 1) {
            $itemCount = 1;
        }

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
            'fee' => (int) round((float) $order->delivery_fee),
            'total_price' => round((float) $order->total_price, 2),
            'item_count' => $itemCount,
            'eta_minutes' => $this->estimateEtaMinutes($order),
            'accepted_at' => $acceptedAt?->created_at?->format('H:i'),
            'status_code' => $statusCode,
            'status_display_name' => $order->statusRef?->display_name,
            'payment_status' => $paymentStatus,
            'payment_method' => $order->payment_method,
            'available_actions' => $availableActions,
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
            return [
                'address' => $order->restaurant->address ?? '-',
                'latitude' => $this->toFloatOrNull($order->restaurant?->latitude),
                'longitude' => $this->toFloatOrNull($order->restaurant?->longitude),
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

            $blocked = $requiresPaid && $paymentStatus !== 'paid';

            $actions[] = [
                'action_code' => $actionCode,
                'label' => $rule['label'],
                'target_status_code' => $rule['to'],
                'blocked' => $blocked,
                'blocked_reason' => $blocked
                    ? 'Pembayaran COD belum dicatat.'
                    : null,
            ];
        }

        $shouldCollectCourierAtPickup = $serviceCode === 'COURIER' &&
            $statusCode === 'ARRIVED_PICKUP' &&
            $paymentStatus !== 'paid';
        $shouldCollectAtDelivered = $serviceCode !== 'COURIER' &&
            $statusCode === 'DELIVERED' &&
            $paymentStatus !== 'paid';

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
        ];
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
