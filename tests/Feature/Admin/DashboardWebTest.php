<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_dashboard_after_order_schema_simplification(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081399990001',
        ]);
        $customer = User::factory()->create([
            'role' => 'customer',
            'phone' => '081399990002',
        ]);

        Order::query()->create([
            'order_number' => 'BDR-TEST-DASH',
            'user_id' => $customer->id,
            'service_type_id' => ServiceType::query()->where('code', 'RIDE')->value('id'),
            'subtotal' => 0,
            'delivery_fee' => 25000,
            'service_fee' => 0,
            'total_price' => 25000,
            'status_id' => OrderStatus::query()->where('code', 'COMPLETED')->value('id'),
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('GMV Bulan Ini')
            ->assertSee('Rp 25.000');
    }
}
