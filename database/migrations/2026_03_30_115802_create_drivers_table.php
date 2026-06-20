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
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('vehicle_plate', 20);
            $table->string('vehicle_type', 50);
            $table->string('vehicle_brand', 50);
            $table->string('vehicle_model', 100);
            $table->string('license_number', 50);
            $table->enum('registration_status', ['pending', 'active', 'rejected', 'suspended'])->default('pending');
            $table->enum('status', ['available', 'busy', 'offline'])->default('offline');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('registration_status');
            $table->index('status');
        });

        DatabaseCheckConstraints::add('drivers', [
            'chk_drivers_latitude_valid' => 'latitude IS NULL OR latitude BETWEEN -90 AND 90',
            'chk_drivers_longitude_valid' => 'longitude IS NULL OR longitude BETWEEN -180 AND 180',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
