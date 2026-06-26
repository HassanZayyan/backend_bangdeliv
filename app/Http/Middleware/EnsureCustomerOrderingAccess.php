<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerOrderingAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->deny($request, 'Unauthenticated.', 401);
        }

        if ($user->role === 'customer') {
            return $next($request);
        }

        if ($user->role === 'driver') {
            $driver = $user->driver;
            $registrationStatus = strtolower(trim((string) ($driver?->registration_status ?? '')));

            if (in_array($registrationStatus, ['pending', 'rejected'], true)) {
                return $next($request);
            }
        }

        return $this->deny($request, 'Akses ditolak untuk role ini.', 403);
    }

    private function deny(Request $request, string $message, int $status): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'success' => false,
                'message' => $message,
            ], $status);
        }

        abort($status, $message);
    }
}
