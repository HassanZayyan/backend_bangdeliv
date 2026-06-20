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
        Schema::create('courier_order_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('package_description');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_courier_order_details_only_courier_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_courier_order_details_only_courier_update');

            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER trg_courier_order_details_only_courier_insert
                BEFORE INSERT ON courier_order_details
                FOR EACH ROW
                BEGIN
                    DECLARE v_service_code VARCHAR(30);

                    SELECT st.code INTO v_service_code
                    FROM orders o
                    JOIN service_types st ON st.id = o.service_type_id
                    WHERE o.id = NEW.order_id
                    LIMIT 1;

                    IF v_service_code IS NULL OR v_service_code <> 'COURIER' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'courier_order_details hanya untuk service COURIER';
                    END IF;
                END
                SQL
            );

            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER trg_courier_order_details_only_courier_update
                BEFORE UPDATE ON courier_order_details
                FOR EACH ROW
                BEGIN
                    DECLARE v_service_code VARCHAR(30);

                    SELECT st.code INTO v_service_code
                    FROM orders o
                    JOIN service_types st ON st.id = o.service_type_id
                    WHERE o.id = NEW.order_id
                    LIMIT 1;

                    IF v_service_code IS NULL OR v_service_code <> 'COURIER' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'courier_order_details hanya untuk service COURIER';
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
            DB::unprepared('DROP TRIGGER IF EXISTS trg_courier_order_details_only_courier_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_courier_order_details_only_courier_update');
        }

        Schema::dropIfExists('courier_order_details');
    }
};
