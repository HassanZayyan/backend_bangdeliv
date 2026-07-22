<?php

namespace Tests\Unit;

use App\Services\Chatbot\ChatbotTransportSupport;
use PHPUnit\Framework\TestCase;

class ChatbotTransportSupportTest extends TestCase
{
    public function test_payment_method_from_normalized_text_detects_transfer_tokens(): void
    {
        foreach (['transfer', 'tf', 'bank', 'qris', 'non tunai', 'nontunai'] as $token) {
            $this->assertSame(
                'TRANSFER',
                ChatbotTransportSupport::paymentMethodFromNormalizedText('bayar pakai '.$token),
                'Token gagal terdeteksi TRANSFER: '.$token
            );
        }
    }

    public function test_payment_method_from_normalized_text_detects_cod_tokens(): void
    {
        foreach (['cod', 'cash', 'tunai'] as $token) {
            $this->assertSame(
                'COD',
                ChatbotTransportSupport::paymentMethodFromNormalizedText('bayar '.$token.' saja'),
                'Token gagal terdeteksi COD: '.$token
            );
        }
    }

    public function test_payment_method_from_normalized_text_returns_null_without_token(): void
    {
        $this->assertNull(ChatbotTransportSupport::paymentMethodFromNormalizedText('kirim laptop ke polines'));
        $this->assertNull(ChatbotTransportSupport::paymentMethodFromNormalizedText(''));
        $this->assertNull(ChatbotTransportSupport::paymentMethodFromNormalizedText('codex bukan metode bayar'));
    }

    public function test_is_payment_method_only_message_accepts_bare_tokens(): void
    {
        foreach (['COD', 'cash', 'Tunai', 'transfer', 'TF', 'bank', 'QRIS', 'non tunai', 'nontunai'] as $message) {
            $this->assertTrue(
                ChatbotTransportSupport::isPaymentMethodOnlyMessage($message, 'COD'),
                'Pesan token tunggal harus diterima: '.$message
            );
        }

        $this->assertTrue(ChatbotTransportSupport::isPaymentMethodOnlyMessage('  QRIS!  ', 'TRANSFER'));
    }

    public function test_is_payment_method_only_message_rejects_sentences_and_null_method(): void
    {
        $this->assertFalse(ChatbotTransportSupport::isPaymentMethodOnlyMessage('bayar pakai cod ya', 'COD'));
        $this->assertFalse(ChatbotTransportSupport::isPaymentMethodOnlyMessage('COD', null));
        $this->assertFalse(ChatbotTransportSupport::isPaymentMethodOnlyMessage('kirim laptop', 'COD'));
    }

    public function test_normalize_whitespace_collapses_internal_spaces(): void
    {
        $this->assertSame('a b c', ChatbotTransportSupport::normalizeWhitespace("  a \t b\n\nc  "));
        $this->assertSame('', ChatbotTransportSupport::normalizeWhitespace('   '));
    }

    public function test_normalize_optional_string_trims_without_collapsing(): void
    {
        $this->assertSame('a  b', ChatbotTransportSupport::normalizeOptionalString('  a  b  '));
        $this->assertNull(ChatbotTransportSupport::normalizeOptionalString('   '));
        $this->assertNull(ChatbotTransportSupport::normalizeOptionalString(null));
        $this->assertNull(ChatbotTransportSupport::normalizeOptionalString(123));
    }

    public function test_nullable_coordinate_parses_numeric_values_only(): void
    {
        $this->assertSame(-7.05, ChatbotTransportSupport::nullableCoordinate('-7.05'));
        $this->assertSame(110.0, ChatbotTransportSupport::nullableCoordinate(110));
        $this->assertSame(0.0, ChatbotTransportSupport::nullableCoordinate('0'));
        $this->assertNull(ChatbotTransportSupport::nullableCoordinate('abc'));
        $this->assertNull(ChatbotTransportSupport::nullableCoordinate(null));
        $this->assertNull(ChatbotTransportSupport::nullableCoordinate(''));
    }

    public function test_has_coordinate_pair_requires_both_axes(): void
    {
        $this->assertTrue(ChatbotTransportSupport::hasCoordinatePair([
            'pickup_latitude' => -7.05,
            'pickup_longitude' => 110.43,
        ], 'pickup'));

        $this->assertTrue(ChatbotTransportSupport::hasCoordinatePair([
            'dropoff_latitude' => '0',
            'dropoff_longitude' => '0',
        ], 'dropoff'));

        $this->assertFalse(ChatbotTransportSupport::hasCoordinatePair([
            'pickup_latitude' => -7.05,
        ], 'pickup'));

        $this->assertFalse(ChatbotTransportSupport::hasCoordinatePair([
            'pickup_latitude' => 'abc',
            'pickup_longitude' => 110.43,
        ], 'pickup'));

        $this->assertFalse(ChatbotTransportSupport::hasCoordinatePair([], 'pickup'));
    }
}
