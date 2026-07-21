<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->call([
                ProductionAdminSeeder::class,
                RestaurantMenuSeeder::class,
                ThesisDatasetSeeder::class,
            ]);

            return;
        }

        $this->call([
            CleanupDemoStorageSeeder::class,
            UserSeeder::class,
            RestaurantMenuSeeder::class,
            ThesisDatasetSeeder::class,
            AccessAccountSeeder::class,
        ]);
    }
}
