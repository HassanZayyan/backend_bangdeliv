<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;

class RestaurantMenuSeeder extends Seeder
{
    public function run(): void
    {
        $restaurants = [
            [
                'name' => 'Resto Taman Kedai Satu',
                'slug' => 'resto-taman-kedai-satu',
                'description' => 'Resto dummy lokal untuk layanan Nitip BangDeliv.',
                'merchant_type' => 'restaurant',
                'address' => 'Area merchant dummy -7.0549432, 110.4347394',
                'latitude' => -7.0549432,
                'longitude' => 110.4347394,
                'phone' => '081233330101',
                'status' => 'active',
                'categories' => [
                    'Paket Nasi' => [
                        ['name' => 'Nasi Ayam Geprek', 'price' => 22000],
                        ['name' => 'Nasi Telur Crispy', 'price' => 18000],
                    ],
                    'Minuman' => [
                        ['name' => 'Es Teh Manis', 'price' => 6000],
                        ['name' => 'Es Jeruk', 'price' => 8000],
                    ],
                ],
            ],
            [
                'name' => 'Dapur Mrican BangDeliv',
                'slug' => 'dapur-mrican-bangdeliv',
                'description' => 'Resto dummy dengan menu rumahan.',
                'merchant_type' => 'restaurant',
                'address' => 'Area merchant dummy -7.0532608, 110.4360778',
                'latitude' => -7.0532608,
                'longitude' => 110.4360778,
                'phone' => '081233330102',
                'status' => 'active',
                'categories' => [
                    'Menu Rumahan' => [
                        ['name' => 'Nasi Ayam Kremes', 'price' => 24000],
                        ['name' => 'Nasi Lele Sambal', 'price' => 21000],
                    ],
                    'Minuman' => [
                        ['name' => 'Teh Hangat', 'price' => 5000],
                        ['name' => 'Kopi Tubruk', 'price' => 7000],
                    ],
                ],
            ],
            [
                'name' => 'Warung Makan Ndeso Sampangan',
                'slug' => 'warung-makan-ndeso-sampangan',
                'description' => 'Resto dummy dekat titik koordinat customer.',
                'merchant_type' => 'restaurant',
                'address' => 'Area merchant dummy -7.0561607, 110.4333919',
                'latitude' => -7.0561607,
                'longitude' => 110.4333919,
                'phone' => '081233330103',
                'status' => 'active',
                'categories' => [
                    'Paket Hemat' => [
                        ['name' => 'Nasi Oseng Ayam', 'price' => 20000],
                        ['name' => 'Nasi Goreng Kampung', 'price' => 19000],
                    ],
                    'Lauk Tambahan' => [
                        ['name' => 'Telur Dadar', 'price' => 7000],
                        ['name' => 'Tempe Goreng', 'price' => 5000],
                    ],
                ],
            ],
            [
                'name' => 'Warung Madura Barokah',
                'slug' => 'warung-madura-barokah',
                'description' => 'Warung Madura dummy untuk barang harian.',
                'merchant_type' => 'warung',
                'address' => 'Area merchant dummy -7.0584234, 110.4390071',
                'latitude' => -7.0584234,
                'longitude' => 110.4390071,
                'phone' => '081233330201',
                'status' => 'active',
                'categories' => [
                    'Sembako' => [
                        ['name' => 'Telur Ayam 1 kg', 'price' => 32000],
                        ['name' => 'Beras 5 kg', 'price' => 70000],
                    ],
                    'Kebutuhan Harian' => [
                        ['name' => 'Minyak Goreng 1 L', 'price' => 18000],
                        ['name' => 'Mie Instan Goreng', 'price' => 3500],
                    ],
                ],
            ],
            [
                'name' => 'Warung Madura Sumber Rejeki',
                'slug' => 'warung-madura-sumber-rejeki',
                'description' => 'Warung Madura dummy dengan katalog contoh.',
                'merchant_type' => 'warung',
                'address' => 'Area merchant dummy -7.0597279, 110.4395979',
                'latitude' => -7.0597279,
                'longitude' => 110.4395979,
                'phone' => '081233330202',
                'status' => 'active',
                'categories' => [
                    'Sembako' => [
                        ['name' => 'Gula Pasir 1 kg', 'price' => 17000],
                        ['name' => 'Tepung Terigu 1 kg', 'price' => 14000],
                    ],
                    'Minuman' => [
                        ['name' => 'Air Mineral 1.5 L', 'price' => 7000],
                        ['name' => 'Teh Botol', 'price' => 6000],
                    ],
                ],
            ],
            [
                'name' => 'Warung Madura Maju Jaya',
                'slug' => 'warung-madura-maju-jaya',
                'description' => 'Warung Madura dummy untuk titip barang umum.',
                'merchant_type' => 'warung',
                'address' => 'Area merchant dummy -7.0598770, 110.4365384',
                'latitude' => -7.059877,
                'longitude' => 110.4365384,
                'phone' => '081233330203',
                'status' => 'active',
                'categories' => [
                    'Sembako' => [
                        ['name' => 'Sabun Cuci Piring', 'price' => 12000],
                        ['name' => 'Gas LPG 3 kg', 'price' => 23000],
                    ],
                    'Snack' => [
                        ['name' => 'Roti Tawar', 'price' => 16000],
                        ['name' => 'Keripik Singkong', 'price' => 9000],
                    ],
                ],
            ],
            [
                'name' => 'Alfamart BangDeliv Point',
                'slug' => 'alfamart-bangdeliv-point',
                'description' => 'Minimarket dummy untuk simulasi Nitip.',
                'merchant_type' => 'convenience_store',
                'address' => 'Area merchant dummy -7.0561494, 110.4384112',
                'latitude' => -7.0561494,
                'longitude' => 110.4384112,
                'phone' => '081233330301',
                'status' => 'active',
                'categories' => [
                    'Minuman' => [
                        ['name' => 'Air Mineral 600 ml', 'price' => 4000],
                        ['name' => 'Susu UHT Coklat', 'price' => 8000],
                    ],
                    'Kebutuhan Harian' => [
                        ['name' => 'Tisu Wajah', 'price' => 12000],
                        ['name' => 'Pasta Gigi', 'price' => 15000],
                    ],
                ],
            ],
            [
                'name' => 'Indomaret BangDeliv Point',
                'slug' => 'indomaret-bangdeliv-point',
                'description' => 'Minimarket dummy dekat area layanan.',
                'merchant_type' => 'convenience_store',
                'address' => 'Area merchant dummy -7.0561674, 110.4386352',
                'latitude' => -7.0561674,
                'longitude' => 110.4386352,
                'phone' => '081233330302',
                'status' => 'active',
                'categories' => [
                    'Snack' => [
                        ['name' => 'Biskuit Coklat', 'price' => 9000],
                        ['name' => 'Keripik Kentang', 'price' => 13000],
                    ],
                    'Kebutuhan Harian' => [
                        ['name' => 'Sabun Mandi', 'price' => 6000],
                        ['name' => 'Shampoo Sachet', 'price' => 2500],
                    ],
                ],
            ],
        ];

        foreach ($restaurants as $restoData) {
            $restaurant = Restaurant::updateOrCreate(
                ['slug' => $restoData['slug']],
                [
                    'name' => $restoData['name'],
                    'description' => $restoData['description'],
                    'merchant_type' => $restoData['merchant_type'] ?? 'restaurant',
                    'address' => $restoData['address'],
                    'latitude' => $restoData['latitude'],
                    'longitude' => $restoData['longitude'],
                    'phone' => $restoData['phone'],
                    'status' => $restoData['status'],
                ]
            );

            $sortCategory = 1;
            foreach ($restoData['categories'] as $categoryName => $menus) {
                $category = MenuCategory::updateOrCreate(
                    [
                        'restaurant_id' => $restaurant->id,
                        'name' => $categoryName,
                    ],
                    [
                        'sort_order' => $sortCategory++,
                    ]
                );

                $sortMenu = 1;
                foreach ($menus as $menu) {
                    Menu::updateOrCreate(
                        [
                            'restaurant_id' => $restaurant->id,
                            'name' => $menu['name'],
                        ],
                        [
                            'menu_category_id' => $category->id,
                            'description' => null,
                            'price' => $menu['price'],
                            'is_available' => true,
                            'sort_order' => $sortMenu++,
                        ]
                    );
                }
            }
        }
    }
}
