<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_get_profile_with_stats_and_addresses(): void
    {
        $user = User::query()->create([
            'name' => 'Naufal',
            'email' => 'naufal@example.com',
            'phone' => '081234567111',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => 'Naufal',
            'phone' => '081234567111',
            'full_address' => 'Jl. Sudirman No. 1',
            'detail' => null,
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'is_default' => true,
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Resto Test',
            'slug' => 'resto-test',
            'description' => null,
            'address' => 'Jl. Test',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'phone' => '081234567890',
            'banner_image' => null,
            'status' => 'active',
            'avg_rating' => 4.50,
            'total_reviews' => 10,
            'estimated_prep_time' => 20,
        ]);

        $order = Order::query()->create([
            'order_number' => 'ORD-0001',
            'user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
            'driver_id' => null,
            'address_id' => $address->id,
            'delivery_address' => 'Jl. Sudirman No. 1',
            'delivery_latitude' => -6.20000000,
            'delivery_longitude' => 106.81666600,
            'subtotal' => 30000,
            'delivery_fee' => 5000,
            'delivery_distance_km' => 2.5,
            'delivery_distance_text' => '2.5 km',
            'total_amount' => 35000,
            'status' => 'completed',
            'payment_status' => 'paid',
            'cancellation_reason' => null,
            'cancelled_by' => null,
            'notes' => null,
            'estimated_delivery' => null,
            'delivered_at' => now(),
        ]);

        Review::query()->create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
            'driver_id' => null,
            'rating' => 5,
            'comment' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('data.name', 'Naufal')
            ->assertJsonPath('data.address_count', 1)
            ->assertJsonPath('data.addresses.0.label', 'Rumah')
            ->assertJsonPath('data.stats.total_orders', 1)
            ->assertJsonPath('data.stats.total_paid', 35000)
            ->assertJsonPath('data.stats.rating', 5);
    }

    public function test_authenticated_user_can_update_profile(): void
    {
        $user = User::query()->create([
            'name' => 'Lama',
            'email' => 'lama@example.com',
            'phone' => '081200000001',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user', [
            'name' => 'Baru',
            'phone' => '0812-0000-0002',
            'email' => 'baru@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Profil berhasil diperbarui.')
            ->assertJsonPath('data.name', 'Baru')
            ->assertJsonPath('data.phone', '081200000002')
            ->assertJsonPath('data.email', 'baru@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Baru',
            'phone' => '081200000002',
            'email' => 'baru@example.com',
        ]);
    }

    public function test_authenticated_user_can_change_password(): void
    {
        $user = User::query()->create([
            'name' => 'Ubah Password',
            'email' => 'ubah.password@example.com',
            'phone' => '081211110000',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user/password', [
            'current_password' => 'rahasia123',
            'new_password' => 'passwordBaru123',
            'new_password_confirmation' => 'passwordBaru123',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Password berhasil diperbarui.');

        $this->assertTrue(Hash::check('passwordBaru123', (string) $user->fresh()->password));
    }

    public function test_change_password_fails_when_current_password_is_wrong(): void
    {
        $user = User::query()->create([
            'name' => 'Validasi Password',
            'email' => 'validasi.password@example.com',
            'phone' => '081211110001',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user/password', [
            'current_password' => 'salah1234',
            'new_password' => 'passwordBaru123',
            'new_password_confirmation' => 'passwordBaru123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'Password saat ini tidak sesuai.');

        $this->assertTrue(Hash::check('rahasia123', (string) $user->fresh()->password));
    }

    public function test_authenticated_user_can_store_saved_address(): void
    {
        $user = User::query()->create([
            'name' => 'Alamat User',
            'email' => 'alamat@example.com',
            'phone' => '081233330000',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/user/addresses', [
            'label' => 'Kos',
            'recipient_name' => 'Alamat User',
            'phone' => '0812-3333-0000',
            'full_address' => 'Jl. Kenanga No. 7, Salatiga',
            'detail' => 'Pagar putih',
            'is_default' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Alamat berhasil disimpan.')
            ->assertJsonPath('data.label', 'Kos')
            ->assertJsonPath('data.phone', '081233330000')
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'label' => 'Kos',
            'phone' => '081233330000',
            'full_address' => 'Jl. Kenanga No. 7, Salatiga',
            'is_default' => true,
        ]);
    }

    public function test_authenticated_user_can_update_saved_address(): void
    {
        $user = User::query()->create([
            'name' => 'Edit Alamat',
            'email' => 'edit.alamat@example.com',
            'phone' => '081200099900',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => 'Edit Alamat',
            'phone' => '081200099900',
            'full_address' => 'Alamat Lama',
            'detail' => null,
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user/addresses/' . $address->id, [
            'label' => 'Kantor',
            'recipient_name' => 'Edit Alamat Baru',
            'phone' => '0812-0009-9901',
            'full_address' => 'Alamat Baru',
            'detail' => 'Belakang minimarket',
            'is_default' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Alamat berhasil diperbarui.')
            ->assertJsonPath('data.label', 'Kantor')
            ->assertJsonPath('data.phone', '081200099901')
            ->assertJsonPath('data.full_address', 'Alamat Baru')
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'label' => 'Kantor',
            'recipient_name' => 'Edit Alamat Baru',
            'phone' => '081200099901',
            'full_address' => 'Alamat Baru',
            'detail' => 'Belakang minimarket',
            'is_default' => true,
        ]);
    }

    public function test_authenticated_user_can_delete_saved_address(): void
    {
        $user = User::query()->create([
            'name' => 'Hapus Alamat',
            'email' => 'hapus.alamat@example.com',
            'phone' => '081233344455',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        $defaultAddress = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => 'Hapus Alamat',
            'phone' => '081233344455',
            'full_address' => 'Alamat Default',
            'detail' => null,
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'is_default' => true,
        ]);

        $otherAddress = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Kantor',
            'recipient_name' => 'Hapus Alamat',
            'phone' => '081233344455',
            'full_address' => 'Alamat Kedua',
            'detail' => null,
            'latitude' => -6.21000000,
            'longitude' => 106.82666600,
            'is_default' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/user/addresses/' . $defaultAddress->id);

        $response->assertOk()
            ->assertJsonPath('message', 'Alamat berhasil dihapus.');

        $this->assertDatabaseMissing('addresses', [
            'id' => $defaultAddress->id,
        ]);

        $this->assertDatabaseHas('addresses', [
            'id' => $otherAddress->id,
            'is_default' => true,
        ]);
    }
}
