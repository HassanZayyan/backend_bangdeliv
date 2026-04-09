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
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('display_name', 100);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('service_types')->insert([
            [
                'code' => 'RIDE',
                'display_name' => 'Antar Jemput Orang',
                'description' => 'Layanan antar jemput penumpang menggunakan koordinat GPS.',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'COURIER',
                'display_name' => 'Antar Barang / Kurir',
                'description' => 'Layanan kirim barang dengan bukti foto dan konfirmasi penerimaan.',
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'SHOPPING',
                'display_name' => 'Titip Belanja',
                'description' => 'Layanan titip belanja dengan dukungan item dari database maupun input manual.',
                'sort_order' => 3,
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
        Schema::dropIfExists('service_types');
    }
};
