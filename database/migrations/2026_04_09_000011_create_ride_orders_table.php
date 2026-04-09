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
        Schema::create('ride_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ride_orders_only_ride_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ride_orders_only_ride_update');

            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER trg_ride_orders_only_ride_insert
                BEFORE INSERT ON ride_orders
                FOR EACH ROW
                BEGIN
                    DECLARE v_service_code VARCHAR(30);

                    SELECT st.code INTO v_service_code
                    FROM orders o
                    JOIN service_types st ON st.id = o.service_type_id
                    WHERE o.id = NEW.order_id
                    LIMIT 1;

                    IF v_service_code IS NULL OR v_service_code <> 'RIDE' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ride_orders hanya untuk service RIDE';
                    END IF;
                END
                SQL
            );

            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER trg_ride_orders_only_ride_update
                BEFORE UPDATE ON ride_orders
                FOR EACH ROW
                BEGIN
                    DECLARE v_service_code VARCHAR(30);

                    SELECT st.code INTO v_service_code
                    FROM orders o
                    JOIN service_types st ON st.id = o.service_type_id
                    WHERE o.id = NEW.order_id
                    LIMIT 1;

                    IF v_service_code IS NULL OR v_service_code <> 'RIDE' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ride_orders hanya untuk service RIDE';
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
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ride_orders_only_ride_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ride_orders_only_ride_update');
        }

        Schema::dropIfExists('ride_orders');
    }
};
