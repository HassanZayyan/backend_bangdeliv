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
        Schema::create('courier_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('package_description')->nullable();
            $table->decimal('estimated_weight_kg', 8, 2)->nullable();
            $table->unsignedSmallInteger('package_length_cm')->nullable();
            $table->unsignedSmallInteger('package_width_cm')->nullable();
            $table->unsignedSmallInteger('package_height_cm')->nullable();
            $table->string('package_size_class', 30)->nullable();
            $table->string('package_safety_status', 40)->nullable();
            $table->json('package_safety_flags')->nullable();
            $table->text('package_safety_reason')->nullable();
            $table->text('package_packing_note')->nullable();
            $table->boolean('requires_photo_evidence')->default(true);
            $table->timestamp('confirmation_deadline_at')->nullable();
            $table->timestamp('auto_confirmed_at')->nullable();
            $table->text('complaint_reason')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_courier_orders_only_courier_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_courier_orders_only_courier_update');

            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER trg_courier_orders_only_courier_insert
                BEFORE INSERT ON courier_orders
                FOR EACH ROW
                BEGIN
                    DECLARE v_service_code VARCHAR(30);

                    SELECT st.code INTO v_service_code
                    FROM orders o
                    JOIN service_types st ON st.id = o.service_type_id
                    WHERE o.id = NEW.order_id
                    LIMIT 1;

                    IF v_service_code IS NULL OR v_service_code <> 'COURIER' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'courier_orders hanya untuk service COURIER';
                    END IF;
                END
                SQL
            );

            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER trg_courier_orders_only_courier_update
                BEFORE UPDATE ON courier_orders
                FOR EACH ROW
                BEGIN
                    DECLARE v_service_code VARCHAR(30);

                    SELECT st.code INTO v_service_code
                    FROM orders o
                    JOIN service_types st ON st.id = o.service_type_id
                    WHERE o.id = NEW.order_id
                    LIMIT 1;

                    IF v_service_code IS NULL OR v_service_code <> 'COURIER' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'courier_orders hanya untuk service COURIER';
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
            DB::unprepared('DROP TRIGGER IF EXISTS trg_courier_orders_only_courier_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_courier_orders_only_courier_update');
        }

        Schema::dropIfExists('courier_orders');
    }
};
