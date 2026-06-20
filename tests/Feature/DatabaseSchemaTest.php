<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_schema_uses_cancel_timestamp_without_soft_delete(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'cancelled_at'));
        $this->assertFalse(Schema::hasColumn('orders', 'deleted_at'));
    }

    public function test_restaurants_schema_does_not_use_status_column(): void
    {
        $this->assertFalse(Schema::hasColumn('restaurants', 'status'));
    }

    public function test_order_evidence_schema_does_not_duplicate_payment_verification_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('order_evidence', 'verification_status'));
        $this->assertFalse(Schema::hasColumn('order_evidence', 'verified_by_user_id'));
        $this->assertFalse(Schema::hasColumn('order_evidence', 'verified_at'));
        $this->assertFalse(Schema::hasColumn('order_evidence', 'rejection_reason'));
        $this->assertTrue(Schema::hasColumn('order_payments', 'recorded_by_user_id'));
    }
}
