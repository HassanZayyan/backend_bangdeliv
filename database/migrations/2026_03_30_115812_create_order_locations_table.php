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
        Schema::create('order_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('location_role', ['PICKUP', 'DROPOFF']);
            $table->string('label', 50)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->text('full_address');
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->unsignedTinyInteger('sequence_no')->default(1);
            $table->string('fulfillment_status', 30)->default('PENDING');
            $table->unsignedTinyInteger('failed_attempt_count')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'location_role', 'sequence_no'], 'order_locations_order_role_sequence_unique');
            $table->index(['order_id', 'sequence_no'], 'order_locations_order_sequence_idx');
            $table->index(['order_id', 'restaurant_id'], 'order_locations_order_restaurant_idx');
            $table->index(['order_id', 'location_role', 'fulfillment_status'], 'order_locations_order_role_fulfillment_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_locations');
    }
};
