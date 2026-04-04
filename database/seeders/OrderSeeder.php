<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Driver;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Restaurant;
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

        $this->seedSingleOrder(
            orderNumber: 'BD-SEED-0001',
            customer: $customer,
            driverId: $driver->id,
            restaurant: $restaurant,
            address: $addressOne,
            status: 'on_delivery',
            paymentStatus: 'paid',
            itemRows: [
                ['menu' => $menuA, 'qty' => 2, 'notes' => 'Pedas level 2'],
                ['menu' => $menuB, 'qty' => 2, 'notes' => null],
            ],
            historyStatuses: ['confirmed', 'driver_assigned', 'picking_up', 'on_delivery']
        );

        $this->seedSingleOrder(
            orderNumber: 'BD-SEED-0002',
            customer: $customerTwo,
            driverId: $driver->id,
            restaurant: $restaurant,
            address: $addressTwo,
            status: 'completed',
            paymentStatus: 'paid',
            itemRows: [
                ['menu' => $menuA, 'qty' => 1, 'notes' => null],
                ['menu' => $menuB, 'qty' => 1, 'notes' => null],
            ],
            historyStatuses: ['confirmed', 'driver_assigned', 'picking_up', 'on_delivery', 'delivered', 'completed']
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
        string $status,
        string $paymentStatus,
        array $itemRows,
        array $historyStatuses
    ): void {
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
                'driver_id' => $driverId,
                'address_id' => $address->id,
                'delivery_address' => trim($address->full_address.' '.$address->detail),
                'delivery_latitude' => $address->latitude,
                'delivery_longitude' => $address->longitude,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'delivery_distance_km' => 3.2,
                'delivery_distance_text' => '3.2 km',
                'total_amount' => $subtotal + $deliveryFee,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'notes' => 'Order seeded for development.',
                'estimated_delivery' => now()->addMinutes(35),
                'delivered_at' => $status === 'completed' ? now()->subMinutes(15) : null,
            ]
        );

        $order->items()->delete();
        foreach ($itemRows as $row) {
            $unitPrice = (float) $row['menu']->price;
            $qty = (int) $row['qty'];

            $order->items()->create([
                'menu_id' => $row['menu']->id,
                'menu_name' => $row['menu']->name,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => $unitPrice * $qty,
                'notes' => $row['notes'],
                'is_available' => true,
            ]);
        }

        OrderStatusHistory::query()->where('order_id', $order->id)->delete();
        foreach ($historyStatuses as $historyStatus) {
            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status' => $historyStatus,
                'changed_by_user_id' => $customer->id,
                'note' => 'Seeded status '.$historyStatus,
                'created_at' => now(),
            ]);
        }
    }
}
