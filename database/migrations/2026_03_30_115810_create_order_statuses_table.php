<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('display_name', 100);
            $table->boolean('is_terminal')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('order_statuses')->insert([
            [
                'code' => 'PENDING',
                'display_name' => 'Menunggu Driver',
                'is_terminal' => false,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'DRIVER_ASSIGNED',
                'display_name' => 'Driver Ditugaskan',
                'is_terminal' => false,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'ARRIVED_MERCHANT',
                'display_name' => 'Driver Tiba di Merchant',
                'is_terminal' => false,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'ARRIVED_PICKUP',
                'display_name' => 'Driver Tiba di Titik Jemput',
                'is_terminal' => false,
                'sort_order' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'PICKED_UP',
                'display_name' => 'Pesanan Diambil',
                'is_terminal' => false,
                'sort_order' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'ON_THE_WAY',
                'display_name' => 'Dalam Perjalanan',
                'is_terminal' => false,
                'sort_order' => 6,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'ARRIVED_DROPOFF',
                'display_name' => 'Driver Tiba di Tujuan',
                'is_terminal' => false,
                'sort_order' => 7,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'DELIVERED',
                'display_name' => 'Sudah Sampai Tujuan',
                'is_terminal' => false,
                'sort_order' => 8,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'COMPLETED',
                'display_name' => 'Selesai',
                'is_terminal' => true,
                'sort_order' => 9,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'CANCELLED',
                'display_name' => 'Dibatalkan',
                'is_terminal' => true,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'CANCELLED_WITH_FEE',
                'display_name' => 'Dibatalkan Dengan Biaya',
                'is_terminal' => true,
                'sort_order' => 11,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_statuses');
    }
};
