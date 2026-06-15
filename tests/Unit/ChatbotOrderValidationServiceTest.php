<?php

namespace Tests\Unit;

use App\Models\Menu;
use App\Models\Restaurant;
use App\Services\Chatbot\ChatbotOrderValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatbotOrderValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_unknown_restaurant(): void
    {
        Restaurant::query()->create([
            'name' => 'Ayam Geprek Juara',
            'slug' => 'ayam-geprek-juara',
            'description' => null,
            'address' => 'Jl. Raya',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'phone' => '081200000001',
            'banner_image' => null,
            'status' => 'active',
        ]);

        $service = app(ChatbotOrderValidationService::class);
        $result = $service->validate([
            'intent' => 'pesan_makanan',
            'resto' => 'Resto Tidak Ada',
            'items' => [
                ['menu' => 'Ayam Geprek', 'qty' => 2],
            ],
        ]);

        $this->assertFalse($result['validation']['is_valid_order']);
        $this->assertNotEmpty($result['validation']['rejection_reasons']);
    }

    public function test_it_rejects_unknown_menu_even_when_intent_is_food_order(): void
    {
        $restaurant = Restaurant::query()->create([
            'name' => 'Ayam Geprek Juara',
            'slug' => 'ayam-geprek-juara',
            'description' => null,
            'address' => 'Jl. Raya',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'phone' => '081200000001',
            'banner_image' => null,
            'status' => 'active',
        ]);

        Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'menu_category_id' => null,
            'name' => 'Ayam Geprek Original',
            'description' => null,
            'price' => 22000,
            'image' => null,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        $service = app(ChatbotOrderValidationService::class);
        $result = $service->validate([
            'intent' => 'pesan_makanan',
            'resto' => null,
            'items' => [
                ['menu' => 'Mie Jebew', 'qty' => 2],
            ],
        ]);

        $this->assertFalse($result['validation']['is_valid_order']);
        $this->assertCount(1, $result['validation']['unmatched_items']);
        $this->assertNotEmpty($result['validation']['rejection_reasons']);
    }
}
