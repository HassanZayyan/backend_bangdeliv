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

            // Lifecycle status uses lookup table instead of enum.
            $table->foreignId('status_id')->constrained('order_statuses');

            $table->timestamps();
            $table->softDeletes();

            // Composite indexes for efficient querying
            $table->index(['user_id', 'id'], 'orders_user_id_id_idx');
            $table->index(['status_id', 'id'], 'orders_status_id_id_idx');
            $table->index(['user_id', 'status_id', 'service_type_id', 'created_at'], 'orders_user_status_service_created_idx');
            $table->index(['service_type_id', 'status_id', 'created_at'], 'orders_service_status_created_idx');
            $table->index('created_at');
        });

        Schema::create('order_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->boolean('careful_carry_required')->default(false);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('order_delivery_fee_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('order_fee_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('label', 120);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['order_id', 'code'], 'order_fee_lines_order_code_unique');
        });

        Schema::create('order_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('delivery_distance_km', 8, 2)->nullable();
            $table->string('delivery_distance_text', 50)->nullable();
            $table->json('route_snapshot')->nullable();
            $table->timestamp('estimated_delivery')->nullable();
            $table->timestamps();
        });

        Schema::create('order_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps();

            $table->index(['driver_id', 'order_id']);
        });

        Schema::create('order_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('cancelled_by', ['customer', 'driver', 'system']);
            $table->text('reason');
            $table->timestamp('cancelled_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_cancellations');
        Schema::dropIfExists('order_assignments');
        Schema::dropIfExists('order_routes');
        Schema::dropIfExists('order_fee_lines');
        Schema::dropIfExists('order_delivery_fee_overrides');
        Schema::dropIfExists('order_pricings');
        Schema::dropIfExists('orders');
    }
};
