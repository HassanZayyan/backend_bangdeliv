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
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('evidence_type', [
                'COURIER_DELIVERY_PHOTO',
                'COURIER_RECEIVER_PHOTO',
                'SHOPPING_RECEIPT',
            ]);
            $table->string('file_url');
            $table->enum('verification_mode', ['AUTO_24H', 'MANUAL'])->default('AUTO_24H');
            $table->enum('verification_status', ['PENDING', 'APPROVED', 'REJECTED', 'AUTO_APPROVED'])->default('PENDING');
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'verification_status'], 'order_evidence_order_status_idx');
            $table->index(['expires_at'], 'order_evidence_expires_at_idx');
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
