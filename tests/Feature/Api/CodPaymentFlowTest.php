<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CodPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_record_cod_and_report_only_counts_paid_cod(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $driverUser = User::factory()->create([
            'name' => 'Driver Settlement',
            'role' => 'driver',
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 7777 COD',
            'registration_status' => 'active',
            'status' => 'busy',
        ]));

        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $rideTypeId = (int) ServiceType::query()->where('code', 'RIDE')->value('id');
        $deliveredStatusId = (int) OrderStatus::query()->where('code', 'DELIVERED')->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-ADM-COD-0001',
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $rideTypeId,
            'driver_id' => $driver->id,
            'address_id' => null,
            'subtotal' => 0,
            'delivery_fee' => 31000,
            'service_fee' => 0,
            'total_amount' => 31000,
            'total_price' => 31000,
            'status_id' => $deliveredStatusId,
        ]);

        OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => 31000,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/orders/'.$order->id.'/payment/record-cod', [
            'amount' => 31000,
            'note' => 'Dikoreksi admin.',
        ])->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PAID',
            'amount' => 31000,
            'recorded_by_user_id' => $admin->id,
            'driver_id' => $driver->id,
        ]);

        $this->getJson('/api/v1/admin/payments/cod-settlement')
            ->assertOk()
            ->assertJsonPath('data.summary.payment_count', 1)
            ->assertJsonPath('data.summary.total_collected', 31000)
            ->assertJsonPath('data.by_driver.0.driver_id', $driver->id)
            ->assertJsonPath('data.by_driver.0.payment_count', 1)
            ->assertJsonPath('data.by_driver.0.total_collected', 31000)
            ->assertJsonPath('data.payments.0.order_id', $order->id)
            ->assertJsonPath('data.payments.0.amount', 31000);
    }
}
