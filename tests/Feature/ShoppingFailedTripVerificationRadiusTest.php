<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderLocation;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Shopping\ShoppingFailedTripCompensationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Radius kehadiran driver menentukan apakah sebuah kegagalan merchant
 * diakumulasi ke kompensasi perjalanan gagal. Nilainya konfigurabel agar
 * kompensasi dapat diperagakan tanpa berada di titik merchant.
 */
class ShoppingFailedTripVerificationRadiusTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_radius_is_two_hundred_meters(): void
    {
        $this->assertSame(
            200,
            app(ShoppingFailedTripCompensationService::class)->verificationRadiusMeters()
        );
    }

    public function test_driver_just_outside_default_radius_is_not_verified(): void
    {
        [$order, $pickup] = $this->orderWithDriverAt(-7.0545582, 110.435897);

        // Jarak driver ke merchant ±254 m, di luar radius bawaan 200 m.
        $this->assertFalse(
            app(ShoppingFailedTripCompensationService::class)
                ->isDriverWithinMerchantRadius($order, $pickup)
        );
    }

    public function test_widened_radius_from_config_verifies_the_same_position(): void
    {
        Config::set('bangdeliv.failed_trip.verification_radius_meters', 500);

        [$order, $pickup] = $this->orderWithDriverAt(-7.0545582, 110.435897);

        $service = app(ShoppingFailedTripCompensationService::class);

        $this->assertSame(500, $service->verificationRadiusMeters());
        $this->assertTrue($service->isDriverWithinMerchantRadius($order, $pickup));
    }

    public function test_stale_driver_location_is_never_verified(): void
    {
        Config::set('bangdeliv.failed_trip.verification_radius_meters', 5000);
        Config::set('bangdeliv.failed_trip.driver_location_fresh_minutes', 5);

        [$order, $pickup] = $this->orderWithDriverAt(
            -7.0567329,
            110.43519084,
            locationUpdatedMinutesAgo: 30
        );

        $this->assertFalse(
            app(ShoppingFailedTripCompensationService::class)
                ->isDriverWithinMerchantRadius($order, $pickup)
        );
    }

    /**
     * @return array{0: Order, 1: OrderLocation}
     */
    private function orderWithDriverAt(
        float $driverLatitude,
        float $driverLongitude,
        int $locationUpdatedMinutesAgo = 0,
    ): array {
        $driverUser = User::query()->create([
            'name' => 'Driver Radius',
            'email' => 'driver.radius'.random_int(1, 99999).'@example.com',
            'phone' => '0812'.random_int(10000000, 99999999),
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B '.random_int(1000, 9999).' RAD',
            'registration_status' => 'active',
            'status' => 'available',
            'latitude' => $driverLatitude,
            'longitude' => $driverLongitude,
            'location_updated_at' => now()->subMinutes($locationUpdatedMinutesAgo),
        ]));

        $customer = User::factory()->create(['role' => 'customer']);

        $order = Order::query()->create([
            'order_number' => 'BD-RAD-'.strtoupper(substr(md5((string) random_int(1, 999999)), 0, 8)),
            'user_id' => $customer->id,
            'service_type_id' => (int) ServiceType::query()->where('code', 'SHOPPING')->value('id'),
            'driver_id' => $driver->id,
            'delivery_address' => 'Jl. Radius No. 1',
            'delivery_latitude' => -7.001234,
            'delivery_longitude' => 110.401234,
            'subtotal' => 0,
            'delivery_fee' => 5000,
            'service_fee' => 0,
            'total_amount' => 5000,
            'total_price' => 5000,
            'status_id' => (int) OrderStatus::query()->where('code', 'ARRIVED_MERCHANT')->value('id'),
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);

        // Koordinat merchant nyata dari log order: Rusdi Barbershop.
        $pickup = OrderLocation::query()->create([
            'order_id' => $order->id,
            'location_role' => 'PICKUP',
            'label' => 'Merchant Uji Radius',
            'full_address' => 'Jl. Merchant Uji',
            'latitude' => -7.0567329,
            'longitude' => 110.43519084,
            'sequence_no' => 1,
            'fulfillment_status' => 'ITEMS_CONFIRMED',
        ]);

        return [$order, $pickup];
    }
}
