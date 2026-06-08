<?php

namespace App\Http\Controllers\Api\Driver;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderExecutionController extends Controller
{
    /**
     * Update the active order status.
     */
    public function updateStatus(Request $request, OrderService $orderService, $orderId)
    {
        $request->validate([
            'status_code' => 'required|string|exists:order_statuses,code',
        ]);

        $order = Order::where('id', $orderId)
            ->with('serviceType')
            ->whereHas('driver', function ($q) {
                $q->where('user_id', Auth::id());
            })
            ->firstOrFail();

        $statusCode = strtoupper($request->status_code);
        $serviceCode = strtoupper((string) ($order->serviceType?->code ?? ''));

        // Legacy status_code -> action_code mapping (backward-compat adapter).
        // Note: ON_THE_WAY maps to BOARD_PASSENGER for RIDE (resolved by service below),
        // or START_DELIVERY for COURIER/SHOPPING. The action engine will reject invalid combos.
        $actionCode = match ($statusCode) {
            'ARRIVED_MERCHANT' => 'ARRIVE_PICKUP',   // SHOPPING
            'ARRIVED_PICKUP' => 'ARRIVE_PICKUP',   // RIDE / COURIER
            'PICKED_UP' => 'CONFIRM_PICKED_UP',
            'ON_THE_WAY' => $serviceCode === 'RIDE' ? 'BOARD_PASSENGER' : 'START_DELIVERY',
            'ARRIVED_DROPOFF' => 'ARRIVE_DROPOFF',
            'DELIVERED' => 'CONFIRM_DELIVERED',
            'COMPLETED' => 'COMPLETE_ORDER',
            default => null,
        };

        if (! $actionCode) {
            return response()->json([
                'message' => 'Transisi status tidak didukung melalui endpoint legacy.',
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
                    'display_name' => $updatedOrder['status_display_name'] ?? $statusCode,
                ],
            ]);
        } catch (ApiException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->status());
        }
    }

}
