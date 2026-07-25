<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLocation;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Driver punya jalan keluar penuh untuk order Nitip selama belum ada toko/resto
 * yang dibeli -- kasus utamanya customer chat "tidak jadi". Aturan feenya sama
 * dengan pembatalan oleh customer: gratis sebelum kuota kegagalan tercapai,
 * berbiaya sesudahnya.
 */
class DriverShoppingOrderCancellationEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_cancels_for_free_before_any_store_is_purchased(): void
    {
        [$driverUser, $order] = $this->arrangeOrder();
        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/shopping/cancel')
            ->assertOk()
            ->assertJsonPath('data.status_code', 'CANCELLED');

        $order->refresh()->load('statusRef');
        $this->assertSame('CANCELLED', (string) $order->statusRef->code);
        $this->assertSame('driver', (string) $order->cancelled_by);
        // Gratis: tidak ada tagihan transfer yang dibuat untuk customer.
        $this->assertDatabaseMissing('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
        ]);
    }

    public function test_driver_cancel_charges_the_fee_once_the_failure_quota_is_reached(): void
    {
        [$driverUser, $order, $pickups] = $this->arrangeOrder();
        Sanctum::actingAs($driverUser);
        $this->failAllStores($order, $pickups);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/shopping/cancel')
            ->assertOk()
            ->assertJsonPath('data.status_code', 'CANCELLED_WITH_FEE');

        $order->refresh();
        $this->assertSame(5500.0, (float) $order->total_price);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
        ]);
    }

    public function test_driver_cannot_cancel_once_a_store_has_been_purchased(): void
    {
        [$driverUser, $order, $pickups] = $this->arrangeOrder();
        $pickups[0]->update(['fulfillment_status' => 'PRICE_APPROVED']);
        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/shopping/cancel')
            ->assertStatus(409);

        $this->assertSame(
            'ARRIVED_MERCHANT',
            (string) $order->refresh()->load('statusRef')->statusRef->code,
        );
    }

    public function test_cancel_action_is_hidden_once_a_store_has_been_purchased(): void
    {
        [$driverUser, $order, $pickups] = $this->arrangeOrder();
        Sanctum::actingAs($driverUser);

        $this->getJson('/api/v1/driver/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.shopping_capabilities.can_driver_cancel_shopping_order', true);

        $pickups[0]->update(['fulfillment_status' => 'PRICE_APPROVED']);

        $this->getJson('/api/v1/driver/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.shopping_capabilities.can_driver_cancel_shopping_order', false);
    }

    public function test_another_driver_cannot_cancel_the_order(): void
    {
        [, $order] = $this->arrangeOrder();
        $otherDriverUser = User::factory()->create(['role' => 'driver']);
        Driver::query()->create($this->driverAttributes([
            'user_id' => $otherDriverUser->id,
            'vehicle_plate' => 'H '.random_int(1000, 9999).' OTH',
            'registration_status' => 'active',
            'status' => 'available',
        ]));
        Sanctum::actingAs($otherDriverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/shopping/cancel')
            ->assertStatus(403);

        $this->assertSame(
            'ARRIVED_MERCHANT',
            (string) $order->refresh()->load('statusRef')->statusRef->code,
        );
    }

    /**
     * Gagalkan ketiga toko/resto dengan bukti foto sehingga kuota tiga tercapai.
     * Rute customer -> toko dipatok 3.000 m sehingga O(d_max) = 11.000 dan fee
     * 50%-nya 5.500.
     *
     * @param  array<int, OrderLocation>  $pickups
     */
    private function failAllStores(Order $order, array $pickups): void
    {
        Storage::fake('public');
        Config::set('bangdeliv.failed_trip.verification_radius_meters', 50000);
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        Http::fake([
            'https://routes.googleapis.com/directions/v2:computeRoutes*' => Http::response([
                'routes' => [[
                    'distanceMeters' => 3000,
                    'duration' => '300s',
                    'legs' => [['distanceMeters' => 3000, 'duration' => '300s']],
                ]],
            ]),
        ]);

        foreach ($pickups as $pickup) {
            $this->post('/api/v1/orders/'.$order->id.'/attempt-failed', [
                'failure_type' => 'PICKUP',
                'reason' => 'Tempat tutup saat driver tiba.',
                'pickup_location_id' => $pickup->id,
                'merchant_closed_photo' => UploadedFile::fake()->image('closed.jpg'),
            ])->assertOk();
        }
    }

    /**
     * @return array{0: User, 1: Order, 2: array<int, OrderLocation>}
     */
    private function arrangeOrder(): array
    {
        $driverUser = User::factory()->create(['role' => 'driver']);
        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'H '.random_int(1000, 9999).' CNL',
            'registration_status' => 'active',
            'status' => 'busy',
            'latitude' => -7.010,
            'longitude' => 110.410,
            'location_updated_at' => now(),
        ]));

        $order = Order::query()->create([
            'order_number' => 'BD-CNL-'.strtoupper(substr(md5((string) random_int(1, 999999)), 0, 8)),
            'user_id' => User::factory()->create(['role' => 'customer'])->id,
            'service_type_id' => (int) ServiceType::query()->where('code', 'SHOPPING')->value('id'),
            'driver_id' => $driver->id,
            'delivery_address' => 'rt 1 rw 3, Bejalen',
            'delivery_latitude' => -7.050,
            'delivery_longitude' => 110.450,
            'subtotal' => 0,
            'delivery_fee' => 11000,
            'service_fee' => 0,
            'total_amount' => 11000,
            'total_price' => 11000,
            'status_id' => (int) OrderStatus::query()->where('code', 'ARRIVED_MERCHANT')->value('id'),
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);

        $pickups = [];
        foreach ([['Toko A', -7.001], ['Toko B', -7.011], ['Toko C', -7.021]] as $index => [$label, $lat]) {
            $pickup = OrderLocation::query()->create([
                'order_id' => $order->id,
                'location_role' => 'PICKUP',
                'label' => $label,
                'full_address' => $label,
                'latitude' => $lat,
                'longitude' => 110.401,
                'sequence_no' => $index + 1,
                'fulfillment_status' => 'ITEMS_CONFIRMED',
            ]);
            OrderItem::query()->create([
                'order_id' => $order->id,
                'pickup_location_id' => $pickup->id,
                'item_source' => 'MANUAL',
                'menu_name' => 'Item '.$label,
                'quantity' => 1,
                'unit_price' => 10000,
                'subtotal' => 10000,
                'is_available' => true,
            ]);
            $pickups[] = $pickup;
        }

        OrderLocation::query()->create([
            'order_id' => $order->id,
            'location_role' => 'DROPOFF',
            'label' => 'Titik Antar',
            'full_address' => 'rt 1 rw 3, Bejalen',
            'latitude' => -7.050,
            'longitude' => 110.450,
            'sequence_no' => 99,
            'fulfillment_status' => 'PENDING',
        ]);

        return [$driverUser, $order, $pickups];
    }
}
