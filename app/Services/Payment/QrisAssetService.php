<?php

namespace App\Services\Payment;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QrisAssetService
{
    private const DISK = 'public';

    private const STORAGE_DIRECTORY = 'settings/payments/qris';

    private const FILENAME_PREFIX = 'qris-bangdeliv';

    private const FALLBACK_PUBLIC_PATH = 'images/payments/qris-bangdeliv.jpeg';

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $asset = $this->activeAsset();
        $version = $asset['last_modified'] ?? null;
        $url = route('payments.qris.show');

        if (is_int($version) && $version > 0) {
            $url .= '?v='.$version;
        }

        return [
            'url' => $url,
            'source' => $asset['source'],
            'source_label' => $asset['source'] === 'uploaded' ? 'Diunggah admin' : 'QRIS resmi',
            'location_label' => $asset['location_label'],
            'updated_at_label' => $this->formatTimestamp($version),
        ];
    }

    public function replace(UploadedFile $file): void
    {
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        $temporaryPath = $file->storeAs(
            self::STORAGE_DIRECTORY,
            self::FILENAME_PREFIX.'-'.Str::uuid()->toString().'.'.$extension,
            self::DISK
        );

        $finalPath = self::STORAGE_DIRECTORY.'/'.self::FILENAME_PREFIX.'.'.$extension;
        $disk = Storage::disk(self::DISK);

        if ($temporaryPath !== $finalPath) {
            $disk->delete($finalPath);
            $disk->move($temporaryPath, $finalPath);
        }

        $oldPaths = array_filter(
            $this->uploadedPaths(),
            fn (string $path): bool => $path !== $finalPath
        );

        if (! empty($oldPaths)) {
            $disk->delete(array_values($oldPaths));
        }
    }

    public function response(): BinaryFileResponse|StreamedResponse
    {
        $asset = $this->activeAsset();
        $headers = [
            'Cache-Control' => 'no-store, max-age=0, must-revalidate',
            'Pragma' => 'no-cache',
        ];

        if ($asset['source'] === 'uploaded') {
            return Storage::disk(self::DISK)->response((string) $asset['path'], null, $headers);
        }

        return response()->file(public_path(self::FALLBACK_PUBLIC_PATH), $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function activeAsset(): array
    {
        $uploadedPath = $this->activeUploadedPath();

        if ($uploadedPath !== null) {
            return [
                'source' => 'uploaded',
                'path' => $uploadedPath,
                'location_label' => 'storage/app/public/'.$uploadedPath,
                'last_modified' => Storage::disk(self::DISK)->lastModified($uploadedPath),
            ];
        }

        $fallbackPath = public_path(self::FALLBACK_PUBLIC_PATH);

        return [
            'source' => 'official',
            'path' => $fallbackPath,
            'location_label' => 'public/'.self::FALLBACK_PUBLIC_PATH,
            'last_modified' => is_file($fallbackPath) ? filemtime($fallbackPath) : null,
        ];
    }

    private function activeUploadedPath(): ?string
    {
        $paths = $this->uploadedPaths();

        if (empty($paths)) {
            return null;
        }

        usort(
            $paths,
            fn (string $left, string $right): int => Storage::disk(self::DISK)->lastModified($right) <=> Storage::disk(self::DISK)->lastModified($left)
        );

        return $paths[0];
    }

    /**
     * @return array<int, string>
     */
    private function uploadedPaths(): array
    {
        return collect(Storage::disk(self::DISK)->files(self::STORAGE_DIRECTORY))
            ->filter(function (string $path): bool {
                $filename = pathinfo($path, PATHINFO_FILENAME);

                return $filename === self::FILENAME_PREFIX
                    || str_starts_with($filename, self::FILENAME_PREFIX.'-');
            })
            ->values()
            ->all();
    }

    private function formatTimestamp(mixed $timestamp): string
    {
        if (! is_int($timestamp) || $timestamp <= 0) {
            return '-';
        }

        return Carbon::createFromTimestamp($timestamp)
            ->timezone((string) config('app.timezone', 'Asia/Jakarta'))
            ->format('d M Y H:i');
    }
}
