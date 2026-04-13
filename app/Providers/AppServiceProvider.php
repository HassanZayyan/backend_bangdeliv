<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('chatbot', function (Request $request): array {
            $perMinute = max(1, (int) config('bangdeliv.chatbot.rate_limit_per_minute', 12));
            $perHour = max($perMinute, (int) config('bangdeliv.chatbot.rate_limit_per_hour', 120));
            $identifier = $request->user()?->id
                ? 'user:' . $request->user()->id
                : 'ip:' . $request->ip();

            return [
                Limit::perMinute($perMinute)->by($identifier),
                Limit::perHour($perHour)->by($identifier),
            ];
        });
    }
}
