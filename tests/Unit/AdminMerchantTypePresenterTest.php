<?php

namespace Tests\Unit;

use App\Services\Admin\AdminMerchantTypePresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminMerchantTypePresenterTest extends TestCase
{
    private AdminMerchantTypePresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new AdminMerchantTypePresenter;
    }

    #[DataProvider('merchantTypeProvider')]
    public function test_merchant_type_is_translated(?string $type, string $expected): void
    {
        $this->assertSame($expected, $this->presenter->label($type));
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function merchantTypeProvider(): array
    {
        return [
            'restaurant' => ['restaurant', 'Restoran'],
            'warung' => ['warung', 'Warung'],
            'convenience_store' => ['convenience_store', 'Minimarket'],
            'other' => ['other', 'Lainnya'],
            'huruf besar tetap dikenali' => ['RESTAURANT', 'Restoran'],
            'nilai tak dikenal jatuh ke Lainnya' => ['food_truck', 'Lainnya'],
            'null jatuh ke Lainnya' => [null, 'Lainnya'],
        ];
    }

    public function test_options_cover_every_enum_value_in_the_migration(): void
    {
        // Nilai ini harus sama persis dengan enum restaurants.merchant_type pada
        // 2026_03_30_115804_create_restaurants_table.php.
        $this->assertSame(
            ['restaurant', 'warung', 'convenience_store', 'other'],
            array_keys($this->presenter->options()),
        );
    }

    public function test_english_enum_values_are_actually_translated(): void
    {
        // Hanya nilai yang memang berbahasa Inggris yang diperiksa. Nilai "warung"
        // sengaja dilewati karena kata itu sudah bahasa Indonesia, jadi label yang
        // identik dengan nilai enumnya justru benar.
        $map = $this->presenter->map();

        $this->assertSame('Restoran', $map['restaurant']);
        $this->assertSame('Minimarket', $map['convenience_store']);
        $this->assertSame('Lainnya', $map['other']);
    }
}
