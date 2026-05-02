<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Driver;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\Restaurant;
use App\Models\ShoppingOrder;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::query()->where('email', 'hassan@bangdeliv.com')->first();
        $customerTwo = User::query()->where('email', 'sari@bangdeliv.com')->first();
        $driver = Driver::query()->where('registration_status', 'active')->first();
        $restaurant = Restaurant::query()->where('slug', 'ayam-geprek-juara')->first();

        if (!$customer || !$customerTwo || !$driver || !$restaurant) {
            return;
        }

        $addressOne = Address::updateOrCreate(
            [
                'user_id' => $customer->id,
                'label' => 'Rumah',
            ],
            [
                'recipient_name' => $customer->name,
                'phone' => $customer->phone,
                'full_address' => 'Jl. Melati No. 3, Jakarta',
                'detail' => 'Pagar hitam, rumah pojok.',
                'latitude' => -6.20550000,
                'longitude' => 106.82400000,
                'is_default' => true,
            ]
        );

        $addressTwo = Address::updateOrCreate(
            [
                'user_id' => $customerTwo->id,
                'label' => 'Kantor',
            ],
            [
                'recipient_name' => $customerTwo->name,
                'phone' => $customerTwo->phone,
                'full_address' => 'Jl. Sudirman Kav. 10, Jakarta',
                'detail' => 'Lobi utama, titip resepsionis.',
                'latitude' => -6.21300000,
                'longitude' => 106.82150000,
                'is_default' => true,
            ]
        );

        $menuA = Menu::query()->where('restaurant_id', $restaurant->id)->where('name', 'Paket Geprek Original')->first();
        $menuB = Menu::query()->where('restaurant_id', $restaurant->id)->where('name', 'Es Teh Manis')->first();

        if (!$menuA || !$menuB) {
            return;
        }

        $shoppingServiceTypeId = ServiceType::query()->where('code', 'SHOPPING')->value('id');
        if (!$shoppingServiceTypeId) {
            return;
        }

        $statusMap = OrderStatus::query()->pluck('id', 'code');

        $this->seedSingleOrder(
            orderNumber: 'BD-SEED-0001',
            customer: $customer,
            driverId: $driver->id,
            restaurant: $restaurant,
            address: $addressOne,
            statusCode: 'ON_THE_WAY',
            paymentStatus: 'paid',
            itemRows: [
                ['menu' => $menuA, 'qty' => 2, 'notes' => 'Pedas level 2'],
                ['menu' => $menuB, 'qty' => 2, 'notes' => null],
            ],
            historyStatuses: ['PENDING', 'DRIVER_ASSIGNED', 'PICKED_UP', 'ON_THE_WAY'],
            shoppingServiceTypeId: (int) $shoppingServiceTypeId,
            statusMap: $statusMap->all(),
        );

        $this->seedSingleOrder(
            orderNumber: 'BD-SEED-0002',
            customer: $customerTwo,
            driverId: $driver->id,
            restaurant: $restaurant,
            address: $addressTwo,
            statusCode: 'COMPLETED',
            paymentStatus: 'paid',
            itemRows: [
                ['menu' => $menuA, 'qty' => 1, 'notes' => null],
                ['menu' => $menuB, 'qty' => 1, 'notes' => null],
            ],
            historyStatuses: ['PENDING', 'DRIVER_ASSIGNED', 'PICKED_UP', 'ON_THE_WAY', 'DELIVERED', 'COMPLETED'],
            shoppingServiceTypeId: (int) $shoppingServiceTypeId,
            statusMap: $statusMap->all(),
        );
    }

    /**
     * @param  array<int, array{menu: \App\Models\Menu, qty: int, notes: ?string}>  $itemRows
     * @param  array<int, string>  $historyStatuses
     */
    private function seedSingleOrder(
        string $orderNumber,
        User $customer,
        int $driverId,
        Restaurant $restaurant,
        Address $address,
        string $statusCode,
        string $paymentStatus,
        array $itemRows,
        array $historyStatuses,
        int $shoppingServiceTypeId,
        array $statusMap
    ): void {
        if (!isset($statusMap[$statusCode])) {
            return;
        }

        $subtotal = 0;
        foreach ($itemRows as $row) {
            $subtotal += (float) $row['menu']->price * $row['qty'];
        }

        $deliveryFee = 8000;
        $order = Order::updateOrCreate(
            ['order_number' => $orderNumber],
            [
                'user_id' => $customer->id,
                'restaurant_id' => $restaurant->id,
                'service_type_id' => $shoppingServiceTypeId,
                'driver_id' => $driverId,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'service_fee' => 0,
                'delivery_distance_km' => 3.2,
                'delivery_distance_text' => '3.2 km',
                'total_price' => $subtotal + $deliveryFee,
                'status_id' => $statusMap[$statusCode],
                'notes' => 'Order seeded for development.',
                'estimated_delivery' => now()->addMinutes(35),
                'delivered_at' => $statusCode === 'COMPLETED' ? now()->subMinutes(15) : null,
            ]
        );

        $order->orderLocations()->delete();
        $order->orderLocations()->createMany([
            [
                'location_role' => 'PICKUP',
                'label' => 'Restaurant',
                'contact_name' => $restaurant->name,
                'contact_phone' => $restaurant->phone,
                'full_address' => $restaurant->address,
                'latitude' => $restaurant->latitude,
                'longitude' => $restaurant->longitude,
                'sequence_no' => 1,
                'notes' => 'Seeded restaurant pickup.',
            ],
            [
                'location_role' => 'DROPOFF',
                'label' => $address->label,
                'contact_name' => $address->recipient_name,
                'contact_phone' => $address->phone,
                'full_address' => trim($address->full_address.' '.$address->detail),
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
                'sequence_no' => 2,
                'notes' => 'Seeded customer dropoff.',
            ],
        ]);

        $order->items()->delete();
        foreach ($itemRows as $row) {
            $unitPrice = (float) $row['menu']->price;
            $qty = (int) $row['qty'];

            $order->items()->create([
                'menu_id' => $row['menu']->id,
                'item_source' => 'MENU_DB',
                'menu_name' => $row['menu']->name,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => $unitPrice * $qty,
                'line_service_fee' => 0,
                'line_total' => $unitPrice * $qty,
                'notes' => $row['notes'],
                'is_available' => true,
                'is_heavy' => false,
            ]);
        }

        ShoppingOrder::query()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'failed_attempt_count' => 0,
                'item_surcharge' => 0,
                'overweight_surcharge' => 0,
                'cancellation_penalty' => 0,
                'has_overweight_item' => false,
                'recalculation_version' => 0,
            ]
        );

        if ($paymentStatus === 'paid') {
            $order->payments()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'payment_method' => 'COD',
                    'payment_status' => 'PAID',
                    'amount' => $subtotal + $deliveryFee,
                    'recorded_by_user_id' => $customer->id,
                    'driver_id' => $driverId,
                    'paid_at' => now()->subMinutes(10),
                    'note' => 'Seeded paid COD payment.',
                ]
            );
        } else {
            $order->payments()->delete();
        }

        OrderStatusHistory::query()->where('order_id', $order->id)->delete();
        foreach ($historyStatuses as $historyStatus) {
            if (!isset($statusMap[$historyStatus])) {
                continue;
            }

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $statusMap[$historyStatus],
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $customer->id,
                'note' => 'Seeded status '.$historyStatus,
                'created_at' => now(),
            ]);
        }
    }
}
