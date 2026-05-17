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
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('merchant_type', ['restaurant', 'warung', 'convenience_store', 'other'])
                ->default('restaurant');
            $table->text('address');
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('phone', 20); // NOT NULL — driver wajib telepon untuk konfirmasi ketersediaan
            $table->string('banner_image')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->decimal('avg_rating', 3, 2)->default(0.00);
            $table->unsignedInteger('total_reviews')->default(0);
            $table->unsignedInteger('estimated_prep_time')->default(15); // menit
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['merchant_type', 'status'], 'restaurants_type_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
