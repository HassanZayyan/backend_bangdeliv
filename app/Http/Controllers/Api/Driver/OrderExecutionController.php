<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Events\DriverLocationUpdated;
use App\Exceptions\ApiException;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class OrderExecutionController extends Controller
{
    /**
     * Update the active order status.
     */
    public function updateStatus(Request $request, OrderService $orderService, $orderId)
    {
        $request->validate([
            'status_code' => 'required|string|exists:order_statuses,code'
        ]);

        $order = Order::where('id', $orderId)
            ->whereHas('driver', function ($q) {
                $q->where('user_id', Auth::id());
            })
            ->firstOrFail();

        $statusCode = strtoupper($request->status_code);
        
        // Legacy status_code → action_code mapping (backward-compat adapter).
        // Note: ON_THE_WAY maps to BOARD_PASSENGER for RIDE (resolved by service below),
        // or START_DELIVERY for COURIER/SHOPPING. The action engine will reject invalid combos.
        $actionCode = match ($statusCode) {
            'ARRIVED_MERCHANT'              => 'ARRIVE_PICKUP',   // SHOPPING
            'ARRIVED_PICKUP'               => 'ARRIVE_PICKUP',   // RIDE / COURIER
            'PICKED_UP'                    => 'CONFIRM_PICKED_UP',
            'ON_THE_WAY'                   => 'START_DELIVERY',  // COURIER / SHOPPING
            'ARRIVED_DROPOFF'              => 'ARRIVE_DROPOFF',
            'DELIVERED'                    => 'CONFIRM_DELIVERED',
            'COMPLETED'                    => 'COMPLETE_ORDER',
            default                        => null,
        };

        if (!$actionCode) {
            return response()->json([
                'message' => 'Transisi status tidak didukung melalui endpoint legacy.'
            ], 422);
        }

        try {
            $updatedOrder = $orderService->transitionStatusByDriver(
                Auth::user(),
                $order->id,
                $actionCode,
                $statusCode
            );

            return response()->json([
                'message' => 'Status updated successfully.',
                'data' => [
                    'status_code' => $updatedOrder['status_code'] ?? $statusCode,
                    'display_name' => $updatedOrder['status_display_name'] ?? $statusCode
                ]
            ]);
        } catch (ApiException $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], $e->status());
        }
    }

    /**
     * Broadcast driver location via websocket (Fire-and-forget & Cache).
     */
    public function updateLocation(Request $request, $orderId)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'heading' => 'nullable|numeric'
        ]);

        $order = Order::where('id', $orderId)
            ->with(['serviceType', 'statusRef'])
            ->whereHas('driver', function ($q) {
                $q->where('user_id', Auth::id());
            })
            ->firstOrFail();

        $serviceCode = strtoupper((string) ($order->serviceType?->code ?? ''));
        $currentStatusCode = strtoupper((string) ($order->statusRef?->code ?? ''));
        $allowedStatusCodes = $serviceCode === 'RIDE'
            ? ['DRIVER_ASSIGNED', 'ARRIVED_PICKUP', 'ON_THE_WAY']
            : ['ON_THE_WAY'];

        if (!in_array($currentStatusCode, $allowedStatusCodes, true)) {
            return response()->json([
                'message' => 'Location tracking belum tersedia pada status order saat ini.'
            ], 403);
        }

        $lat = $request->input('latitude');
        $lng = $request->input('longitude');
        $heading = $request->input('heading', 0);

        if ($order->driver) {
            $order->driver->update([
                'current_latitude' => $lat,
                'current_longitude' => $lng,
            ]);
        }

        // 1. Cache latest location for redundancy (in case websocket disconnects)
        $cacheKey = 'driver_location:' . $order->driver_id;
        Cache::put($cacheKey, [
            'order_id' => $order->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'heading' => $heading,
            'updated_at' => now()->toIso8601String()
        ], now()->addHours(2));

        // 2. Broadcast to connected Customer
        broadcast(new DriverLocationUpdated($order->id, $lat, $lng, $heading));

        return response()->json([
            'message' => 'Location broadcasted successfully.'
        ]);
    }
}
