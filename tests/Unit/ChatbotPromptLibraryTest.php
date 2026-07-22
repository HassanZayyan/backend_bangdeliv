<?php

namespace Tests\Unit;

use App\Services\Chatbot\ChatbotPromptLibrary;
use PHPUnit\Framework\TestCase;

class ChatbotPromptLibraryTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function instructionProvider(): array
    {
        return [
            'nitip' => [ChatbotPromptLibrary::foodOrderInstruction()],
            'kurir' => [ChatbotPromptLibrary::courierInstruction()],
            'antar_jemput' => [ChatbotPromptLibrary::rideInstruction()],
        ];
    }

    /**
     * @dataProvider instructionProvider
     */
    public function test_instruction_contains_structured_sections(string $instruction): void
    {
        foreach (['PERAN:', 'ATURAN:', 'KELUARAN:', 'CONTOH:', 'INPUT:', 'OUTPUT:'] as $marker) {
            $this->assertStringContainsString($marker, $instruction, 'Marker hilang: '.$marker);
        }

        $this->assertStringContainsString('Hanya JSON sesuai schema.', $instruction);
        $this->assertStringContainsString('Dilarang merespon teks biasa.', $instruction);
    }

    public function test_examples_encode_to_valid_json_with_required_keys(): void
    {
        $requiredKeys = [
            'nitip' => ['intent', 'command', 'items'],
            'kurir' => ['intent', 'command', 'package_description', 'dropoff_address'],
            'antar_jemput' => ['intent', 'command', 'pickup_address', 'destination_address', 'notes'],
        ];
        $validIntents = [
            'nitip' => 'shopping_order',
            'kurir' => 'courier_order',
            'antar_jemput' => 'ride_order',
        ];

        foreach ($requiredKeys as $serviceType => $keys) {
            $examples = ChatbotPromptLibrary::examplesFor($serviceType);
            $this->assertNotEmpty($examples, 'Contoh kosong untuk '.$serviceType);

            foreach ($examples as $example) {
                $encoded = json_encode($example['output'], JSON_UNESCAPED_UNICODE);
                $this->assertIsString($encoded);
                $decoded = json_decode($encoded, true);
                $this->assertIsArray($decoded, 'Output contoh bukan JSON valid: '.$example['input']);

                foreach ($keys as $key) {
                    $this->assertArrayHasKey($key, $decoded, "Key {$key} hilang di contoh: ".$example['input']);
                }

                $this->assertSame(
                    $validIntents[$serviceType],
                    $decoded['intent'],
                    'Intent contoh salah: '.$example['input']
                );
            }
        }

        $this->assertSame([], ChatbotPromptLibrary::examplesFor('unknown'));
    }

    public function test_courier_instruction_keeps_load_bearing_decomposition_rules(): void
    {
        $instruction = ChatbotPromptLibrary::courierInstruction();

        $this->assertStringContainsString('laptop ROG temen gue', $instruction);
        $this->assertStringContainsString('package_description', $instruction);
        $this->assertStringContainsString('barang inti BESERTA imbuhannya', $instruction);
        $this->assertStringContainsString('"anter", "anterin", "anterkan", "antarin", "kirimin", dan "bawain"', $instruction);
        $this->assertStringContainsString('Erha Setiabudi Tembalang', $instruction);
    }

    public function test_food_instruction_keeps_item_splitting_rules(): void
    {
        $instruction = ChatbotPromptLibrary::foodOrderInstruction();

        $this->assertStringContainsString('level 6', $instruction);
        $this->assertStringContainsString('mie gacoan level 7', $instruction);
        $this->assertStringContainsString('"show_menu", "menu_next", "menu_previous", "search_menu", "recommend_food"', $instruction);
        $this->assertStringContainsString('active_merchant_name', $instruction);
        $this->assertStringContainsString('operation', $instruction);
    }

    public function test_ride_examples_map_pickup_message_to_pickup_address(): void
    {
        $examples = ChatbotPromptLibrary::examplesFor('antar_jemput');
        $pickupExample = null;
        foreach ($examples as $example) {
            if ($example['input'] === 'jemput aku di kos ya') {
                $pickupExample = $example;
            }
        }

        $this->assertNotNull($pickupExample);
        $this->assertSame('kos', $pickupExample['output']['pickup_address']);
        $this->assertNull($pickupExample['output']['destination_address']);
        $this->assertNull($pickupExample['output']['notes']);
    }

    public function test_ride_examples_capture_both_pickup_and_destination(): void
    {
        $examples = ChatbotPromptLibrary::examplesFor('antar_jemput');
        $combined = null;
        foreach ($examples as $example) {
            if (str_starts_with($example['input'], 'Jemput saya di Kopi Kenangan')) {
                $combined = $example;
            }
        }

        $this->assertNotNull($combined);
        $this->assertSame('Kopi Kenangan Tembalang', $combined['output']['pickup_address']);
        $this->assertSame('Alun-Alun Semarang', $combined['output']['destination_address']);
    }
}
