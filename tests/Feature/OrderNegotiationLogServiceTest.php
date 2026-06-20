<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Order\OrderNegotiationLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    public function test_order_events_schema_and_model_defaults_keep_optional_fields_non_null(): void
    {
        $this->assertTrue(Schema::hasColumn('order_events', 'trigger_type'));
        $this->assertTrue(Schema::hasColumn('order_events', 'note'));
        $this->assertTrue(Schema::hasColumn('order_events', 'metadata'));
        $this->assertFalse(Schema::hasColumn('order_locations', 'contact_name'));
        $this->assertFalse(Schema::hasColumn('order_locations', 'contact_phone'));

        $order = $this->createOrder();

        $event = OrderLog::query()->create([
            'order_id' => $order->id,
        ])->refresh();

        $this->assertSame('SYSTEM_EVENT', $event->event_type);
        $this->assertSame('SYSTEM_EVENT', $event->trigger_type);
        $this->assertSame('', $event->note);
        $this->assertSame([], $event->metadata);
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
