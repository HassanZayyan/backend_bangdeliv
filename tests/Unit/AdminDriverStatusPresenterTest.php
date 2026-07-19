<?php

namespace Tests\Unit;

use App\Services\Admin\AdminDriverStatusPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminDriverStatusPresenterTest extends TestCase
{
    private AdminDriverStatusPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new AdminDriverStatusPresenter;
    }

    #[DataProvider('registrationProvider')]
    public function test_registration_status_is_translated(?string $status, string $expected): void
    {
        $this->assertSame($expected, $this->presenter->registrationLabel($status));
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function registrationProvider(): array
    {
        return [
            'pending' => ['pending', 'Menunggu Verifikasi'],
            'active' => ['active', 'Aktif'],
            'rejected' => ['rejected', 'Ditolak'],
            'suspended' => ['suspended', 'Ditangguhkan'],
            'huruf besar tetap dikenali' => ['ACTIVE', 'Aktif'],
            'spasi berlebih diabaikan' => ['  pending  ', 'Menunggu Verifikasi'],
            'nilai tak dikenal jatuh ke default' => ['entah_apa', 'Menunggu Verifikasi'],
            'null jatuh ke default' => [null, 'Menunggu Verifikasi'],
        ];
    }

    #[DataProvider('operationalProvider')]
    public function test_operational_status_is_translated(?string $status, string $expected): void
    {
        $this->assertSame($expected, $this->presenter->operationalLabel($status));
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function operationalProvider(): array
    {
        return [
            'available' => ['available', 'Siap Menerima'],
            'busy' => ['busy', 'Sedang Bertugas'],
            'offline' => ['offline', 'Tidak Aktif'],
            'nilai tak dikenal jatuh ke default' => ['entah_apa', 'Tidak Aktif'],
            'null jatuh ke default' => [null, 'Tidak Aktif'],
        ];
    }

    #[DataProvider('documentTypeProvider')]
    public function test_document_type_is_translated(?string $type, string $expected): void
    {
        $this->assertSame($expected, $this->presenter->documentTypeLabel($type));
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function documentTypeProvider(): array
    {
        return [
            'ktp' => ['ktp', 'KTP'],
            'sim' => ['sim', 'SIM'],
            'selfie' => ['selfie', 'Foto Selfie'],
            'huruf besar tetap dikenali' => ['SELFIE', 'Foto Selfie'],
            'jenis tak dikenal tampil kapital' => ['stnk', 'STNK'],
            'null menjadi strip' => [null, '-'],
        ];
    }

    #[DataProvider('documentStatusProvider')]
    public function test_document_status_is_translated(?string $status, string $expected): void
    {
        $this->assertSame($expected, $this->presenter->documentStatusLabel($status));
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function documentStatusProvider(): array
    {
        return [
            'pending' => ['pending', 'Menunggu'],
            'approved' => ['approved', 'Disetujui'],
            'rejected' => ['rejected', 'Ditolak'],
            'nilai tak dikenal jatuh ke default' => ['entah_apa', 'Menunggu'],
            'null jatuh ke default' => [null, 'Menunggu'],
        ];
    }

    public function test_no_label_leaks_english_enum_values(): void
    {
        $labels = array_merge(
            array_values($this->presenter->registrationMap()),
            array_values($this->presenter->operationalMap()),
            array_values($this->presenter->documentStatusMap()),
        );

        foreach ($labels as $label) {
            $this->assertNotContains(strtolower($label), [
                'pending', 'active', 'rejected', 'suspended',
                'available', 'busy', 'offline', 'approved',
            ], "Label \"{$label}\" masih memakai istilah enum berbahasa Inggris.");
        }
    }

    public function test_badge_classes_follow_status_severity(): void
    {
        $this->assertSame('badge-success', $this->presenter->registrationBadgeClass('active'));
        $this->assertSame('badge-danger', $this->presenter->registrationBadgeClass('rejected'));
        $this->assertSame('badge-danger', $this->presenter->registrationBadgeClass('suspended'));
        $this->assertSame('badge-warning', $this->presenter->registrationBadgeClass('pending'));
        $this->assertSame('badge-warning', $this->presenter->registrationBadgeClass(null));

        $this->assertSame('badge-success', $this->presenter->documentBadgeClass('approved'));
        $this->assertSame('badge-danger', $this->presenter->documentBadgeClass('rejected'));
        $this->assertSame('badge-warning', $this->presenter->documentBadgeClass('pending'));
        $this->assertSame('badge-warning', $this->presenter->documentBadgeClass(null));
    }
}
