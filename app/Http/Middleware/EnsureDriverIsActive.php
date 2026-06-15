<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDriverIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->deny($request, 'Unauthenticated.', 401);
        }

        if ($user->role !== 'driver') {
            return $this->deny($request, 'Akses hanya untuk driver.', 403);
        }

        $driver = $user->driver;
        if (! $driver) {
            return $this->deny($request, 'Profil driver tidak ditemukan.', 403);
        }

        if ($driver->registration_status !== 'active') {
            $message = $driver->registration_status === 'suspended'
                ? 'Akun driver sedang disuspensi oleh admin.'
                : 'Akun driver belum aktif. Selesaikan proses verifikasi terlebih dahulu.';

            return $this->deny($request, $message, 403);
        }

        return $next($request);
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
