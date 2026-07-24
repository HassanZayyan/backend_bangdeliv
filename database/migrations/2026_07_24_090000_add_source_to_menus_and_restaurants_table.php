<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menandai asal-usul data resto/menu: 'official' (katalog resmi yang dikelola
 * RestaurantMenuSeeder) vs 'admin' (dibuat lewat panel admin). Ini membuat
 * seeder katalog bisa bersifat aditif — mem-prune HANYA baris resmi yang sudah
 * tidak ada di katalog dan TIDAK PERNAH menghapus data buatan admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table): void {
            $table->string('source')->default('admin')->index()->after('restaurant_id');
        });

        Schema::table('restaurants', function (Blueprint $table): void {
            $table->string('source')->default('admin')->index()->after('slug');
        });

        // Backfill data KATALOG RESMI yang sudah ada agar bertanda 'official'.
        $official = require database_path('seeders/data/bangdeliv_official_restaurants.php');

        // Menu katalog memakai id deterministik 1..N (lihat RestaurantMenuSeeder),
        // jadi menu ber-id <= total menu katalog adalah data resmi; sisanya
        // (auto-increment >= N+1) adalah menu buatan admin.
        $officialMenuCount = 0;
        foreach ($official as $resto) {
            $officialMenuCount += count($resto['menus'] ?? []);
        }
        if ($officialMenuCount > 0) {
            DB::table('menus')->where('id', '<=', $officialMenuCount)->update(['source' => 'official']);
        }

        $officialSlugs = array_values(array_filter(array_column($official, 'slug')));
        if ($officialSlugs !== []) {
            DB::table('restaurants')->whereIn('slug', $officialSlugs)->update(['source' => 'official']);
        }
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table): void {
            $table->dropColumn('source');
        });

        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
