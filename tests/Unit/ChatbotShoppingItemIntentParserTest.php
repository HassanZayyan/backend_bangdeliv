<?php

namespace Tests\Unit;

use App\Services\Chatbot\ChatbotShoppingItemIntentParser;
use PHPUnit\Framework\TestCase;

class ChatbotShoppingItemIntentParserTest extends TestCase
{
    public function test_parses_bullet_item_list_with_level_number(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse("- gacoan level 6 1x\n- udang keju 1x");

        $this->assertSame([
            [
                'name' => 'gacoan level 6',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
            [
                'name' => 'udang keju',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $items);
    }

    public function test_parses_quantity_units_and_natural_separator(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse('gacoan level 6 1 porsi dan udang keju 1 porsi');

        $this->assertSame([
            [
                'name' => 'gacoan level 6',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
            [
                'name' => 'udang keju',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $items);
    }

    public function test_can_parse_single_implicit_item_when_context_allows_it(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse('gacoan level 6', allowImplicitSingleItem: true);

        $this->assertSame([
            [
                'name' => 'gacoan level 6',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $items);
    }

    public function test_parses_bare_trailing_quantity_when_context_allows_it(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse(
            'mie gacoan level 7 2',
            allowBareTrailingQuantity: true
        );

        $this->assertSame([
            [
                'name' => 'mie gacoan level 7',
                'quantity' => 2,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $items);
    }

    public function test_parses_level_item_with_explicit_porsi_quantity(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse('mie gacoan level 7 2 porsi');

        $this->assertSame([
            [
                'name' => 'mie gacoan level 7',
                'quantity' => 2,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $items);
    }

    public function test_parses_decrement_operation_with_clean_item_name(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse(
            'kurangi mie gacoan level 4 2x',
            allowBareTrailingQuantity: true
        );

        $this->assertSame([
            [
                'name' => 'mie gacoan level 4',
                'quantity' => 2,
                'operation' => ChatbotShoppingItemIntentParser::OP_DECREMENT,
                'notes' => null,
            ],
        ], $items);
    }

    public function test_recognizes_edit_without_item_as_clarification_needed(): void
    {
        $parser = new ChatbotShoppingItemIntentParser;

        $this->assertTrue($parser->requiresItemClarification('kurangi 1 dong', allowBareTrailingQuantity: true));
        $this->assertTrue($parser->requiresItemClarification('eh tambah lagi 2', allowBareTrailingQuantity: true));
        $this->assertFalse($parser->requiresItemClarification('tambah mie gacoan level 4 1x', allowBareTrailingQuantity: true));
    }

    public function test_keeps_paket_when_it_is_part_of_menu_name(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse('titip 2 paket geprek original dari Ayam Geprek Juara');

        $this->assertSame([
            [
                'name' => 'paket geprek original',
                'quantity' => 2,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $items);
    }

    public function test_keeps_level_number_inside_single_item(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse('ayam geprek level 6 1x');

        $this->assertSame([
            [
                'name' => 'ayam geprek level 6',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $items);
    }
}
