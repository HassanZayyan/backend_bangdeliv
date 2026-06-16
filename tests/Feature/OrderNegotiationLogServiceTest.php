<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Order\OrderNegotiationLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderNegotiationLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_and_latest_use_event_type_contract(): void
    {
        $service = app(OrderNegotiationLogService::class);
        $order = $this->createOrder();
        $actor = User::factory()->create(['role' => 'driver']);

        $first = $service->record(
            'DELIVERY_FEE_NEGOTIATION',
            $order,
            'DRIVER_FEE_QUOTED',
            $actor->id,
            'Ongkir berubah.',
            ['quoted_amount' => '12000']
        );
        $latest = $service->record(
            'DELIVERY_FEE_NEGOTIATION',
            $order,
            'CUSTOMER_FEE_COUNTERED',
            $actor->id,
            null,
            ['counter_amount' => 10000]
        );

        $this->assertSame($first->event_type, $first->log_type);
        $this->assertDatabaseHas('order_events', [
            'id' => $first->id,
            'event_type' => 'DELIVERY_FEE_NEGOTIATION',
            'trigger_type' => 'DRIVER_FEE_QUOTED',
            'changed_by_user_id' => $actor->id,
        ]);

        $this->assertSame($latest->id, $service->latest('DELIVERY_FEE_NEGOTIATION', $order)?->id);
        $this->assertSame($latest->id, $service->latest('DELIVERY_FEE_NEGOTIATION', (int) $order->id)?->id);
    }

    public function test_value_parsers_normalize_metadata_primitives(): void
    {
        $service = app(OrderNegotiationLogService::class);

        $this->assertSame(12, $service->intOrNull('12'));
        $this->assertSame(15000.25, $service->floatOrNull('15000.254'));
        $this->assertTrue($service->boolOrNull('true'));
        $this->assertFalse($service->boolOrNull('0'));
        $this->assertNull($service->intOrNull(''));
        $this->assertNull($service->floatOrNull(null));
    }

    private function createOrder(): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $serviceTypeId = (int) ServiceType::query()->where('code', 'RIDE')->value('id');
        $statusId = (int) OrderStatus::query()->where('code', 'DRIVER_ASSIGNED')->value('id');

        return Order::query()->create([
            'order_number' => 'BD-NEG-'.strtoupper(substr(md5((string) random_int(1, 9999)), 0, 8)),
            'user_id' => $customer->id,
            'service_type_id' => $serviceTypeId,
            'subtotal' => 0,
            'delivery_fee' => 9000,
            'service_fee' => 0,
            'total_price' => 9000,
            'status_id' => $statusId,
        ]);
    }
}
