<?php

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
            $table->string('vehicle_type', 50)->nullable();
            $table->string('vehicle_brand', 50)->nullable();
            $table->string('vehicle_model', 100)->nullable();
            $table->string('license_number', 50);
            $table->enum('registration_status', ['pending', 'active', 'rejected', 'suspended'])->default('pending');
            $table->enum('status', ['available', 'busy', 'offline'])->default('offline');
            $table->decimal('current_latitude', 10, 8)->nullable();
            $table->decimal('current_longitude', 11, 8)->nullable();
            $table->decimal('avg_rating', 3, 2)->default(0.00);
            $table->unsignedInteger('total_deliveries')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('registration_status');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
