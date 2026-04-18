<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AiChatLog;
use App\Models\User;
use App\Services\ChatbotCourierOrderService;
use App\Services\ChatbotOrderValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChatbotController extends Controller
{
    private const SERVICE_TYPE_MAP = [
        'antar_jemput' => 'RIDE',
        'kurir' => 'COURIER',
        'nitip' => 'SHOPPING',
    ];

    public function __construct(
        private readonly ChatbotOrderValidationService $validator,
        private readonly ChatbotCourierOrderService $courierOrderService
    ) {
    }

    public function processChat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'service_type' => ['nullable', Rule::in(array_keys(self::SERVICE_TYPE_MAP))],
            'session_id' => ['nullable', 'string', 'max:100'],
        ]);

        $serviceType = (string) ($validated['service_type'] ?? 'nitip');
        $serviceCode = self::SERVICE_TYPE_MAP[$serviceType];
        $sessionId = $this->resolveSessionId($request, $validated['session_id'] ?? null);
        $message = trim((string) $validated['message']);

        if ($serviceType === 'kurir') {
            return $this->processCourierChat(
                user: $request->user(),
                message: $message,
                sessionId: $sessionId,
                serviceType: $serviceType,
                serviceCode: $serviceCode
            );
        }

        $apiKey = env('GEMINI_API_KEY');
        $models = [
            'gemini-3.1-flash-lite-preview',
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
            'gemini-3-flash-preview',
        ];

        $systemInstruction = "Kamu adalah AI asisten BangDeliv. Ekstrak pesan menjadi JSON. Format wajib: {\"intent\": \"pesan_makanan\" atau \"out_of_domain\", \"resto\": \"string/null\", \"items\": [{\"menu\": \"string\", \"qty\": integer}]}. Pastikan mengekstrak setiap pesanan menu secara terpisah ke dalam array items! Dilarang merespon teks biasa.";

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $message]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'intent' => ['type' => 'STRING'],
                        'resto' => ['type' => 'STRING', 'nullable' => true],
                        'items' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'menu' => ['type' => 'STRING'],
                                    'qty' => ['type' => 'INTEGER']
                                ],
                                'required' => ['menu', 'qty']
                            ]
                        ]
                    ],
                    'required' => ['intent', 'items']
                ]
            ]
        ];

        $lastError = null;

        foreach ($models as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->post($url, $payload);

                if ($response->successful()) {
                    $result = $response->json();
                    $textResponse = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
                    $decodedResponse = json_decode($textResponse, true);

                    if (!is_array($decodedResponse)) {
                        $decodedResponse = [
                            'intent' => 'out_of_domain',
                            'resto' => null,
                            'items' => [],
                        ];
                    }

                    $validatedPayload = $this->validator->validate($decodedResponse);
                    $validatedPayload['assistant_text'] = $this->buildShoppingAssistantText($validatedPayload);
                    $this->storeChatLogs(
                        user: $request->user(),
                        sessionId: $sessionId,
                        userMessage: $message,
                        assistantPayload: $validatedPayload,
                        modelUsed: $model
                    );

                    return response()->json([
                        'status' => 'success',
                        'service_context' => [
                            'service_type' => $serviceType,
                            'service_code' => $serviceCode,
                        ],
                        'data' => $validatedPayload,
                        'model_used' => $model
                    ], 200);
                }

                if ($response->status() === 429) {
                    Log::warning("Gemini API Rate Limit Hit for model: {$model}");
                    continue;
                }

                $lastError = "API Error {$response->status()}: " . $response->body();

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::error("Gemini API Exception for model {$model}: " . $e->getMessage());
                continue;
            }
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Layanan AI sedang sibuk atau melampaui batas kuota harian. Silakan coba beberapa saat lagi.',
            'debug_error' => config('app.debug') ? $lastError : null
        ], 503);
    }

    private function processCourierChat(
        User $user,
        string $message,
        string $sessionId,
        string $serviceType,
        string $serviceCode
    ) {
        try {
            $payload = $this->courierOrderService->process($user, $message);
            $this->storeChatLogs(
                user: $user,
                sessionId: $sessionId,
                userMessage: $message,
                assistantPayload: $payload,
                modelUsed: null
            );

            return response()->json([
                'status' => 'success',
                'service_context' => [
                    'service_type' => $serviceType,
                    'service_code' => $serviceCode,
                ],
                'data' => $payload,
                'model_used' => null,
            ], 200);
        } catch (ApiException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildShoppingAssistantText(array $payload): string
    {
        $intent = (string) ($payload['intent'] ?? 'unknown');
        if ($intent !== 'pesan_makanan') {
            return 'Aku fokus bantu pemesanan makanan. Coba tulis menu dan jumlahnya, ya.';
        }

        $validation = is_array($payload['validation'] ?? null)
            ? $payload['validation']
            : [];

        $isValidOrder = ($validation['is_valid_order'] ?? false) === true;
        $reasons = is_array($validation['rejection_reasons'] ?? null)
            ? $validation['rejection_reasons']
            : [];
        $unmatchedItems = is_array($validation['unmatched_items'] ?? null)
            ? $validation['unmatched_items']
            : [];
        $matchedItems = is_array($validation['matched_items'] ?? null)
            ? $validation['matched_items']
            : [];
        $matchedRestaurant = is_array($validation['matched_restaurant'] ?? null)
            ? $validation['matched_restaurant']
            : null;

        if (!$isValidOrder) {
            $buffer = "Maaf, pesananmu belum bisa diproses karena:\n";

            foreach ($reasons as $reason) {
                $buffer .= '- '.trim((string) $reason)."\n";
            }

            if ($unmatchedItems !== []) {
                $buffer .= '- Menu yang belum ditemukan:' . "\n";
                foreach ($unmatchedItems as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $qty = max(1, (int) ($item['qty'] ?? 1));
                    $menu = (string) ($item['menu'] ?? '-');
                    $buffer .= "  - {$qty}x {$menu}\n";
                }
            }

            $buffer .= "\nCoba pilih menu/resto yang tersedia di aplikasi, ya.";

            return trim($buffer);
        }

        if ($matchedItems !== []) {
            $buffer = "Siap, pesananmu valid dan tersedia:\n";

            foreach ($matchedItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $qty = max(1, (int) ($item['qty'] ?? 1));
                $menuName = (string) ($item['menu_name'] ?? '-');
                $buffer .= "- {$qty}x {$menuName}\n";
            }

            $restaurantName = is_array($matchedRestaurant)
                ? trim((string) ($matchedRestaurant['name'] ?? ''))
                : '';
            if ($restaurantName !== '') {
                $buffer .= "\nResto: {$restaurantName}";
            }

            return trim($buffer);
        }

        return 'Aku belum menangkap item pesananmu. Coba tulis seperti: "2 ayam geprek, 1 es teh".';
    }

    /**
     * @param  array<string, mixed>  $assistantPayload
     */
    private function storeChatLogs(
        User $user,
        string $sessionId,
        string $userMessage,
        array $assistantPayload,
        ?string $modelUsed
    ): void {
        try {
            $intent = (string) ($assistantPayload['intent'] ?? 'unknown');
            $assistantText = trim((string) ($assistantPayload['assistant_text'] ?? 'Respon chatbot berhasil diproses.'));
            $orderId = $this->resolveOrderId($assistantPayload);

            AiChatLog::query()->create([
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'role' => 'user',
                'message' => $userMessage,
                'ai_response' => null,
                'model_used' => null,
                'intent' => $intent,
                'order_id' => $orderId,
                'created_at' => now(),
            ]);

            AiChatLog::query()->create([
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'role' => 'assistant',
                'message' => $assistantText === '' ? 'Respon chatbot berhasil diproses.' : $assistantText,
                'ai_response' => $assistantPayload,
                'model_used' => $modelUsed,
                'intent' => $intent,
                'order_id' => $orderId,
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Gagal menyimpan ai_chat_logs: '.$exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $assistantPayload
     */
    private function resolveOrderId(array $assistantPayload): ?int
    {
        $order = $assistantPayload['order'] ?? null;
        if (!is_array($order)) {
            return null;
        }

        $orderId = $order['id'] ?? null;
        if ($orderId === null) {
            return null;
        }

        return (int) $orderId;
    }

    private function resolveSessionId(Request $request, mixed $incoming): string
    {
        $candidate = trim((string) ($incoming ?? ''));
        if ($candidate === '') {
            $candidate = trim((string) $request->header('X-Chat-Session', ''));
        }

        if ($candidate === '') {
            $candidate = 'chat-'.$request->user()->id.'-'.Str::uuid()->toString();
        }

        return substr($candidate, 0, 100);

    }
}
