<?php

namespace Tests\Unit;

use App\Services\Chatbot\ChatbotShoppingItemIntentParser;
use App\Services\Chatbot\ChatbotShoppingItemNormalizer;
use PHPUnit\Framework\TestCase;

class ChatbotShoppingItemNormalizerTest extends TestCase
{
    public function test_incoming_items_normalize_name_quantity_operation_and_notes(): void
    {
        $items = ChatbotShoppingItemNormalizer::incomingItems([
            [
                'menu' => 'Roti Tawar',
                'qty' => 0,
                'operation' => 'replace',
                'notes' => '  tanpa pinggir ',
                'is_heavy' => true,
            ],
            ['name' => '   '],
            'skip',
        ]);

        $this->assertSame([
            [
                'name' => 'Roti Tawar',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_SET,
                'notes' => 'tanpa pinggir',
            ],
        ], $items);
        $this->assertArrayNotHasKey('is_heavy', $items[0]);
    }

    public function test_gemini_items_keep_legacy_name_menu_and_quantity_aliases(): void
    {
        $items = ChatbotShoppingItemNormalizer::geminiItems([
            [
                'name' => 'Susu',
                'quantity' => 2,
                'operation' => 'delete',
                'notes' => '',
            ],
        ]);

        $this->assertSame([
            [
                'name' => 'Susu',
                'menu' => 'Susu',
                'quantity' => 2,
                'qty' => 2,
                'notes' => null,
                'operation' => ChatbotShoppingItemIntentParser::OP_REMOVE,
            ],
        ], $items);
    }

    public function test_draft_item_supports_menu_name_and_stable_key(): void
    {
        $item = ChatbotShoppingItemNormalizer::draftItem([
            'menu_name' => '  Roti   Tawar ',
            'quantity' => 3,
        ]);

        $this->assertSame([
            'name' => 'Roti   Tawar',
            'quantity' => 3,
            'notes' => null,
        ], $item);
        $this->assertSame('roti tawar', ChatbotShoppingItemNormalizer::itemKey($item['name']));
    }
}
