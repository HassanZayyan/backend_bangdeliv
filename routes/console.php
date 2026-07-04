<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\Notification\NotificationDiagnosticService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notifications:diagnose {userId} {type=order_status_changed} {--order-id=} {--dry-run}', function (
    NotificationDiagnosticService $diagnostics,
) {
    $result = $diagnostics->diagnose(
        userId: (int) $this->argument('userId'),
        type: (string) $this->argument('type'),
        orderId: $this->option('order-id') !== null && $this->option('order-id') !== ''
            ? (int) $this->option('order-id')
            : null,
        send: ! (bool) $this->option('dry-run'),
    );

    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return ($result['sent'] ?? false) || ($result['dry_run'] ?? false) ? 0 : 1;
})->purpose('Diagnose and optionally send a BangDeliv FCM notification to a user');
