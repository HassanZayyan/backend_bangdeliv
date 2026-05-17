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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pickup_location_id')->nullable()->constrained('order_locations')->nullOnDelete();
            $table->enum('item_source', ['MENU_DB', 'MANUAL'])->default('MENU_DB');
            $table->string('menu_name'); // snapshot nama menu saat order
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2); // snapshot harga saat order
            $table->decimal('subtotal', 12, 2); // qty × unit_price
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_available')->default(true); // driver update jika item habis
            $table->boolean('is_heavy')->default(false);
            $table->timestamps();

            $table->index(['order_id', 'item_source'], 'order_items_order_source_idx');
            $table->index(['order_id', 'is_heavy'], 'order_items_order_heavy_idx');
            $table->index(['order_id', 'pickup_location_id'], 'order_items_order_pickup_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_order_items_only_shopping_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_order_items_only_shopping_update');

            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER trg_order_items_only_shopping_insert
                BEFORE INSERT ON order_items
                FOR EACH ROW
                BEGIN
                    DECLARE v_service_code VARCHAR(30);

                    SELECT st.code INTO v_service_code
                    FROM orders o
                    JOIN service_types st ON st.id = o.service_type_id
                    WHERE o.id = NEW.order_id
                    LIMIT 1;

                    IF v_service_code IS NULL OR v_service_code <> 'SHOPPING' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_items hanya diperbolehkan untuk service SHOPPING';
                    END IF;
                END
                SQL
            );

            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER trg_order_items_only_shopping_update
                BEFORE UPDATE ON order_items
                FOR EACH ROW
                BEGIN
                    DECLARE v_service_code VARCHAR(30);

                    SELECT st.code INTO v_service_code
                    FROM orders o
                    JOIN service_types st ON st.id = o.service_type_id
                    WHERE o.id = NEW.order_id
                    LIMIT 1;

                    IF v_service_code IS NULL OR v_service_code <> 'SHOPPING' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_items hanya diperbolehkan untuk service SHOPPING';
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
            DB::unprepared('DROP TRIGGER IF EXISTS trg_order_items_only_shopping_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_order_items_only_shopping_update');
        }

        Schema::dropIfExists('order_items');
    }
};
