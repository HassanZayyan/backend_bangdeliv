<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mengunci struktur key payload detail order customer & driver.
 * Refactor internal OrderService tidak boleh mengubah bentuk respons
 * yang dibaca aplikasi Flutter.
 */
class OrderPayloadShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_driver_order_detail_payload_shapes_are_stable(): void
    {
        $driverUser = User::query()->create([
            'name' => 'Driver Shape',
            'email' => 'driver.shape@example.com',
            'phone' => '081211118881',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 1111 SHP',
            'registration_status' => 'active',
            'status' => 'available',
        ]));

        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $shoppingTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');
        $pendingStatusId = (int) OrderStatus::query()->where('code', 'PENDING')->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-SHAPE-0001',
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $shoppingTypeId,
            'driver_id' => null,
            'address_id' => null,
            'delivery_address' => 'Jl. Merdeka No. 7, Semarang',
            'delivery_latitude' => -7.005145,
            'delivery_longitude' => 110.438125,
            'subtotal' => 10000,
            'delivery_fee' => 7000,
            'service_fee' => 0,
            'total_amount' => 17000,
            'total_price' => 17000,
            'status_id' => $pendingStatusId,
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);

        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/driver/orders/'.$order->id.'/accept')->assertOk();

        $driverDetail = $this->getJson('/api/v1/driver/orders/'.$order->id);
        $driverDetail->assertOk();
        $driverKeys = array_keys((array) $driverDetail->json('data'));

        Sanctum::actingAs($customer);
        $customerDetail = $this->getJson('/api/v1/orders/'.$order->id);
        $customerDetail->assertOk();
        $customerKeys = array_keys((array) $customerDetail->json('data'));

        $this->assertEqualsCanonicalizing(
            [
                'assigned_at',
                'cancellation_reason',
                'cancelled_at',
                'cancelled_by',
                'courier_order',
                'created_at',
                'delivered_at',
                'delivery_address',
                'delivery_distance_km',
                'delivery_distance_text',
                'delivery_fee',
                'delivery_fee_change_note',
                'delivery_fee_negotiation',
                'delivery_fee_source',
                'delivery_latitude',
                'delivery_longitude',
                'driver',
                'driver_avatar_url',
                'driver_eta',
                'driver_id',
                'evidences',
                'fee_breakdown',
                'id',
                'items',
                'order_locations',
                'order_number',
                'paid_amount',
                'paid_at',
                'paid_by_user_id',
                'payment_method',
                'payment_proof_feedback',
                'payment_status',
                'payments',
                'pricing_snapshot',
                'proofs',
                'restaurant',
                'restaurant_id',
                'route',
                'service_fee',
                'service_type',
                'service_type_id',
                'shopping_capabilities',
                'shopping_item_change_request',
                'shopping_negotiation',
                'shopping_receipt',
                'shopping_route',
                'shopping_stops',
                'status_histories',
                'status_id',
                'status_ref',
                'subtotal',
                'total_price',
                'updated_at',
                'user_id',
                'was_cancelled_with_fee',
            ],
            $customerKeys,
            'CUSTOMER payload keys berubah.'
        );

        $this->assertEqualsCanonicalizing(
            [
                'accepted_at',
                'available_actions',
                'customer_avatar_url',
                'customer_name',
                'customer_phone',
                'delivery_distance_km',
                'delivery_distance_text',
                'delivery_fee',
                'delivery_fee_negotiation',
                'delivery_fee_source',
                'driver_admin_fee',
                'driver_admin_fee_percent',
                'driver_income_gross',
                'driver_income_net',
                'dropoff_address',
                'dropoff_latitude',
                'dropoff_longitude',
                'eta_minutes',
                'fee',
                'fee_breakdown',
                'has_pending_shopping_prices',
                'id',
                'item_count',
                'merchant',
                'order_number',
                'payment_method',
                'payment_proof_feedback',
                'payment_status',
                'pickup_address',
                'pickup_latitude',
                'pickup_longitude',
                'pricing',
                'pricing_snapshot',
                'proofs',
                'route',
                'service_type_code',
                'service_type_name',
                'shopping_capabilities',
                'shopping_item_change_request',
                'shopping_items',
                'shopping_negotiation',
                'shopping_route',
                'shopping_stops',
                'status_code',
                'status_display_name',
                'status_timeline',
                'total_price',
            ],
            $driverKeys,
            'DRIVER payload keys berubah.'
        );
    }
}
