<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('sender_role', 20);
            $table->string('sender_name_snapshot');
            $table->text('body');
            $table->string('client_message_id', 80)->nullable();
            $table->timestamps();

            $table->index(['order_id', 'id'], 'order_chat_messages_order_id_idx');
            $table->index(['sender_user_id', 'created_at'], 'order_chat_messages_sender_created_idx');
            $table->unique(
                ['order_id', 'sender_user_id', 'client_message_id'],
                'order_chat_messages_client_dedupe_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_chat_messages');
    }
};
