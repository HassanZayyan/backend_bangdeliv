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
        Schema::create('order_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->enum('log_type', [
                'STATUS_CHANGE',
                'PRICE_RECALCULATION',
                'ITEM_UPDATE',
                'PAYMENT_UPDATE',
                'SYSTEM_EVENT',
            ])->default('SYSTEM_EVENT');
            $table->string('trigger_type', 60)->nullable();

            $table->decimal('old_subtotal', 12, 2)->nullable();
            $table->decimal('new_subtotal', 12, 2)->nullable();
            $table->decimal('old_delivery_fee', 12, 2)->nullable();
            $table->decimal('new_delivery_fee', 12, 2)->nullable();
            $table->decimal('old_service_fee', 12, 2)->nullable();
            $table->decimal('new_service_fee', 12, 2)->nullable();
            $table->decimal('old_total_price', 12, 2)->nullable();
            $table->decimal('new_total_price', 12, 2)->nullable();
            $table->decimal('delta_total_price', 12, 2)->nullable();

            $table->unsignedInteger('recalculation_version')->default(0);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at'], 'order_logs_order_created_idx');
            $table->index(['order_id', 'log_type'], 'order_logs_order_type_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_logs');
    }
};
