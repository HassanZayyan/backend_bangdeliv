<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Geo\BangDelivServiceAreaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RideOrderCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bangdeliv.google_maps_api_key' => 'test-google-maps-key',
        ]);
    }

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
            'latitude' => -7.31991677,
            'longitude' => 110.46393595,
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            'https://routes.googleapis.com/*' => Http::response([
                'routes' => [[
                    'distanceMeters' => 5000,
                    'duration' => '600s',
                    'polyline' => ['encodedPolyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@'],
                    'legs' => [[
                        'distanceMeters' => 5000,
                        'duration' => '600s',
                    ]],
                ]],
            ], 200),
            'https://maps.googleapis.com/maps/api/distancematrix/json*' => Http::response([
                'status' => 'OK',
                'rows' => [
                    [
                        'elements' => [
                            [
                                'status' => 'OK',
                                'distance' => ['value' => 5000, 'text' => '5.0 km'],
                                'duration' => ['value' => 600, 'text' => '10 mins'],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://maps.googleapis.com/maps/api/place/textsearch/json*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'formatted_address' => 'Lapangan Pancasila Salatiga, Jawa Tengah',
                        'geometry' => [
                            'location' => [
                                'lat' => -7.33120000,
                                'lng' => 110.50770000,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'formatted_address' => 'Lapangan Pancasila Salatiga, Jawa Tengah',
                        'geometry' => [
                            'location' => [
                                'lat' => -7.33120000,
                                'lng' => 110.50770000,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/orders/ride', [
            'address_id' => $address->id,
            'destination_address' => 'Lapangan Pancasila Salatiga',
        ]);

        $rideServiceTypeId = ServiceType::query()->where('code', 'RIDE')->value('id');
        $pendingStatusId = OrderStatus::query()->where('code', 'PENDING')->value('id');

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.service_type_id', $rideServiceTypeId)
            ->assertJsonPath('data.status_id', $pendingStatusId)
            ->assertJsonPath('data.delivery_address', 'Lapangan Pancasila Salatiga, Jawa Tengah')
            ->assertJsonPath('data.delivery_latitude', '-7.33120000')
            ->assertJsonPath('data.delivery_longitude', '110.50770000')
            ->assertJsonPath('data.route.encoded_polyline', '_p~iF~ps|U_ulLnnqC_mqNvxq`@')
            ->assertJsonPath('data.route.route_provider', 'routes_api');

        $orderId = (int) $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
            'service_type_id' => $rideServiceTypeId,
            'status_id' => $pendingStatusId,
        ]);

        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'location_role' => 'PICKUP',
            'full_address' => 'Jl. Mawar No. 1',
            'latitude' => -7.31991677,
            'longitude' => 110.46393595,
        ]);

        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'location_role' => 'DROPOFF',
            'full_address' => 'Lapangan Pancasila Salatiga, Jawa Tengah',
            'latitude' => -7.33120000,
            'longitude' => 110.50770000,
        ]);

        $this->assertDatabaseHas('ride_order_details', [
            'order_id' => $orderId,
        ]);

        $routeSnapshot = Order::query()->findOrFail($orderId)->route_snapshot;
        $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', $routeSnapshot['encoded_polyline'] ?? null);
        $this->assertSame('routes_api', $routeSnapshot['route_provider'] ?? null);

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $orderId,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $orderId,
            'status_id' => $pendingStatusId,
            'changed_by_user_id' => $user->id,
        ]);

        Http::assertSentCount(2);
    }

    public function test_customer_can_create_ride_order_with_default_pickup_address_when_address_id_is_omitted(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081211110013',
        ]);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Alamat Lama',
            'recipient_name' => 'Customer Ride',
            'phone' => '081211110013',
            'full_address' => 'Alamat tanpa koordinat',
            'detail' => null,
            'latitude' => 0,
            'longitude' => 0,
            'is_default' => false,
        ]);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah Default',
            'recipient_name' => 'Customer Ride',
            'phone' => '081211110013',
            'full_address' => 'Jl. Default Pickup No. 9',
            'detail' => 'Pagar hijau',
            'latitude' => -7.31991677,
            'longitude' => 110.46393595,
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            'https://routes.googleapis.com/*' => Http::response([
                'routes' => [[
                    'distanceMeters' => 5000,
                    'duration' => '600s',
                    'polyline' => ['encodedPolyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@'],
                    'legs' => [[
                        'distanceMeters' => 5000,
                        'duration' => '600s',
                    ]],
                ]],
            ], 200),
            'https://maps.googleapis.com/maps/api/distancematrix/json*' => Http::response([
                'status' => 'OK',
                'rows' => [
                    [
                        'elements' => [
                            [
                                'status' => 'OK',
                                'distance' => ['value' => 5000, 'text' => '5.0 km'],
                                'duration' => ['value' => 600, 'text' => '10 mins'],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://maps.googleapis.com/maps/api/place/textsearch/json*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'formatted_address' => 'Lapangan Pancasila Salatiga, Jawa Tengah',
                        'geometry' => [
                            'location' => [
                                'lat' => -7.33120000,
                                'lng' => 110.50770000,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'formatted_address' => 'Lapangan Pancasila Salatiga, Jawa Tengah',
                        'geometry' => [
                            'location' => [
                                'lat' => -7.33120000,
                                'lng' => 110.50770000,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/orders/ride', [
            'destination_address' => 'Lapangan Pancasila Salatiga',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $orderId = (int) $response->json('data.id');

        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'location_role' => 'PICKUP',
            'full_address' => 'Jl. Default Pickup No. 9',
            'latitude' => -7.31991677,
            'longitude' => 110.46393595,
        ]);

        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'location_role' => 'DROPOFF',
            'full_address' => 'Lapangan Pancasila Salatiga, Jawa Tengah',
            'latitude' => -7.33120000,
            'longitude' => 110.50770000,
        ]);

        Http::assertSentCount(2);
    }

    public function test_customer_can_create_ride_order_with_payload_destination_coordinates_without_regeocoding(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081211110010',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Ride GPS',
            'phone' => '081211110010',
            'full_address' => 'Jl. Mawar No. 1',
            'detail' => 'Lobi depan',
            'latitude' => -7.31991677,
            'longitude' => 110.46393595,
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);

        $geocodingCalls = 0;

        Http::fake([
            'https://maps.googleapis.com/maps/api/distancematrix/json*' => Http::response([
                'status' => 'OK',
                'rows' => [
                    [
                        'elements' => [
                            [
                                'status' => 'OK',
                                'distance' => ['value' => 5000, 'text' => '5.0 km'],
                                'duration' => ['value' => 600, 'text' => '10 mins'],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://maps.googleapis.com/maps/api/geocode/json*' => function () use (&$geocodingCalls) {
                $geocodingCalls++;

                return Http::response([
                    'status' => 'OK',
                    'results' => [[
                        'formatted_address' => 'Alamat Geocoded Berbeda',
                        'geometry' => [
                            'location' => [
                                'lat' => -6.90000000,
                                'lng' => 107.60000000,
                            ],
                        ],
                    ]],
                ], 200);
            },
            'https://maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
                'status' => 'OK',
                'rows' => [[
                    'elements' => [[
                        'status' => 'OK',
                        'distance' => [
                            'text' => '2.0 km',
                            'value' => 2000,
                        ],
                        'duration' => [
                            'text' => '10 mins',
                            'value' => 600,
                        ],
                    ]],
                ]],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/orders/ride', [
            'address_id' => $address->id,
            'destination_address' => 'Titik pin manual customer',
            'destination_latitude' => -7.33120000,
            'destination_longitude' => 110.50770000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_address', 'Titik pin manual customer')
            ->assertJsonPath('data.delivery_latitude', '-7.33120000')
            ->assertJsonPath('data.delivery_longitude', '110.50770000');

        $orderId = (int) $response->json('data.id');

        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'location_role' => 'DROPOFF',
            'full_address' => 'Titik pin manual customer',
            'latitude' => -7.33120000,
            'longitude' => 110.50770000,
        ]);

        $this->assertSame(0, $geocodingCalls, 'Destination geocoding should be skipped when explicit coordinates are provided.');
    }

    public function test_customer_cannot_create_ride_order_when_pickup_and_destination_are_too_close(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081211110011',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Ride Close Route',
            'phone' => '081211110011',
            'full_address' => 'Jl. Mawar No. 1',
            'detail' => 'Lobi depan',
            'latitude' => -7.31991677,
            'longitude' => 110.46393595,
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);
        Http::fake();

        $response = $this->postJson('/api/v1/orders/ride', [
            'address_id' => $address->id,
            'destination_address' => 'Titik sama dengan pickup',
            'destination_latitude' => -7.31991677,
            'destination_longitude' => 110.46393595,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Titik tujuan terlalu dekat dengan titik jemput. Pilih titik tujuan yang berbeda.');

        $this->assertDatabaseCount('ride_order_details', 0);
        Http::assertNothingSent();
    }

    public function test_customer_cannot_create_ride_order_when_destination_is_outside_service_radius(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081211110012',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Ride Outside Radius',
            'phone' => '081211110012',
            'full_address' => 'Angkringan 54',
            'detail' => null,
            'latitude' => -7.31991677,
            'longitude' => 110.46393595,
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);
        Http::fake();

        $response = $this->postJson('/api/v1/orders/ride', [
            'address_id' => $address->id,
            'destination_address' => 'Monas Jakarta',
            'destination_latitude' => -6.175392,
            'destination_longitude' => 106.827153,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.code', BangDelivServiceAreaService::ERROR_DISTANCE_LIMIT)
            ->assertJsonPath('errors.point_role', 'tujuan');

        $this->assertStringContainsString('melebihi batas layanan', (string) $response->json('message'));
        $this->assertDatabaseCount('ride_order_details', 0);
        Http::assertNothingSent();
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
        $this->assertDatabaseCount('ride_order_details', 0);
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

    public function test_create_ride_order_rejects_invalid_destination_address(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081211110005',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Invalid Destination',
            'phone' => '081211110005',
            'full_address' => 'Jl. Anggrek No. 11',
            'detail' => null,
            'latitude' => -6.23000000,
            'longitude' => 106.84666600,
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            'https://maps.googleapis.com/maps/api/distancematrix/json*' => Http::response([
                'status' => 'OK',
                'rows' => [
                    [
                        'elements' => [
                            [
                                'status' => 'OK',
                                'distance' => ['value' => 5000, 'text' => '5.0 km'],
                                'duration' => ['value' => 600, 'text' => '10 mins'],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://maps.googleapis.com/maps/api/place/textsearch/json*' => Http::response([
                'status' => 'ZERO_RESULTS',
                'results' => [],
            ], 200),
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'ZERO_RESULTS',
                'results' => [],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/orders/ride', [
            'address_id' => $address->id,
            'destination_address' => 'isekai',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Alamat tujuan tidak valid atau tidak ditemukan di peta.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('ride_order_details', 0);

        Http::assertSentCount(2);
    }

    public function test_customer_can_validate_ride_destination_before_confirmation(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081211110006',
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            'https://maps.googleapis.com/maps/api/distancematrix/json*' => Http::response([
                'status' => 'OK',
                'rows' => [
                    [
                        'elements' => [
                            [
                                'status' => 'OK',
                                'distance' => ['value' => 5000, 'text' => '5.0 km'],
                                'duration' => ['value' => 600, 'text' => '10 mins'],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://maps.googleapis.com/maps/api/place/textsearch/json*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'formatted_address' => 'Politeknik Negeri Semarang, Tembalang, Kota Semarang, Jawa Tengah, Indonesia',
                        'geometry' => [
                            'location' => [
                                'lat' => -7.05244100,
                                'lng' => 110.43515500,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'formatted_address' => 'Politeknik Negeri Semarang, Tembalang, Kota Semarang, Jawa Tengah, Indonesia',
                        'geometry' => [
                            'location' => [
                                'lat' => -7.05244100,
                                'lng' => 110.43515500,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/orders/ride/validate-destination', [
            'destination_address' => 'antar ke polines',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Alamat tujuan valid.')
            ->assertJsonPath('data.formatted_address', 'Politeknik Negeri Semarang, Tembalang, Kota Semarang, Jawa Tengah, Indonesia');

        Http::assertSentCount(1);
    }

    public function test_customer_cannot_validate_ride_destination_when_address_is_invalid(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081211110007',
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            'https://maps.googleapis.com/maps/api/distancematrix/json*' => Http::response([
                'status' => 'OK',
                'rows' => [
                    [
                        'elements' => [
                            [
                                'status' => 'OK',
                                'distance' => ['value' => 5000, 'text' => '5.0 km'],
                                'duration' => ['value' => 600, 'text' => '10 mins'],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://maps.googleapis.com/maps/api/place/textsearch/json*' => Http::response([
                'status' => 'ZERO_RESULTS',
                'results' => [],
            ], 200),
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'ZERO_RESULTS',
                'results' => [],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/orders/ride/validate-destination', [
            'destination_address' => 'isekai',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Alamat tujuan tidak valid atau tidak ditemukan di peta.');

        Http::assertSentCount(2);
    }
}
