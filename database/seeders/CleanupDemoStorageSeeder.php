<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class CleanupDemoStorageSeeder extends Seeder
{
    /**
     * Remove generated upload folders before demo data is recreated.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $disk = Storage::disk('public');
        $directories = [
            'avatars',
            'driver-documents',
            'driver-docs',
            'orders',
            'ktp',
            'sim',
            'selfie',
        ];

        foreach ($directories as $directory) {
            if ($disk->exists($directory)) {
                $disk->deleteDirectory($directory);
            }
        }
    }
}
