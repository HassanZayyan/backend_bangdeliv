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
        Schema::create('order_events', function (Blueprint $table) {
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
            $table->unsignedInteger('recalculation_version')->default(0);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at'], 'order_events_order_created_idx');
            $table->index(['order_id', 'log_type'], 'order_events_order_type_idx');
        });

        Schema::create('order_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_event_id')->unique()->constrained('order_events')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('order_price_change_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_price_change_id')->constrained('order_price_changes')->cascadeOnDelete();
            $table->string('component_code', 40);
            $table->decimal('old_amount', 12, 2)->default(0);
            $table->decimal('new_amount', 12, 2)->default(0);
            $table->decimal('delta_amount', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['order_price_change_id', 'component_code'], 'price_change_component_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_price_change_lines');
        Schema::dropIfExists('order_price_changes');
        Schema::dropIfExists('order_events');
    }
};
