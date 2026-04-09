<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30)->unique();
            $table->foreignId('user_id')->constrained(); // customer
            $table->foreignId('restaurant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_type_id')->constrained('service_types');
            $table->foreignId('driver_id')->nullable()->constrained();
            $table->foreignId('address_id')->nullable()->constrained();

            // Snapshot alamat pengiriman
            $table->text('delivery_address');
            $table->decimal('delivery_latitude', 10, 8);
            $table->decimal('delivery_longitude', 11, 8);

            // Pricing
            $table->decimal('subtotal', 12, 2);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('service_fee', 12, 2)->default(0);
            $table->decimal('delivery_distance_km', 8, 2)->nullable();
            $table->string('delivery_distance_text', 50)->nullable();
            $table->decimal('total_amount', 12, 2);
            $table->decimal('total_price', 12, 2)->default(0);

            // Lifecycle status uses lookup table instead of enum.
            $table->foreignId('status_id')->constrained('order_statuses');

            $table->enum('payment_status', ['unpaid', 'paid'])->default('unpaid');
            $table->enum('payment_method', ['COD'])->default('COD');
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            // Cancellation
            $table->text('cancellation_reason')->nullable();
            $table->enum('cancelled_by', ['customer', 'driver', 'system'])->nullable();

            // Additional
            $table->text('notes')->nullable();
            $table->timestamp('estimated_delivery')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Composite indexes for efficient querying
            $table->index(['user_id', 'status_id']);
            $table->index(['driver_id', 'status_id']);
            $table->index(['restaurant_id', 'status_id']);
            $table->index(['service_type_id', 'status_id', 'created_at'], 'orders_service_status_created_idx');
            $table->index('created_at');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_completed_requires_paid_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_completed_requires_paid_update');

            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER trg_orders_completed_requires_paid_insert
                BEFORE INSERT ON orders
                FOR EACH ROW
                BEGIN
                    DECLARE v_status_code VARCHAR(40);

                    SELECT os.code INTO v_status_code
                    FROM order_statuses os
                    WHERE os.id = NEW.status_id
                    LIMIT 1;

                    IF v_status_code = 'COMPLETED' AND (NEW.payment_status IS NULL OR NEW.payment_status <> 'paid') THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order COMPLETED harus memiliki payment_status paid';
                    END IF;
                END
                SQL
            );

            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER trg_orders_completed_requires_paid_update
                BEFORE UPDATE ON orders
                FOR EACH ROW
                BEGIN
                    DECLARE v_status_code VARCHAR(40);

                    SELECT os.code INTO v_status_code
                    FROM order_statuses os
                    WHERE os.id = NEW.status_id
                    LIMIT 1;

                    IF v_status_code = 'COMPLETED' AND (NEW.payment_status IS NULL OR NEW.payment_status <> 'paid') THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order COMPLETED harus memiliki payment_status paid';
                    END IF;
                END
                SQL
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_completed_requires_paid_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_completed_requires_paid_update');
        }

        Schema::dropIfExists('orders');
    }
};
