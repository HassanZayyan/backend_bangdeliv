<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class DatabaseCheckConstraints
{
    /**
     * @param  array<string, string>  $constraints
     */
    public static function add(string $table, array $constraints): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($constraints as $name => $expression) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
}
