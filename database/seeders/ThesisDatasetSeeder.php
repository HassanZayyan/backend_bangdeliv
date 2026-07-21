<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Memulihkan dataset pengujian skripsi (14–17 Juli 2026) secara utuh dengan id
 * eksplisit: 5 driver terverifikasi, 23 customer, beserta seluruh order,
 * pembayaran, event, riwayat status, bukti, dan chat. Fresh seed di VPS akan
 * menghasilkan data yang identik dengan revisi laporan.
 *
 * Prasyarat: admin sudah ada (ProductionAdminSeeder / UserSeeder) dan
 * RestaurantMenuSeeder sudah berjalan (shopping_order_items.menu_id mengacu ke
 * id menu deterministik).
 */
class ThesisDatasetSeeder extends Seeder
{
    public const ADMIN_CREATED_AT = '2026-07-08 09:30:00';

    public const ADMIN_UPDATED_AT = '2026-07-15 21:19:29';

    /**
     * Urutan tabel mengikuti dependensi foreign key.
     *
     * @var list<string>
     */
    private const TABLES = [
        'users',
        'addresses',
        'drivers',
        'driver_documents',
        'device_tokens',
        'orders',
        'order_locations',
        'shopping_order_items',
        'order_payments',
        'ride_order_details',
        'courier_order_details',
        'shopping_order_receipts',
        'order_evidence',
        'order_events',
        'order_status_histories',
        'order_chat_messages',
        'order_chat_reads',
    ];

    public function run(): void
    {
        $admin = User::query()
            ->where('role', 'admin')
            ->orderBy('id')
            ->first();

        if ($admin === null) {
            throw new RuntimeException(
                'Admin belum tersedia. Jalankan ProductionAdminSeeder/UserSeeder sebelum ThesisDatasetSeeder.'
            );
        }

        $data = [];
        foreach (self::TABLES as $table) {
            $path = database_path("seeders/data/thesis_dataset/{$table}.php");

            if (! is_file($path)) {
                throw new RuntimeException("Data file thesis_dataset/{$table}.php tidak ditemukan.");
            }

            $data[$table] = require $path;
        }

        DB::transaction(function () use ($data, $admin): void {
            foreach (self::TABLES as $table) {
                $rows = $data[$table];

                if ($table === 'driver_documents') {
                    $rows = array_map(
                        static fn (array $row): array => [...$row, 'verified_by' => $admin->id],
                        $rows
                    );
                }

                $this->upsert($table, $rows);
            }

            // Pin timestamp admin agar konsisten dengan cerita dataset
            // (ProductionAdminSeeder membuatnya dengan created_at = waktu seeding;
            // updated_at = aksi admin terakhir: verifikasi dokumen Driver 05).
            DB::table('users')
                ->where('id', $admin->id)
                ->update([
                    'created_at' => self::ADMIN_CREATED_AT,
                    'updated_at' => self::ADMIN_UPDATED_AT,
                ]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $updateColumns = array_values(array_diff(array_keys($rows[0]), ['id']));

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table($table)->upsert($chunk, ['id'], $updateColumns);
        }
    }
}
