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
            $table->foreignId('service_type_id')->constrained('service_types');
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->string('delivery_fee_source', 20)->default('system');
            $table->json('route_snapshot')->nullable();
            $table->enum('cancelled_by', ['customer', 'driver', 'system'])->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // Lifecycle status uses lookup table instead of enum.
            $table->foreignId('status_id')->constrained('order_statuses');

            $table->timestamps();

            // Composite indexes for efficient querying
            $table->index(['user_id', 'id'], 'orders_user_id_id_idx');
            $table->index(['status_id', 'id'], 'orders_status_id_id_idx');
            $table->index(['user_id', 'status_id', 'service_type_id', 'created_at'], 'orders_user_status_service_created_idx');
            $table->index(['service_type_id', 'status_id', 'created_at'], 'orders_service_status_created_idx');
            $table->index(['driver_id', 'status_id', 'created_at'], 'orders_driver_status_created_idx');
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
