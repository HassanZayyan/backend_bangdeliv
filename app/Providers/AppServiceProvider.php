<?php

namespace App\Providers;

use App\Services\Admin\AdminNotificationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.admin', function ($view): void {
            $layoutData = app(AdminNotificationService::class)->layoutData();

            $view->with([
                'adminNotificationSummary' => $layoutData['summary'],
                'adminNavigationCounts' => $layoutData['navigation'],
                'adminOrderServiceFilters' => $layoutData['order_service_filters'],
            ]);
        });

        RateLimiter::for('chatbot', function (Request $request): array {
            $perMinute = max(1, (int) config('bangdeliv.chatbot.rate_limit_per_minute', 12));
            $perHour = max($perMinute, (int) config('bangdeliv.chatbot.rate_limit_per_hour', 120));
            $identifier = $request->user()?->id
                ? 'user:'.$request->user()->id
                : 'ip:'.$request->ip();

            return [
                Limit::perMinute($perMinute)->by($identifier),
                Limit::perHour($perHour)->by($identifier),
            ];
        });
    }
}
