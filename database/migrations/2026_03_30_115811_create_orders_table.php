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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30)->unique();
            $table->foreignId('user_id')->constrained(); // customer
            $table->foreignId('restaurant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_type_id')->constrained('service_types');
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();

            // Pricing
            $table->decimal('subtotal', 12, 2);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('service_fee', 12, 2)->default(0);
            $table->decimal('delivery_distance_km', 8, 2)->nullable();
            $table->string('delivery_distance_text', 50)->nullable();
            $table->json('route_snapshot')->nullable();
            $table->decimal('total_price', 12, 2)->default(0);

            // Lifecycle status uses lookup table instead of enum.
            $table->foreignId('status_id')->constrained('order_statuses');

            // Cancellation
            $table->text('cancellation_reason')->nullable();
            $table->enum('cancelled_by', ['customer', 'driver', 'system'])->nullable();

            $table->timestamp('estimated_delivery')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Composite indexes for efficient querying
            $table->index(['user_id', 'status_id']);
            $table->index(['driver_id', 'status_id']);
            $table->index(['restaurant_id', 'status_id']);
            $table->index(['service_type_id', 'status_id', 'created_at'], 'orders_service_status_created_idx');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
