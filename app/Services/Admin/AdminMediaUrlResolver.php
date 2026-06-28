<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Storage;

class AdminMediaUrlResolver
{
    public function publicUrl(?string $path): ?string
    {
        $path = trim((string) ($path ?? ''));
        if ($path === '') {
            return null;
        }

        if ($this->isRemoteUrl($path)) {
            return $path;
        }

        $path = ltrim($path, '/');

        return Storage::disk('public')->exists($path)
            ? asset('storage/'.$path)
            : null;
    }

    public function isRemoteUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }
}
