<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Restaurant;
use App\Models\Review;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bangdeliv.google_maps_api_key' => 'test-google-maps-key',
        ]);
    }

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

        $serviceType = ServiceType::query()->firstOrCreate(
            ['code' => 'SHOPPING'],
            [
                'display_name' => 'Shopping',
                'description' => 'Test service type',
                'sort_order' => 1,
            ]
        );

        $completedStatus = OrderStatus::query()->firstOrCreate(
            ['code' => 'COMPLETED'],
            [
                'display_name' => 'Completed',
                'is_terminal' => true,
                'sort_order' => 99,
            ]
        );

        $order = Order::query()->create([
            'order_number' => 'ORD-0001',
            'user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
            'service_type_id' => $serviceType->id,
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
            'total_price' => 35000,
            'status_id' => $completedStatus->id,
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

        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'formatted_address' => 'Jl. Kenanga No. 7, Salatiga, Jawa Tengah, Indonesia',
                        'geometry' => [
                            'location' => [
                                'lat' => -7.33165000,
                                'lng' => 110.49950000,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

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
            ->assertJsonPath('data.latitude', '-7.33165000')
            ->assertJsonPath('data.longitude', '110.49950000')
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'label' => 'Kos',
            'phone' => '081233330000',
            'full_address' => 'Jl. Kenanga No. 7, Salatiga, Jawa Tengah, Indonesia',
            'latitude' => -7.33165000,
            'longitude' => 110.49950000,
            'is_default' => true,
        ]);

        Http::assertSentCount(1);
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

        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'formatted_address' => 'Alamat Baru, Kota Semarang, Jawa Tengah, Indonesia',
                        'geometry' => [
                            'location' => [
                                'lat' => -6.97030000,
                                'lng' => 110.42570000,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

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
            ->assertJsonPath('data.full_address', 'Alamat Baru, Kota Semarang, Jawa Tengah, Indonesia')
            ->assertJsonPath('data.latitude', '-6.97030000')
            ->assertJsonPath('data.longitude', '110.42570000')
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'label' => 'Kantor',
            'recipient_name' => 'Edit Alamat Baru',
            'phone' => '081200099901',
            'full_address' => 'Alamat Baru, Kota Semarang, Jawa Tengah, Indonesia',
            'detail' => 'Belakang minimarket',
            'latitude' => -6.97030000,
            'longitude' => 110.42570000,
            'is_default' => true,
        ]);

        Http::assertSentCount(1);
    }

    public function test_authenticated_user_can_store_saved_address_with_payload_coordinates_when_geocoding_unavailable(): void
    {
        $user = User::query()->create([
            'name' => 'Alamat GPS',
            'email' => 'alamat.gps@example.com',
            'phone' => '081211223344',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/*' => Http::response([], 503),
        ]);

        $response = $this->postJson('/api/user/addresses', [
            'label' => 'Rumah GPS',
            'recipient_name' => 'Alamat GPS',
            'phone' => '0812-1122-3344',
            'full_address' => 'Perumahan Bukit Sari Blok A2',
            'detail' => 'Rumah cat putih',
            'latitude' => -7.76371000,
            'longitude' => 110.40642000,
            'is_default' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Alamat berhasil disimpan.')
            ->assertJsonPath('data.full_address', 'Perumahan Bukit Sari Blok A2')
            ->assertJsonPath('data.latitude', '-7.76371000')
            ->assertJsonPath('data.longitude', '110.40642000');

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'label' => 'Rumah GPS',
            'full_address' => 'Perumahan Bukit Sari Blok A2',
            'latitude' => -7.76371000,
            'longitude' => 110.40642000,
            'is_default' => true,
        ]);

        Http::assertSentCount(1);
    }

    public function test_authenticated_user_update_keeps_payload_coordinates_even_if_geocode_differs(): void
    {
        $user = User::query()->create([
            'name' => 'Update GPS',
            'email' => 'update.gps@example.com',
            'phone' => '081200011122',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => 'Update GPS',
            'phone' => '081200011122',
            'full_address' => 'Alamat Lama',
            'detail' => null,
            'latitude' => -6.90000000,
            'longitude' => 107.60000000,
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'formatted_address' => 'Alamat Baru Geocoded, Kota Bandung, Jawa Barat, Indonesia',
                        'geometry' => [
                            'location' => [
                                'lat' => -6.91234567,
                                'lng' => 107.61234567,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->putJson('/api/user/addresses/' . $address->id, [
            'label' => 'Rumah Baru',
            'recipient_name' => 'Update GPS',
            'phone' => '0812-0001-1122',
            'full_address' => 'Alamat Baru Input Pengguna',
            'detail' => 'Dekat masjid',
            'latitude' => -6.93456789,
            'longitude' => 107.65432109,
            'is_default' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Alamat berhasil diperbarui.')
            ->assertJsonPath('data.full_address', 'Alamat Baru Geocoded, Kota Bandung, Jawa Barat, Indonesia')
            ->assertJsonPath('data.latitude', '-6.93456789')
            ->assertJsonPath('data.longitude', '107.65432109');

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'label' => 'Rumah Baru',
            'full_address' => 'Alamat Baru Geocoded, Kota Bandung, Jawa Barat, Indonesia',
            'latitude' => -6.93456789,
            'longitude' => 107.65432109,
        ]);

        Http::assertSentCount(1);
    }

    public function test_authenticated_user_can_validate_saved_address(): void
    {
        $user = User::query()->create([
            'name' => 'Validasi Alamat',
            'email' => 'validasi.alamat@example.com',
            'phone' => '081233340000',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'formatted_address' => 'Jl. Sudirman No. 10, Jakarta, Indonesia',
                        'geometry' => [
                            'location' => [
                                'lat' => -6.21462000,
                                'lng' => 106.84513000,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/user/addresses/validate', [
            'full_address' => 'Jl. Sudirman No. 10, Jakarta',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Alamat valid.')
            ->assertJsonPath('data.formatted_address', 'Jl. Sudirman No. 10, Jakarta, Indonesia')
            ->assertJsonPath('data.latitude', -6.21462)
            ->assertJsonPath('data.longitude', 106.84513);

        Http::assertSentCount(1);
    }

    public function test_authenticated_user_cannot_validate_invalid_saved_address(): void
    {
        $user = User::query()->create([
            'name' => 'Validasi Invalid',
            'email' => 'validasi.invalid@example.com',
            'phone' => '081233340001',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/*' => Http::response([
                'status' => 'ZERO_RESULTS',
                'results' => [],
            ], 200),
        ]);

        $response = $this->postJson('/api/user/addresses/validate', [
            'full_address' => 'isekai',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Alamat tidak valid atau tidak ditemukan di peta.');

        Http::assertSentCount(1);
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
