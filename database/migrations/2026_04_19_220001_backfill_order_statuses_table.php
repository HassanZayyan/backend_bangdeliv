<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        $rows = [
            ['code' => 'PENDING', 'display_name' => 'Menunggu Driver', 'is_terminal' => false, 'sort_order' => 1],
            ['code' => 'DRIVER_ASSIGNED', 'display_name' => 'Driver Ditugaskan', 'is_terminal' => false, 'sort_order' => 2],
            ['code' => 'ARRIVED_MERCHANT', 'display_name' => 'Driver Tiba di Merchant', 'is_terminal' => false, 'sort_order' => 3],
            ['code' => 'ARRIVED_PICKUP', 'display_name' => 'Driver Tiba di Titik Jemput', 'is_terminal' => false, 'sort_order' => 4],
            ['code' => 'PICKED_UP', 'display_name' => 'Pesanan Diambil', 'is_terminal' => false, 'sort_order' => 5],
            ['code' => 'ON_THE_WAY', 'display_name' => 'Dalam Perjalanan', 'is_terminal' => false, 'sort_order' => 6],
            ['code' => 'ARRIVED_DROPOFF', 'display_name' => 'Driver Tiba di Tujuan', 'is_terminal' => false, 'sort_order' => 7],
            ['code' => 'DELIVERED', 'display_name' => 'Sudah Sampai Tujuan', 'is_terminal' => false, 'sort_order' => 8],
            ['code' => 'COMPLETED', 'display_name' => 'Selesai', 'is_terminal' => true, 'sort_order' => 9],
            ['code' => 'CANCELLED', 'display_name' => 'Dibatalkan', 'is_terminal' => true, 'sort_order' => 10],
            ['code' => 'CANCELLED_WITH_FEE', 'display_name' => 'Dibatalkan Dengan Biaya', 'is_terminal' => true, 'sort_order' => 11],
            ['code' => 'COMPLAINT', 'display_name' => 'Komplain', 'is_terminal' => false, 'sort_order' => 12],
        ];

        $payload = array_map(static function (array $row) use ($now): array {
            return [
                'code' => $row['code'],
                'display_name' => $row['display_name'],
                'is_terminal' => $row['is_terminal'],
                'sort_order' => $row['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $rows);

        DB::table('order_statuses')->upsert(
            $payload,
            ['code'],
            ['display_name', 'is_terminal', 'sort_order', 'updated_at']
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank. This migration is a data backfill.
    }
};
