<?php

use App\Support\DatabaseCheckConstraints;
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
            $table->enum('merchant_type', ['restaurant', 'warung', 'convenience_store', 'other'])
                ->default('restaurant');
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('banner_image')->nullable();
            $table->json('gallery_images')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('merchant_type');
        });

        DatabaseCheckConstraints::add('restaurants', [
            'chk_restaurants_latitude_valid' => 'latitude BETWEEN -90 AND 90',
            'chk_restaurants_longitude_valid' => 'longitude BETWEEN -180 AND 180',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
