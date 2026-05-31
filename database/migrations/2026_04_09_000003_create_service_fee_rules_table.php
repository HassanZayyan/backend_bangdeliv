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
        Schema::create('service_fee_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained('service_types')->cascadeOnDelete();
            $table->string('rule_code', 60);
            $table->json('rule_config');
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['service_type_id', 'rule_code']);
            $table->index(['service_type_id', 'is_active']);
        });

        $shoppingServiceTypeId = DB::table('service_types')->where('code', 'SHOPPING')->value('id');
        $courierServiceTypeId = DB::table('service_types')->where('code', 'COURIER')->value('id');

        if ($shoppingServiceTypeId) {
            DB::table('service_fee_rules')->insert([
                [
                    'service_type_id' => $shoppingServiceTypeId,
                    'rule_code' => 'ITEM_BLOCK_SURCHARGE',
                    'rule_config' => json_encode([
                        'free_until_item_count' => 6,
                        'first_surcharge_item_count' => 7,
                        'block_size' => 6,
                        'surcharge_per_block' => 2000,
                    ]),
                    'is_active' => true,
                    'starts_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'service_type_id' => $shoppingServiceTypeId,
                    'rule_code' => 'OVERWEIGHT_FLAT_SURCHARGE',
                    'rule_config' => json_encode([
                        'is_flat' => true,
                        'surcharge' => 6000,
                        'is_applied_once_per_order' => true,
                    ]),
                    'is_active' => true,
                    'starts_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'service_type_id' => $shoppingServiceTypeId,
                    'rule_code' => 'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS',
                    'rule_config' => json_encode([
                        'failed_attempt_threshold' => 3,
                        'penalty_percent_of_delivery_fee' => 50,
                    ]),
                    'is_active' => true,
                    'starts_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        if ($courierServiceTypeId) {
            DB::table('service_fee_rules')->insert([
                'service_type_id' => $courierServiceTypeId,
                'rule_code' => 'AUTO_CONFIRM_EVIDENCE_TIMEOUT',
                'rule_config' => json_encode([
                    'auto_confirm_after_hours' => 24,
                ]),
                'is_active' => true,
                'starts_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_fee_rules');
    }
};
