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
        Schema::create('ai_chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 100);
            $table->timestamp('last_message_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'session_id']);
            $table->index(['user_id', 'last_message_at']);
            $table->index(['user_id', 'completed_at', 'last_message_at'], 'ai_sessions_user_completed_last_idx');
        });

        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 100);
            $table->enum('role', ['user', 'assistant']);
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'session_id']);
            $table->index(['session_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('ai_message_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_message_id')->unique()->constrained('ai_chat_messages')->cascadeOnDelete();
            $table->json('ai_response');
            $table->string('model_used', 100);
            $table->string('intent', 50);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['intent', 'order_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_message_details');
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chat_sessions');
    }
};
