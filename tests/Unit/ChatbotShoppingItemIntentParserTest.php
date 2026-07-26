<?php

namespace Tests\Unit;

use App\Services\Chatbot\ChatbotShoppingItemIntentParser;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('merchantHeaderItemListProvider')]
    public function test_skips_merchant_header_when_parsing_item_list(string $message): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse(
            $message,
            allowBareTrailingQuantity: true
        );

        $this->assertSame([
            [
                'name' => 'sego tiwul',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $items);
    }

    /**
     * @return array<string, array{message: string}>
     */
    public static function merchantHeaderItemListProvider(): array
    {
        return [
            'numbered explicit x quantity' => [
                'message' => "Beli di nasgor gajah:\n1. sego tiwul 1x",
            ],
            'bullet explicit x quantity' => [
                'message' => "Beli di nasgor gajah:\n- sego tiwul 1x",
            ],
            'bullet bare quantity' => [
                'message' => "Beli di nasgor gajah:\n- sego tiwul 1",
            ],
            'numbered bare quantity' => [
                'message' => "Beli di nasgor gajah:\n1. sego tiwul 1",
            ],
        ];
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

    public function test_parses_conversational_quantity_with_apostrophe_merchant_tail(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse("aku mau beli ayam krispi sayap 3x di rendy's chicken");

        $this->assertSame([
            [
                'name' => 'ayam krispi sayap',
                'quantity' => 3,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $items);
    }

    public function test_preserves_comma_separated_multi_item_quantities(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse(
            'gue mau beli nasi goreng 1, nasi ruwet 2, kwetiau goreng 1 di nasgor gajah',
            allowBareTrailingQuantity: true
        );

        $this->assertSame([
            [
                'name' => 'nasi goreng',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
            [
                'name' => 'nasi ruwet',
                'quantity' => 2,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
            [
                'name' => 'kwetiau goreng',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $items);
    }

    public function test_parses_plus_separated_manual_items(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse(
            'susu 1 + roti tawar 2',
            allowBareTrailingQuantity: true
        );

        $this->assertSame([
            [
                'name' => 'susu',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
            [
                'name' => 'roti tawar',
                'quantity' => 2,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $items);
    }

    public function test_parses_comma_quantity_without_stripping_level_or_size(): void
    {
        $parser = new ChatbotShoppingItemIntentParser;

        $this->assertSame([
            [
                'name' => 'mie pedas level 7',
                'quantity' => 2,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $parser->parse('mie pedas level 7, 2x', allowBareTrailingQuantity: true));

        $this->assertSame([
            [
                'name' => 'minyak 500ml',
                'quantity' => 2,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $parser->parse('minyak 500ml, 2x', allowBareTrailingQuantity: true));

        $this->assertSame([
            [
                'name' => 'beras 1kg',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $parser->parse('beras 1kg, 1', allowBareTrailingQuantity: true));

        $this->assertSame([
            [
                'name' => 'beras 1 kg',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $parser->parse('beras 1 kg, 1', allowBareTrailingQuantity: true));
    }

    public function test_parses_mixed_comma_list_with_quantity_only_segments(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse(
            'nasi goreng 1, mie pedas level 7, 1, minyak 500ml, 1, sabun 1',
            allowBareTrailingQuantity: true
        );

        $this->assertSame([
            [
                'name' => 'nasi goreng',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
            [
                'name' => 'mie pedas level 7',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
            [
                'name' => 'minyak 500ml',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
            [
                'name' => 'sabun',
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

    public function test_splits_items_on_colloquial_connector_after_leading_vocative(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse(
            'mas tolong belikan mie gacoan level 1 sama udang keju 1',
            allowImplicitSingleItem: true,
            allowBareTrailingQuantity: true
        );

        $this->assertSame([
            [
                'name' => 'mie gacoan level 1',
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

    #[DataProvider('colloquialItemPhraseProvider')]
    public function test_strips_colloquial_vocative_and_buy_verb(string $message): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse(
            $message,
            allowImplicitSingleItem: true,
            allowBareTrailingQuantity: true
        );

        $this->assertSame([
            [
                'name' => 'nasi goreng',
                'quantity' => 2,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
            [
                'name' => 'es teh',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $items);
    }

    /**
     * @return array<string, array{message: string}>
     */
    public static function colloquialItemPhraseProvider(): array
    {
        return [
            'vocative + terus' => ['message' => 'eh mbak titip nasi goreng 2 terus es teh 1'],
            'vocative + beliin + sama' => ['message' => 'bang beliin nasi goreng 2 sama es teh 1'],
            'nitipin + ama' => ['message' => 'nitipin nasi goreng 2 ama es teh 1'],
            'ampersand separator' => ['message' => 'nasi goreng 2 & es teh 1'],
        ];
    }

    public function test_keeps_level_number_inside_single_item(): void
    {
        $items = (new ChatbotShoppingItemIntentParser)->parse('ayam krispi level 6 1x di resto');

        $this->assertSame([
            [
                'name' => 'ayam krispi level 6',
                'quantity' => 1,
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
            ],
        ], $items);
    }
}
