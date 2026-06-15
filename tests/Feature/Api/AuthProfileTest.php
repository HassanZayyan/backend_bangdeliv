<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Restaurant;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
            'subtotal' => 30000,
            'delivery_fee' => 5000,
            'route_snapshot' => [
                'distance_meters' => 2500,
                'distance_km' => 2.5,
                'distance_text' => '2.5 km',
            ],
            'total_price' => 35000,
            'status_id' => $completedStatus->id,
            'cancellation_reason' => null,
            'cancelled_by' => null,
            'delivered_at' => now(),
        ]);

        $order->payments()->create([
            'payment_method' => 'COD',
            'payment_status' => 'PAID',
            'amount' => 35000,
            'recorded_by_user_id' => $user->id,
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('data.name', 'Naufal')
            ->assertJsonPath('data.address_count', 1)
            ->assertJsonPath('data.addresses.0.label', 'Rumah')
            ->assertJsonPath('data.stats.total_orders', 1)
            ->assertJsonPath('data.stats.total_paid', 35000);

        $this->assertArrayNotHasKey('rating', $response->json('data.stats'));
    }

    public function test_driver_profile_uses_driver_based_stats(): void
    {
        $driverUser = User::query()->create([
            'name' => 'Driver Satu',
            'email' => 'driver.satu@example.com',
            'phone' => '081211110010',
            'password' => Hash::make('rahasia123'),
            'role' => 'driver',
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 1234 XYZ',
            'license_number' => 'SIMC-8899123',
            'registration_status' => 'active',
            'status' => 'available',
        ]));

        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Resto Driver',
            'slug' => 'resto-driver',
            'description' => null,
            'address' => 'Jl. Driver No. 7',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'phone' => '081234567890',
            'banner_image' => null,
            'status' => 'active',
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

        $pendingStatus = OrderStatus::query()->firstOrCreate(
            ['code' => 'PENDING'],
            [
                'display_name' => 'Pending',
                'is_terminal' => false,
                'sort_order' => 1,
            ]
        );

        $completedOrderOne = Order::query()->create([
            'order_number' => 'ORD-DRV-0001',
            'user_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'service_type_id' => $serviceType->id,
            'driver_id' => $driver->id,
            'address_id' => null,
            'delivery_address' => 'Jl. Mawar No. 1',
            'delivery_latitude' => -6.20000000,
            'delivery_longitude' => 106.81666600,
            'subtotal' => 20000,
            'delivery_fee' => 12000,
            'total_amount' => 32000,
            'total_price' => 32000,
            'status_id' => $completedStatus->id,
            'payment_status' => 'paid',
            'payment_method' => 'COD',
            'delivered_at' => now(),
        ]);

        $completedOrderTwo = Order::query()->create([
            'order_number' => 'ORD-DRV-0002',
            'user_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'service_type_id' => $serviceType->id,
            'driver_id' => $driver->id,
            'address_id' => null,
            'delivery_address' => 'Jl. Melati No. 2',
            'delivery_latitude' => -6.21000000,
            'delivery_longitude' => 106.82666600,
            'subtotal' => 15000,
            'delivery_fee' => 8000,
            'total_amount' => 23000,
            'total_price' => 23000,
            'status_id' => $completedStatus->id,
            'payment_status' => 'paid',
            'payment_method' => 'COD',
            'delivered_at' => now(),
        ]);

        Order::query()->create([
            'order_number' => 'ORD-DRV-0003',
            'user_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'service_type_id' => $serviceType->id,
            'driver_id' => $driver->id,
            'address_id' => null,
            'delivery_address' => 'Jl. Anggrek No. 3',
            'delivery_latitude' => -6.22000000,
            'delivery_longitude' => 106.83666600,
            'subtotal' => 22000,
            'delivery_fee' => 7000,
            'total_amount' => 29000,
            'total_price' => 29000,
            'status_id' => $pendingStatus->id,
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);

        Order::query()->create([
            'order_number' => 'ORD-DRV-0004',
            'user_id' => $driverUser->id,
            'restaurant_id' => $restaurant->id,
            'service_type_id' => $serviceType->id,
            'driver_id' => null,
            'address_id' => null,
            'delivery_address' => 'Jl. Driver Order Customer',
            'delivery_latitude' => -6.23000000,
            'delivery_longitude' => 106.84666600,
            'subtotal' => 500000,
            'delivery_fee' => 15000,
            'total_amount' => 515000,
            'total_price' => 515000,
            'status_id' => $completedStatus->id,
            'payment_status' => 'paid',
            'payment_method' => 'COD',
            'delivered_at' => now(),
        ]);

        Sanctum::actingAs($driverUser);

        $response = $this->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('data.stats.total_orders', 2)
            ->assertJsonPath('data.stats.total_paid', 20000)
            ->assertJsonPath('data.driver_profile.vehicle_type', 'Motor Matic')
            ->assertJsonPath('data.driver_profile.vehicle_brand', 'Honda')
            ->assertJsonPath('data.driver_profile.vehicle_model', 'Beat')
            ->assertJsonPath('data.driver_profile.vehicle_plate', 'B 1234 XYZ')
            ->assertJsonPath('data.driver_profile.registration_status', 'active');

        $this->assertArrayNotHasKey('rating', $response->json('data.stats'));
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

    public function test_driver_can_update_required_vehicle_profile_fields(): void
    {
        $driverUser = User::query()->create([
            'name' => 'Driver Kendaraan Lama',
            'email' => 'driver.kendaraan.lama@example.com',
            'phone' => '081277770001',
            'password' => Hash::make('rahasia123'),
            'role' => 'driver',
        ]);

        Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 9091 OLD',
            'license_number' => 'SIMC-OLD-2026',
            'registration_status' => 'active',
            'status' => 'available',
        ]));

        Sanctum::actingAs($driverUser);

        $response = $this->putJson('/api/user', [
            'name' => 'Driver Kendaraan Baru',
            'phone' => '0812-7777-0002',
            'email' => 'driver.kendaraan.baru@example.com',
            'vehicle_type' => 'Motor Listrik',
            'vehicle_brand' => 'Yamaha',
            'vehicle_model' => 'E01',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.driver_profile.vehicle_type', 'Motor Listrik')
            ->assertJsonPath('data.driver_profile.vehicle_brand', 'Yamaha')
            ->assertJsonPath('data.driver_profile.vehicle_model', 'E01');

        $this->assertDatabaseHas('drivers', [
            'user_id' => $driverUser->id,
            'vehicle_type' => 'Motor Listrik',
            'vehicle_brand' => 'Yamaha',
            'vehicle_model' => 'E01',
            'vehicle_plate' => 'B 9091 OLD',
        ]);
    }

    public function test_authenticated_user_can_update_profile_with_avatar(): void
    {
        Storage::fake('public');

        $user = User::query()->create([
            'name' => 'Avatar Lama',
            'email' => 'avatar.lama@example.com',
            'phone' => '081255550001',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        Sanctum::actingAs($user);

        $avatar = UploadedFile::fake()->image('avatar-baru.jpg', 400, 400);

        $response = $this->post('/api/user', [
            '_method' => 'PUT',
            'name' => 'Avatar Baru',
            'phone' => '081255550002',
            'email' => 'avatar.baru@example.com',
            'avatar' => $avatar,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Profil berhasil diperbarui.')
            ->assertJsonPath('data.name', 'Avatar Baru');

        $refreshed = $user->fresh();

        $this->assertNotNull($refreshed?->avatar);
        Storage::disk('public')->assertExists((string) $refreshed?->avatar);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Avatar Baru',
            'phone' => '081255550002',
            'email' => 'avatar.baru@example.com',
        ]);
    }

    public function test_authenticated_user_can_remove_profile_avatar(): void
    {
        Storage::fake('public');

        $user = User::query()->create([
            'name' => 'Avatar Aktif',
            'email' => 'avatar.aktif@example.com',
            'phone' => '081255550101',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
            'avatar' => 'avatars/999/old-avatar.jpg',
        ]);

        Storage::disk('public')->put('avatars/999/old-avatar.jpg', 'old-avatar-file');

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user', [
            'name' => 'Avatar Dihapus',
            'phone' => '081255550102',
            'email' => 'avatar.hapus@example.com',
            'remove_avatar' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Profil berhasil diperbarui.')
            ->assertJsonPath('data.name', 'Avatar Dihapus')
            ->assertJsonPath('data.avatar_url', null);

        $refreshed = $user->fresh();
        $this->assertNull($refreshed?->avatar);
        Storage::disk('public')->assertMissing('avatars/999/old-avatar.jpg');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Avatar Dihapus',
            'phone' => '081255550102',
            'email' => 'avatar.hapus@example.com',
            'avatar' => null,
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
            'full_address' => 'Jl. Kenanga No. 7, Salatiga',
            'latitude' => -7.33165000,
            'longitude' => 110.49950000,
            'is_default' => true,
        ]);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return ($data['bounds'] ?? null) === '-7.650000,110.050000|-6.900000,110.800000'
                && ($data['components'] ?? null) === 'country:ID';
        });
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

        $response = $this->putJson('/api/user/addresses/'.$address->id, [
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
            ->assertJsonPath('data.latitude', '-6.97030000')
            ->assertJsonPath('data.longitude', '110.42570000')
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'label' => 'Kantor',
            'recipient_name' => 'Edit Alamat Baru',
            'phone' => '081200099901',
            'full_address' => 'Alamat Baru',
            'latitude' => -6.97030000,
            'longitude' => 110.42570000,
            'is_default' => true,
        ]);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return ($data['bounds'] ?? null) === '-7.650000,110.050000|-6.900000,110.800000'
                && ($data['components'] ?? null) === 'country:ID';
        });
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

        Http::assertSentCount(0);
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

        $response = $this->putJson('/api/user/addresses/'.$address->id, [
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
            ->assertJsonPath('data.full_address', 'Alamat Baru Input Pengguna')
            ->assertJsonPath('data.latitude', '-6.93456789')
            ->assertJsonPath('data.longitude', '107.65432109');

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'label' => 'Rumah Baru',
            'full_address' => 'Alamat Baru Input Pengguna',
            'latitude' => -6.93456789,
            'longitude' => 107.65432109,
        ]);

        Http::assertSentCount(0);
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
                        'formatted_address' => 'Jl. Sudirman No. 10, Salatiga, Jawa Tengah, Indonesia',
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

        $response = $this->postJson('/api/user/addresses/validate', [
            'full_address' => 'Jl. Sudirman No. 10, Salatiga',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Alamat valid.')
            ->assertJsonPath('data.formatted_address', 'Jl. Sudirman No. 10, Salatiga, Jawa Tengah, Indonesia')
            ->assertJsonPath('data.latitude', -7.33165)
            ->assertJsonPath('data.longitude', 110.4995);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return ($data['bounds'] ?? null) === '-7.650000,110.050000|-6.900000,110.800000'
                && ($data['components'] ?? null) === 'country:ID';
        });
    }

    public function test_saved_address_validation_rejects_geocoding_result_outside_service_area(): void
    {
        $user = User::query()->create([
            'name' => 'Validasi Luar Area',
            'email' => 'validasi.luar.area@example.com',
            'phone' => '081233340002',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'formatted_address' => 'Monas, Jakarta, Indonesia',
                        'geometry' => [
                            'location' => [
                                'lat' => -6.175392,
                                'lng' => 106.827153,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/user/addresses/validate', [
            'full_address' => 'Monas Jakarta',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Alamat tidak valid atau tidak ditemukan di peta.');

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return ($data['bounds'] ?? null) === '-7.650000,110.050000|-6.900000,110.800000'
                && ($data['components'] ?? null) === 'country:ID';
        });
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

        $response = $this->deleteJson('/api/user/addresses/'.$defaultAddress->id);

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
