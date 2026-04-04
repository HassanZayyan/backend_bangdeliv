<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use App\Models\RestaurantOperatingHour;
use Illuminate\Database\Seeder;

class RestaurantMenuSeeder extends Seeder
{
    public function run(): void
    {
        $restaurants = [
            [
                'name' => 'Ayam Geprek Juara',
                'slug' => 'ayam-geprek-juara',
                'description' => 'Spesialis ayam geprek level pedas.',
                'address' => 'Jl. Veteran No. 12',
                'latitude' => -6.20111111,
                'longitude' => 106.82111111,
                'phone' => '081233330001',
                'status' => 'active',
                'avg_rating' => 4.70,
                'total_reviews' => 220,
                'estimated_prep_time' => 18,
                'categories' => [
                    'Paket Nasi' => [
                        ['name' => 'Paket Geprek Original', 'price' => 22000],
                        ['name' => 'Paket Geprek Keju', 'price' => 26000],
                    ],
                    'Minuman' => [
                        ['name' => 'Es Teh Manis', 'price' => 6000],
                        ['name' => 'Es Jeruk', 'price' => 8000],
                    ],
                ],
            ],
            [
                'name' => 'Kopi Senja Masa',
                'slug' => 'kopi-senja-masa',
                'description' => 'Kopi dan snack sore hari.',
                'address' => 'Komplek Ruko A1',
                'latitude' => -6.20999999,
                'longitude' => 106.82999999,
                'phone' => '081233330002',
                'status' => 'active',
                'avg_rating' => 4.85,
                'total_reviews' => 175,
                'estimated_prep_time' => 12,
                'categories' => [
                    'Kopi' => [
                        ['name' => 'Americano', 'price' => 18000],
                        ['name' => 'Cafe Latte', 'price' => 24000],
                    ],
                    'Snack' => [
                        ['name' => 'Croffle Coklat', 'price' => 20000],
                        ['name' => 'Roti Bakar Keju', 'price' => 17000],
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
                    'address' => $restoData['address'],
                    'latitude' => $restoData['latitude'],
                    'longitude' => $restoData['longitude'],
                    'phone' => $restoData['phone'],
                    'status' => $restoData['status'],
                    'avg_rating' => $restoData['avg_rating'],
                    'total_reviews' => $restoData['total_reviews'],
                    'estimated_prep_time' => $restoData['estimated_prep_time'],
                ]
            );

            for ($day = 0; $day <= 6; $day++) {
                RestaurantOperatingHour::updateOrCreate(
                    [
                        'restaurant_id' => $restaurant->id,
                        'day_of_week' => $day,
                    ],
                    [
                        'open_time' => '09:00:00',
                        'close_time' => '22:00:00',
                        'is_closed' => false,
                    ]
                );
            }

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
