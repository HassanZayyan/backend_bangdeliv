<?php

namespace App\Services\Admin;

/**
 * Satu-satunya sumber label untuk kolom restaurants.merchant_type.
 *
 * Dipakai bersama oleh form tambah/ubah restoran dan daftar restoran, supaya
 * pilihan di form dan label di tabel tidak pernah berbeda.
 */
class AdminMerchantTypePresenter
{
    /**
     * @return array<string, string>
     */
    public function map(): array
    {
        return [
            'restaurant' => 'Restoran',
            'warung' => 'Warung',
            'convenience_store' => 'Minimarket',
            'other' => 'Lainnya',
        ];
    }

    public function label(?string $type): string
    {
        return $this->map()[strtolower(trim((string) $type))] ?? 'Lainnya';
    }

    /**
     * Pilihan untuk <select> pada form tambah/ubah restoran.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->map();
    }
}
