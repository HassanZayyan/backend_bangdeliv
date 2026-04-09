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
        Schema::create('shopping_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('failed_attempt_count')->default(0);
            $table->decimal('item_surcharge', 12, 2)->default(0);
            $table->decimal('overweight_surcharge', 12, 2)->default(0);
            $table->decimal('cancellation_penalty', 12, 2)->default(0);
            $table->boolean('has_overweight_item')->default(false);
            $table->unsignedInteger('recalculation_version')->default(0);
            $table->timestamp('last_recalculated_at')->nullable();
            $table->json('pricing_snapshot')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_shopping_orders_only_shopping_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_shopping_orders_only_shopping_update');

            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER trg_shopping_orders_only_shopping_insert
                BEFORE INSERT ON shopping_orders
                FOR EACH ROW
                BEGIN
                    DECLARE v_service_code VARCHAR(30);

                    SELECT st.code INTO v_service_code
                    FROM orders o
                    JOIN service_types st ON st.id = o.service_type_id
                    WHERE o.id = NEW.order_id
                    LIMIT 1;

                    IF v_service_code IS NULL OR v_service_code <> 'SHOPPING' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'shopping_orders hanya untuk service SHOPPING';
                    END IF;
                END
                SQL
            );

            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER trg_shopping_orders_only_shopping_update
                BEFORE UPDATE ON shopping_orders
                FOR EACH ROW
                BEGIN
                    DECLARE v_service_code VARCHAR(30);

                    SELECT st.code INTO v_service_code
                    FROM orders o
                    JOIN service_types st ON st.id = o.service_type_id
                    WHERE o.id = NEW.order_id
                    LIMIT 1;

                    IF v_service_code IS NULL OR v_service_code <> 'SHOPPING' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'shopping_orders hanya untuk service SHOPPING';
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
            DB::unprepared('DROP TRIGGER IF EXISTS trg_shopping_orders_only_shopping_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_shopping_orders_only_shopping_update');
        }

        Schema::dropIfExists('shopping_orders');
    }
};
