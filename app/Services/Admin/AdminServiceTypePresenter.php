<?php

namespace App\Services\Admin;

use App\Enums\ServiceTypeCode;
use Illuminate\Support\Str;

class AdminServiceTypePresenter
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function filters(): array
    {
        return [
            'all' => ['label' => 'Semua Layanan', 'nav_label' => 'Semua', 'code' => null, 'badge_class' => 'badge-info'],
            'shopping' => ['label' => 'Titip Belanja', 'nav_label' => 'Titip Belanja', 'code' => ServiceTypeCode::Shopping->value, 'badge_class' => 'badge-warning'],
            'courier' => ['label' => 'Kurir', 'nav_label' => 'Kurir', 'code' => ServiceTypeCode::Courier->value, 'badge_class' => 'badge-info'],
            'ride' => ['label' => 'Antar Jemput', 'nav_label' => 'Antar Jemput', 'code' => ServiceTypeCode::Ride->value, 'badge_class' => 'badge-success'],
        ];
    }

    public function normalizeFilter(?string $filter): string
    {
        $filter = trim((string) $filter);

        return array_key_exists($filter, $this->filters()) ? $filter : 'all';
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $serviceTypeCodeToId
     */
    public function idForFilter(string $filter, $serviceTypeCodeToId): ?int
    {
        $code = $this->filters()[$filter]['code'] ?? null;

        return $code !== null && isset($serviceTypeCodeToId[$code])
            ? (int) $serviceTypeCodeToId[$code]
            : null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function badgeMap(): array
    {
        return collect($this->filters())
            ->filter(fn (array $filter): bool => $filter['code'] !== null)
            ->mapWithKeys(fn (array $filter): array => [
                $filter['code'] => [
                    'label' => $filter['label'],
                    'class' => $filter['badge_class'],
                ],
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function badgeConfig(?string $serviceCode): array
    {
        $serviceCode = ServiceTypeCode::normalize($serviceCode);

        return $this->badgeMap()[$serviceCode] ?? [
            'label' => Str::headline(strtolower($serviceCode)),
            'class' => 'badge-info',
        ];
    }

    public function searchPlaceholder(string $filter): string
    {
        return match ($filter) {
            'courier' => 'Cari nomor, pelanggan, alamat, paket...',
            'ride' => 'Cari nomor, pelanggan, alamat...',
            default => 'Cari nomor, pelanggan, alamat, resto...',
        };
    }
}
