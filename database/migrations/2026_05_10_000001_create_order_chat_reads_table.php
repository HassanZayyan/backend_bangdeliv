<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_chat_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_read_message_id')
                ->nullable()
                ->constrained('order_chat_messages')
                ->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'user_id'], 'order_chat_reads_order_user_unique');
            $table->index(['order_id', 'last_read_message_id'], 'order_chat_reads_order_last_read_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_chat_reads');
    }
};
