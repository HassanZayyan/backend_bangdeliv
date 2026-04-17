<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RideOrderCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_ride_order_with_own_pickup_address(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081211110001',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Ride',
            'phone' => '081211110001',
            'full_address' => 'Jl. Mawar No. 1',
            'detail' => 'Lobi depan',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/orders/ride', [
            'address_id' => $address->id,
            'destination_address' => 'Jl. Sudirman No. 10, Jakarta',
            'notes' => 'Tolong jemput di lobi utama.',
        ]);

        $rideServiceTypeId = ServiceType::query()->where('code', 'RIDE')->value('id');
        $pendingStatusId = OrderStatus::query()->where('code', 'PENDING')->value('id');

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.address_id', $address->id)
            ->assertJsonPath('data.service_type_id', $rideServiceTypeId)
            ->assertJsonPath('data.status_id', $pendingStatusId)
            ->assertJsonPath('data.delivery_address', 'Jl. Sudirman No. 10, Jakarta');

        $orderId = (int) $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
            'address_id' => $address->id,
            'service_type_id' => $rideServiceTypeId,
            'status_id' => $pendingStatusId,
            'delivery_address' => 'Jl. Sudirman No. 10, Jakarta',
            'restaurant_id' => null,
            'payment_status' => 'unpaid',
        ]);

        $this->assertDatabaseHas('ride_orders', [
            'order_id' => $orderId,
            'notes' => 'Tolong jemput di lobi utama.',
        ]);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $orderId,
            'status_id' => $pendingStatusId,
            'event_type' => 'STATUS_CHANGE',
            'changed_by_user_id' => $user->id,
        ]);
    }

    public function test_customer_cannot_create_ride_order_with_other_user_address(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'phone' => '081211110002',
        ]);

        $otherUser = User::factory()->create([
            'role' => 'customer',
            'phone' => '081211110003',
        ]);

        $otherAddress = Address::query()->create([
            'user_id' => $otherUser->id,
            'label' => 'Rumah',
            'recipient_name' => 'Other User',
            'phone' => '081211110003',
            'full_address' => 'Jl. Melati No. 5',
            'detail' => null,
            'latitude' => -6.21000000,
            'longitude' => 106.82666600,
            'is_default' => true,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/ride', [
            'address_id' => $otherAddress->id,
            'destination_address' => 'Jl. Gatot Subroto No. 20, Jakarta',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Alamat jemput tidak ditemukan.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('ride_orders', 0);
    }

    public function test_create_ride_order_requires_destination_address(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081211110004',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Validasi',
            'phone' => '081211110004',
            'full_address' => 'Jl. Kenanga No. 8',
            'detail' => null,
            'latitude' => -6.22000000,
            'longitude' => 106.83666600,
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/orders/ride', [
            'address_id' => $address->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_address']);
    }
}
