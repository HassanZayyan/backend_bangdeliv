<?php

namespace Database\Seeders;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Seeder;

class DriverSeeder extends Seeder
{
    public function run(): void
    {
        $dummyUsers = User::query()
            ->withTrashed()
            ->whereIn('email', [
                'agus.driver@bangdeliv.com',
                'dwi.driver@bangdeliv.com',
                'siti.driver@bangdeliv.com',
            ])
            ->get();

        $dummyUserIds = $dummyUsers->pluck('id')->all();
        if ($dummyUserIds !== []) {
            Driver::query()
                ->withTrashed()
                ->whereIn('user_id', $dummyUserIds)
                ->get()
                ->each
                ->forceDelete();
        }

        $dummyUsers->each->forceDelete();
    }
}
