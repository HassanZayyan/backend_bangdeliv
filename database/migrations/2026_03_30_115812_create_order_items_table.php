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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained();
            $table->string('menu_name'); // snapshot nama menu saat order
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2); // snapshot harga saat order
            $table->decimal('subtotal', 12, 2); // qty × unit_price
            $table->text('notes')->nullable();
            $table->boolean('is_available')->default(true); // driver update jika item habis
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
