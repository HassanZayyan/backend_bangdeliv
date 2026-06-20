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
        Schema::create('order_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('evidence_type', [
                'COURIER_DELIVERY_PHOTO',
                'COURIER_RECEIVER_PHOTO',
                'SHOPPING_RECEIPT',
                'PICKUP_PHOTO',
                'DELIVERY_PHOTO',
                'STORE_CLOSED_PHOTO',
                'PAYMENT_TRANSFER_PHOTO',
            ]);
            $table->string('file_url');
            $table->timestamp('uploaded_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'evidence_type'], 'order_evidence_order_type_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_evidence');
    }
};
