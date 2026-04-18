<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AiChatLog;
use App\Models\User;
use App\Services\ChatbotCourierOrderService;
use App\Services\ChatbotGeminiService;
use App\Services\ChatbotOrderValidationService;
use App\Services\ChatbotRideOrderService;
use Illuminate\Http\Request;
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

    /**
     * @var array<int, string>
     */
    private const CONFIRM_COMMANDS = [
        'konfirmasi',
        'confirm',
        'lanjut',
    ];

    /**
     * @var array<int, string>
     */
    private const RESET_DESTINATION_COMMANDS = [
        'ubah tujuan',
        'ganti tujuan',
        'reset tujuan',
    ];

    public function __construct(
        private readonly ChatbotOrderValidationService $validator,
        private readonly ChatbotCourierOrderService $courierOrderService,
        private readonly ChatbotRideOrderService $rideOrderService,
        private readonly ChatbotGeminiService $geminiService
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

        if ($serviceType === 'kurir' || $serviceType === 'antar_jemput') {
            return $this->processTransportChat(
                user: $request->user(),
                message: $message,
                sessionId: $sessionId,
                serviceType: $serviceType,
                serviceCode: $serviceCode
            );
        }

        return $this->processFoodChat(
            user: $request->user(),
            message: $message,
            sessionId: $sessionId,
            serviceType: $serviceType,
            serviceCode: $serviceCode
        );
    }

    private function processTransportChat(
        User $user,
        string $message,
        string $sessionId,
        string $serviceType,
        string $serviceCode
    ) {
        try {
            $nluPayload = null;
            $modelUsed = null;
            $fastCommand = $this->detectTransportFastCommand($message);

            if ($fastCommand !== null) {
                $nluPayload = ['command' => $fastCommand];
                $modelUsed = 'deterministic-command';
            } else {
                try {
                    $nluResult = $this->geminiService->interpretTransportMessage($serviceType, $message);
                    $nluPayload = is_array($nluResult['payload'] ?? null) ? $nluResult['payload'] : null;
                    $modelUsed = isset($nluResult['model_used']) ? (string) $nluResult['model_used'] : null;
                } catch (ApiException $exception) {
                    // Fallback ke parser deterministik jika Gemini unavailable.
                    $nluPayload = null;
                    $modelUsed = null;
                }
            }

            $payload = $serviceType === 'kurir'
                ? $this->courierOrderService->process($user, $message, $sessionId, $nluPayload)
                : $this->rideOrderService->process($user, $message, $sessionId, $nluPayload);

            $this->storeChatLogs(
                user: $user,
                sessionId: $sessionId,
                userMessage: $message,
                assistantPayload: $payload,
                modelUsed: $modelUsed
            );

            return response()->json([
                'status' => 'success',
                'service_context' => [
                    'service_type' => $serviceType,
                    'service_code' => $serviceCode,
                ],
                'data' => $payload,
                'model_used' => $modelUsed,
            ], 200);
        } catch (ApiException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status());
        }
    }

    private function processFoodChat(
        User $user,
        string $message,
        string $sessionId,
        string $serviceType,
        string $serviceCode
    ) {
        try {
            $parsed = $this->geminiService->parseFoodOrder($message);
            $rawPayload = is_array($parsed['payload'] ?? null)
                ? $parsed['payload']
                : [
                    'intent' => 'out_of_domain',
                    'resto' => null,
                    'items' => [],
                ];

            $validatedPayload = $this->validator->validate($rawPayload);
            $validatedPayload['assistant_text'] = $this->buildShoppingAssistantText($validatedPayload);

            $modelUsed = isset($parsed['model_used']) ? (string) $parsed['model_used'] : null;

            $this->storeChatLogs(
                user: $user,
                sessionId: $sessionId,
                userMessage: $message,
                assistantPayload: $validatedPayload,
                modelUsed: $modelUsed
            );

            return response()->json([
                'status' => 'success',
                'service_context' => [
                    'service_type' => $serviceType,
                    'service_code' => $serviceCode,
                ],
                'data' => $validatedPayload,
                'model_used' => $modelUsed,
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

    private function detectTransportFastCommand(string $message): ?string
    {
        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $message)));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, self::RESET_DESTINATION_COMMANDS, true)) {
            return 'reset_destination';
        }

        if (in_array($normalized, self::CONFIRM_COMMANDS, true)) {
            return 'confirm';
        }

        return null;
    }
}
