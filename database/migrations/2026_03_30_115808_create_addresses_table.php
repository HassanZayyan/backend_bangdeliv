<?php

use App\Support\DatabaseCheckConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50); // "Rumah", "Kantor", "Kos"
            $table->string('recipient_name');
            $table->string('phone', 20);
            $table->text('full_address');
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        DatabaseCheckConstraints::add('addresses', [
            'chk_addresses_latitude_valid' => 'latitude BETWEEN -90 AND 90',
            'chk_addresses_longitude_valid' => 'longitude BETWEEN -180 AND 180',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
