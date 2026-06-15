<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'delivery_distance_km',
                'delivery_distance_text',
                'estimated_delivery',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('delivery_distance_km', 8, 2)->nullable()->after('delivery_fee_source');
            $table->string('delivery_distance_text', 50)->nullable()->after('delivery_distance_km');
            $table->timestamp('estimated_delivery')->nullable()->after('cancelled_at');
        });
    }
};
