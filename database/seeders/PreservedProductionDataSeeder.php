<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PreservedProductionDataSeeder extends Seeder
{
    public const SNAPSHOT_PATH = 'seed-data/preserved-production-accounts.json';

    /** @var list<int> */
    private const EXPECTED_USER_IDS = [2, 3, 4, 5, 6, 8];

    /** @var list<int> */
    private const EXPECTED_ADDRESS_IDS = [1, 2, 3, 5];

    /** @var list<int> */
    private const EXPECTED_DRIVER_IDS = [1];

    /** @var list<int> */
    private const EXPECTED_DOCUMENT_IDS = [1, 2, 3];

    public function run(): void
    {
        if (! app()->isProduction()) {
            return;
        }

        $snapshot = $this->loadSnapshot();
        $this->validateSnapshot($snapshot);

        $admin = User::query()
            ->where('role', 'admin')
            ->orderBy('id')
            ->first();

        if ($admin === null) {
            throw new RuntimeException(
                'Admin production belum tersedia. Jalankan ProductionAdminSeeder sebelum PreservedProductionDataSeeder.'
            );
        }

        DB::transaction(function () use ($snapshot, $admin): void {
            $this->upsert('users', $snapshot['users']);
            $this->upsert('addresses', $snapshot['addresses']);
            $this->upsert('drivers', $snapshot['drivers']);

            $documents = array_map(
                static fn (array $document): array => [
                    ...$document,
                    'verified_by' => $admin->id,
                ],
                $snapshot['driver_documents']
            );

            $this->upsert('driver_documents', $documents);
        });
    }

    /**
     * @return array{
     *     version: int,
     *     captured_at: string,
     *     users: list<array<string, mixed>>,
     *     addresses: list<array<string, mixed>>,
     *     drivers: list<array<string, mixed>>,
     *     driver_documents: list<array<string, mixed>>
     * }
     */
    private function loadSnapshot(): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::SNAPSHOT_PATH)) {
            throw new RuntimeException(
                'Snapshot production tidak ditemukan di storage/app/private/'.self::SNAPSHOT_PATH.'.'
            );
        }

        try {
            $snapshot = json_decode(
                $disk->get(self::SNAPSHOT_PATH),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Snapshot production bukan JSON yang valid: '.$exception->getMessage(),
                previous: $exception
            );
        }

        if (! is_array($snapshot)) {
            throw new RuntimeException('Snapshot production harus berupa JSON object.');
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    private function validateSnapshot(array $snapshot): void
    {
        if (($snapshot['version'] ?? null) !== 1) {
            throw new RuntimeException('Snapshot production harus menggunakan version 1.');
        }

        if (! is_string($snapshot['captured_at'] ?? null) || trim($snapshot['captured_at']) === '') {
            throw new RuntimeException('Snapshot production wajib memiliki captured_at.');
        }

        $requiredFields = [
            'users' => [
                'id', 'name', 'email', 'google_sub', 'phone', 'email_verified_at',
                'phone_verified_at', 'password', 'role', 'avatar', 'is_active',
                'is_blacklisted', 'remember_token', 'created_at', 'updated_at', 'deleted_at',
            ],
            'addresses' => [
                'id', 'user_id', 'label', 'recipient_name', 'phone', 'full_address',
                'latitude', 'longitude', 'is_default', 'created_at', 'updated_at',
            ],
            'drivers' => [
                'id', 'user_id', 'vehicle_plate', 'vehicle_type', 'vehicle_brand',
                'vehicle_model', 'registration_status', 'status', 'latitude', 'longitude',
                'location_updated_at', 'created_at', 'updated_at', 'deleted_at',
            ],
            'driver_documents' => [
                'id', 'driver_id', 'document_type', 'file_path', 'verification_status',
                'rejection_reason', 'verified_at', 'verified_by', 'created_at', 'updated_at',
            ],
        ];

        foreach ($requiredFields as $section => $fields) {
            $rows = $snapshot[$section] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new RuntimeException("Snapshot section {$section} harus berupa array.");
            }

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    throw new RuntimeException("Snapshot {$section}[{$index}] harus berupa object.");
                }

                foreach ($fields as $field) {
                    if (! array_key_exists($field, $row)) {
                        throw new RuntimeException("Snapshot {$section}[{$index}] tidak memiliki field {$field}.");
                    }
                }
            }
        }

        $this->assertExactIds('users', $snapshot['users'], self::EXPECTED_USER_IDS);
        $this->assertExactIds('addresses', $snapshot['addresses'], self::EXPECTED_ADDRESS_IDS);
        $this->assertExactIds('drivers', $snapshot['drivers'], self::EXPECTED_DRIVER_IDS);
        $this->assertExactIds('driver_documents', $snapshot['driver_documents'], self::EXPECTED_DOCUMENT_IDS);

        $this->assertUniqueValues('users', $snapshot['users'], 'email');
        $this->assertUniqueValues('users', $snapshot['users'], 'google_sub', true);
        $this->assertUniqueValues('users', $snapshot['users'], 'phone', true);
        $this->assertUniqueValues('addresses', $snapshot['addresses'], 'id');
        $this->assertUniqueValues('drivers', $snapshot['drivers'], 'user_id');
        $this->assertUniqueValues('driver_documents', $snapshot['driver_documents'], 'document_type');

        $userIds = array_column($snapshot['users'], 'id');
        $driverIds = array_column($snapshot['drivers'], 'id');

        foreach ($snapshot['users'] as $user) {
            if ((int) $user['id'] === 7 || strcasecmp(trim((string) $user['name']), 'Zaky (testing)') === 0) {
                throw new RuntimeException('User testing ID 7 tidak boleh masuk snapshot production.');
            }

            if (! in_array($user['role'], ['customer', 'driver'], true)) {
                throw new RuntimeException('Snapshot hanya boleh memuat user customer atau driver non-admin.');
            }
        }

        foreach ($snapshot['addresses'] as $address) {
            if (! in_array($address['user_id'], $userIds, true)) {
                throw new RuntimeException("Address ID {$address['id']} mengacu ke user yang tidak tersedia.");
            }
        }

        foreach ($snapshot['drivers'] as $driver) {
            if (! in_array($driver['user_id'], $userIds, true)) {
                throw new RuntimeException("Driver ID {$driver['id']} mengacu ke user yang tidak tersedia.");
            }

            $driverUser = $this->findById($snapshot['users'], (int) $driver['user_id']);
            if (($driverUser['role'] ?? null) !== 'driver') {
                throw new RuntimeException("User milik driver ID {$driver['id']} harus memiliki role driver.");
            }
        }

        $documentTypes = [];
        foreach ($snapshot['driver_documents'] as $document) {
            if (! in_array($document['driver_id'], $driverIds, true)) {
                throw new RuntimeException("Driver document ID {$document['id']} mengacu ke driver yang tidak tersedia.");
            }

            if (! in_array($document['document_type'], ['ktp', 'sim', 'selfie'], true)) {
                throw new RuntimeException("Driver document ID {$document['id']} memiliki document_type tidak valid.");
            }

            if (trim((string) $document['file_path']) === '') {
                throw new RuntimeException("Driver document ID {$document['id']} wajib memiliki file_path audit.");
            }

            if ($document['verification_status'] !== 'approved') {
                throw new RuntimeException("Driver document ID {$document['id']} harus berstatus approved.");
            }

            $documentTypes[] = $document['document_type'];
        }

        sort($documentTypes);
        if ($documentTypes !== ['ktp', 'selfie', 'sim']) {
            throw new RuntimeException('Snapshot wajib memuat tepat satu dokumen KTP, SIM, dan selfie.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $expectedIds
     */
    private function assertExactIds(string $section, array $rows, array $expectedIds): void
    {
        $ids = array_map('intval', array_column($rows, 'id'));
        sort($ids);

        if ($ids !== $expectedIds) {
            throw new RuntimeException(
                "Snapshot section {$section} memiliki ID yang tidak sesuai baseline 11 Juli 2026."
            );
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertUniqueValues(string $section, array $rows, string $field, bool $nullable = false): void
    {
        $seen = [];

        foreach ($rows as $row) {
            $value = $row[$field];
            if ($nullable && ($value === null || $value === '')) {
                continue;
            }

            $key = is_string($value) ? strtolower($value) : (string) $value;
            if (isset($seen[$key])) {
                throw new RuntimeException("Snapshot section {$section} memiliki {$field} duplikat.");
            }

            $seen[$key] = true;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function findById(array $rows, int $id): ?array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsert(string $table, array $rows): void
    {
        $updateColumns = array_values(array_diff(array_keys($rows[0]), ['id']));
        DB::table($table)->upsert($rows, ['id'], $updateColumns);
    }
}
