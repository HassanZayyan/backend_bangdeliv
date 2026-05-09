<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function driverAttributes(array $overrides = []): array
    {
        return array_merge([
            'vehicle_type' => 'Motor Matic',
            'vehicle_brand' => 'Honda',
            'vehicle_model' => 'Beat',
        ], $overrides);
    }
}
