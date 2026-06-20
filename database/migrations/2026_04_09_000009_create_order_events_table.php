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
            $table->string('event_type', 60)->default('SYSTEM_EVENT');
            $table->string('trigger_type', 60)->default('SYSTEM_EVENT');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');
            $table->json('metadata');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at'], 'order_events_order_created_idx');
            $table->index(['order_id', 'event_type'], 'order_events_order_type_idx');
            $table->index(['order_id', 'trigger_type'], 'order_events_order_trigger_idx');
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('status_id')->constrained('order_statuses')->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at'], 'order_status_histories_order_created_idx');
            $table->index(['order_id', 'status_id'], 'order_status_histories_order_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_events');
    }
};
