<?php

namespace Tests\Support;

use Database\Seeders\PreservedProductionDataSeeder;
use Illuminate\Support\Facades\Storage;

class PreservedProductionSnapshot
{
    /** @return array<string, mixed> */
    public static function data(): array
    {
        $timestamp = '2026-07-11 12:00:00';
        $users = [];

        foreach ([2, 3, 4, 5, 6, 8] as $id) {
            $users[] = [
                'id' => $id,
                'name' => $id === 8 ? 'Pelanggan 05' : "Preserved User {$id}",
                'email' => "preserved{$id}@example.test",
                'google_sub' => "google-sub-{$id}",
                'phone' => "0810000000{$id}",
                'email_verified_at' => $timestamp,
                'phone_verified_at' => null,
                'password' => null,
                'role' => $id === 2 ? 'driver' : 'customer',
                'avatar' => "https://example.test/avatar-{$id}.jpg",
                'is_active' => 1,
                'is_blacklisted' => 0,
                'remember_token' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'deleted_at' => null,
            ];
        }

        $addresses = [];
        foreach ([1 => 3, 2 => 5, 3 => 6, 5 => 8] as $id => $userId) {
            $addresses[] = [
                'id' => $id,
                'user_id' => $userId,
                'label' => 'Rumah',
                'recipient_name' => "Recipient {$userId}",
                'phone' => "0820000000{$userId}",
                'full_address' => "Alamat user {$userId}",
                'latitude' => -7.320,
                'longitude' => 110.470,
                'is_default' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        $documents = [];
        foreach ([1 => 'ktp', 2 => 'sim', 3 => 'selfie'] as $id => $type) {
            $documents[] = [
                'id' => $id,
                'driver_id' => 1,
                'document_type' => $type,
                'file_path' => "driver-documents/1/{$type}/preserved.jpg",
                'verification_status' => 'approved',
                'rejection_reason' => null,
                'verified_at' => $timestamp,
                'verified_by' => 999,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        return [
            'version' => 1,
            'captured_at' => '2026-07-11T18:47:00+07:00',
            'users' => $users,
            'addresses' => $addresses,
            'drivers' => [[
                'id' => 1,
                'user_id' => 2,
                'vehicle_plate' => 'H 1001 AA',
                'vehicle_type' => 'Motor Matic',
                'vehicle_brand' => 'Honda',
                'vehicle_model' => 'Vario 125 New',
                'registration_status' => 'active',
                'status' => 'offline',
                'latitude' => -7.320,
                'longitude' => 110.470,
                'location_updated_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'deleted_at' => null,
            ]],
            'driver_documents' => $documents,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @param  list<string>  $missingDocumentTypes
     */
    public static function write(?array $snapshot = null, array $missingDocumentTypes = []): void
    {
        $snapshot ??= self::data();

        Storage::disk('local')->put(
            PreservedProductionDataSeeder::SNAPSHOT_PATH,
            json_encode($snapshot, JSON_THROW_ON_ERROR)
        );

        foreach ($snapshot['driver_documents'] as $document) {
            if (in_array($document['document_type'], $missingDocumentTypes, true)) {
                continue;
            }

            Storage::disk('public')->put($document['file_path'], 'test-document');
        }
    }
}
