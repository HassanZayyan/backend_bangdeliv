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
            $table->foreignId('restaurant_id')->constrained();
            $table->foreignId('driver_id')->nullable()->constrained();
            $table->foreignId('address_id')->nullable()->constrained();

            // Snapshot alamat pengiriman
            $table->text('delivery_address');
            $table->decimal('delivery_latitude', 10, 8);
            $table->decimal('delivery_longitude', 11, 8);

            // Pricing
            $table->decimal('subtotal', 12, 2);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('delivery_distance_km', 8, 2)->nullable();
            $table->string('delivery_distance_text', 50)->nullable();
            $table->decimal('total_amount', 12, 2);

            // Status
            $table->enum('status', [
                'confirmed',
                'driver_assigned',
                'item_unavailable',
                'picking_up',
                'on_delivery',
                'delivered',
                'completed',
                'cancelled',
            ])->default('confirmed');

            $table->enum('payment_status', ['unpaid', 'paid'])->default('unpaid');

            // Cancellation
            $table->text('cancellation_reason')->nullable();
            $table->enum('cancelled_by', ['customer', 'driver', 'system'])->nullable();

            // Additional
            $table->text('notes')->nullable();
            $table->timestamp('estimated_delivery')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Composite indexes for efficient querying
            $table->index(['user_id', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index(['restaurant_id', 'status']);
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
