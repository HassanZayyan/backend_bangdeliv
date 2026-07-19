<?php

namespace App\Services\Admin;

/**
 * Menerjemahkan nilai enum driver dari database menjadi label berbahasa Indonesia.
 *
 * Nilai enum-nya sendiri (pending, active, approved, ...) tidak boleh diubah karena
 * dipakai juga oleh API dan aplikasi Flutter. Presenter ini hanya lapisan tampilan.
 */
class AdminDriverStatusPresenter
{
    /**
     * Status pendaftaran driver — kolom drivers.registration_status.
     *
     * @return array<string, string>
     */
    public function registrationMap(): array
    {
        return [
            'pending' => 'Menunggu Verifikasi',
            'active' => 'Aktif',
            'rejected' => 'Ditolak',
            'suspended' => 'Ditangguhkan',
        ];
    }

    /**
     * Status operasional driver — kolom drivers.status.
     *
     * @return array<string, string>
     */
    public function operationalMap(): array
    {
        return [
            'available' => 'Siap Menerima',
            'busy' => 'Sedang Bertugas',
            'offline' => 'Tidak Aktif',
        ];
    }

    /**
     * Jenis dokumen — kolom driver_documents.document_type.
     *
     * @return array<string, string>
     */
    public function documentTypeMap(): array
    {
        return [
            'ktp' => 'KTP',
            'sim' => 'SIM',
            'selfie' => 'Foto Selfie',
        ];
    }

    /**
     * Status verifikasi dokumen — kolom driver_documents.verification_status.
     *
     * @return array<string, string>
     */
    public function documentStatusMap(): array
    {
        return [
            'pending' => 'Menunggu',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
        ];
    }

    public function registrationLabel(?string $status): string
    {
        return $this->registrationMap()[$this->normalize($status)] ?? 'Menunggu Verifikasi';
    }

    public function operationalLabel(?string $status): string
    {
        return $this->operationalMap()[$this->normalize($status)] ?? 'Tidak Aktif';
    }

    public function documentTypeLabel(?string $type): string
    {
        $normalized = $this->normalize($type);

        return $this->documentTypeMap()[$normalized] ?? ($normalized === '' ? '-' : strtoupper($normalized));
    }

    public function documentStatusLabel(?string $status): string
    {
        return $this->documentStatusMap()[$this->normalize($status)] ?? 'Menunggu';
    }

    public function registrationBadgeClass(?string $status): string
    {
        return match ($this->normalize($status)) {
            'active' => 'badge-success',
            'rejected', 'suspended' => 'badge-danger',
            default => 'badge-warning',
        };
    }

    public function documentBadgeClass(?string $status): string
    {
        return match ($this->normalize($status)) {
            'approved' => 'badge-success',
            'rejected' => 'badge-danger',
            default => 'badge-warning',
        };
    }

    private function normalize(?string $value): string
    {
        return strtolower(trim((string) $value));
    }
}
