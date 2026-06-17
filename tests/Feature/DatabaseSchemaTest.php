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
}
