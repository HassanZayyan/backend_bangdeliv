<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatbotCourierFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_chatbot_kurir_creates_order_and_logs_chat(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '081111111111',
        ]);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => $user->name,
            'phone' => $user->phone,
            'full_address' => 'Jl. Melati No. 3, Jakarta',
            'detail' => 'Pagar hitam',
            'latitude' => -6.20550000,
            'longitude' => 106.82400000,
            'is_default' => true,
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'Kirim dokumen dari kantor ke Jl. Sudirman No. 10, isi paket: berkas kontrak.',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-01',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.intent', 'courier_order')
            ->assertJsonPath('data.order.created', true);

        $orderId = (int) $response->json('data.order.id');

        $this->assertGreaterThan(0, $orderId);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('courier_orders', [
            'order_id' => $orderId,
        ]);
        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'location_role' => 'PICKUP',
        ]);
        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'location_role' => 'DROPOFF',
        ]);
        $this->assertDatabaseHas('ai_chat_logs', [
            'user_id' => $user->id,
            'session_id' => 'sess-kurir-01',
            'role' => 'user',
        ]);
        $this->assertDatabaseHas('ai_chat_logs', [
            'user_id' => $user->id,
            'session_id' => 'sess-kurir-01',
            'role' => 'assistant',
            'order_id' => $orderId,
        ]);
    }

    public function test_chatbot_kurir_returns_validation_for_incomplete_message(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '082222222222',
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'Kirim paket dong',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-02',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.intent', 'courier_order')
            ->assertJsonPath('data.validation.is_valid_order', false)
            ->assertJsonPath('data.order.created', false);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('courier_orders', 0);
        $this->assertDatabaseHas('ai_chat_logs', [
            'user_id' => $user->id,
            'session_id' => 'sess-kurir-02',
            'role' => 'user',
        ]);
        $this->assertDatabaseHas('ai_chat_logs', [
            'user_id' => $user->id,
            'session_id' => 'sess-kurir-02',
            'role' => 'assistant',
        ]);
    }

    public function test_chatbot_kurir_does_not_use_package_keyword_as_dropoff_address(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '083333333333',
        ]);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => $user->name,
            'phone' => $user->phone,
            'full_address' => 'Sraten, Karanganyar',
            'detail' => 'Gang 1',
            'latitude' => -7.56100000,
            'longitude' => 110.82000000,
            'is_default' => true,
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'Kirim dokumen',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-03',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.order.created', false)
            ->assertJsonPath('data.courier.dropoff_address', null)
            ->assertJsonPath('data.courier.package_description', 'dokumen');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('courier_orders', 0);
        $this->assertDatabaseHas('ai_chat_logs', [
            'user_id' => $user->id,
            'session_id' => 'sess-kurir-03',
            'role' => 'assistant',
        ]);
    }

    public function test_chatbot_kurir_quick_format_maps_dropoff_and_package_correctly(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '084444444444',
        ]);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => $user->name,
            'phone' => $user->phone,
            'full_address' => 'sraten',
            'detail' => null,
            'latitude' => -7.56100000,
            'longitude' => 110.82000000,
            'is_default' => true,
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'kirim kunci motor dari sraten ke kos wiharto baskoro, isi paket: kunci motor',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-04',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.order.created', true)
            ->assertJsonPath('data.courier.pickup_address', 'sraten')
            ->assertJsonPath('data.courier.dropoff_address', 'kos wiharto baskoro')
            ->assertJsonPath('data.courier.package_description', 'kunci motor');

        $orderId = (int) $response->json('data.order.id');

        $this->assertGreaterThan(0, $orderId);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'delivery_address' => 'kos wiharto baskoro',
        ]);
        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'location_role' => 'DROPOFF',
            'full_address' => 'kos wiharto baskoro',
        ]);
        $this->assertDatabaseHas('courier_orders', [
            'order_id' => $orderId,
            'package_description' => 'kunci motor',
        ]);
    }

    public function test_chatbot_kurir_parses_explicit_single_word_locations(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '085555555555',
        ]);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => $user->name,
            'phone' => $user->phone,
            'full_address' => 'sraten',
            'detail' => null,
            'latitude' => -7.56100000,
            'longitude' => 110.82000000,
            'is_default' => true,
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'ambil di sraten, kirim ke salatiga, isi paket: makanan',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-05',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.order.created', true)
            ->assertJsonPath('data.courier.pickup_address', 'sraten')
            ->assertJsonPath('data.courier.dropoff_address', 'salatiga')
            ->assertJsonPath('data.courier.package_description', 'makanan');
    }
}
