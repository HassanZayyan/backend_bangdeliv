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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->unique()->nullable()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->enum('role', ['customer', 'driver', 'admin'])->default('customer')->after('password');
            $table->string('avatar')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('avatar');
            $table->boolean('is_blacklisted')->default(false)->after('is_active');
            $table->unsignedInteger('completed_orders_count')->default(0)->after('is_blacklisted');
            $table->unsignedInteger('cancelled_orders_count')->default(0)->after('completed_orders_count');
            $table->softDeletes();

            $table->index('role');
            $table->index('is_active');
            $table->index('is_blacklisted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['is_active']);
            $table->dropIndex(['is_blacklisted']);
            $table->dropUnique(['phone']);

            $table->dropColumn([
                'phone',
                'phone_verified_at',
                'role',
                'avatar',
                'is_active',
                'is_blacklisted',
                'completed_orders_count',
                'cancelled_orders_count',
                'deleted_at',
            ]);
        });
    }
};
