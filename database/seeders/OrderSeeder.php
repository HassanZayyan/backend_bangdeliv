<?php

namespace Database\Seeders;

use App\Models\Order;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        Order::query()
            ->withTrashed()
            ->whereIn('order_number', ['BD-SEED-0001', 'BD-SEED-0002'])
            ->get()
            ->each
            ->forceDelete();
    }
}
