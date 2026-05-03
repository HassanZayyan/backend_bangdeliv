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
use App\Services\GoogleMapsGeocodingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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

    private const MODEL_CONTEXT_TURN_LIMIT = 8;

    /**
     * @var array<string, string>
     */
    private const INTENT_TO_SERVICE_TYPE = [
        'ride_order' => 'antar_jemput',
        'courier_order' => 'kurir',
        'pesan_makanan' => 'nitip',
        'out_of_domain' => 'nitip',
    ];

    public function __construct(
        private readonly ChatbotOrderValidationService $validator,
        private readonly ChatbotCourierOrderService $courierOrderService,
        private readonly ChatbotRideOrderService $rideOrderService,
        private readonly ChatbotGeminiService $geminiService,
        private readonly GoogleMapsGeocodingService $geocodingService,
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

    public function listSessions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_type' => ['nullable', Rule::in(array_keys(self::SERVICE_TYPE_MAP))],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $limit = (int) ($validated['limit'] ?? 20);
        $serviceTypeFilter = isset($validated['service_type'])
            ? (string) $validated['service_type']
            : null;

        $latestAssistantLogIds = AiChatLog::query()
            ->selectRaw('MAX(id) as id')
            ->where('user_id', $user->id)
            ->where('role', 'assistant')
            ->groupBy('session_id');

        $latestAssistantLogs = AiChatLog::query()
            ->whereIn('id', $latestAssistantLogIds)
            ->orderByDesc('created_at')
            ->limit($limit * 2)
            ->get();

        $sessionIds = $latestAssistantLogs
            ->pluck('session_id')
            ->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->unique()
            ->values()
            ->all();

        if ($sessionIds === []) {
            return response()->json([
                'status' => 'success',
                'data' => [],
            ], 200);
        }

        $messageCounts = AiChatLog::query()
            ->selectRaw('session_id, COUNT(*) as aggregate')
            ->where('user_id', $user->id)
            ->whereIn('session_id', $sessionIds)
            ->groupBy('session_id')
            ->pluck('aggregate', 'session_id');

        $rows = [];

        foreach ($latestAssistantLogs as $log) {
            $sessionId = trim((string) $log->session_id);
            if ($sessionId === '') {
                continue;
            }

            $serviceType = $this->resolveServiceTypeFromLog($log);
            if ($serviceTypeFilter !== null && $serviceType !== $serviceTypeFilter) {
                continue;
            }

            $rows[] = [
                'session_id' => $sessionId,
                'service_type' => $serviceType,
                'last_message' => (string) $log->message,
                'last_message_at' => optional($log->created_at)?->toISOString(),
                'message_count' => (int) ($messageCounts[$sessionId] ?? 0),
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $rows,
        ], 200);
    }

    public function sessionHistory(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'before_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $normalizedSessionId = substr(trim($sessionId), 0, 100);
        if ($normalizedSessionId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Session ID tidak valid.',
            ], 422);
        }

        $limit = (int) ($validated['limit'] ?? 50);
        $beforeId = isset($validated['before_id']) ? (int) $validated['before_id'] : null;

        $query = AiChatLog::query()
            ->where('user_id', $user->id)
            ->where('session_id', $normalizedSessionId)
            ->when($beforeId !== null, static function (Builder $builder) use ($beforeId): void {
                $builder->where('id', '<', $beforeId);
            })
            ->orderByDesc('id')
            ->limit($limit + 1);

        $logs = $query->get();
        $hasMore = $logs->count() > $limit;
        if ($hasMore) {
            $logs = $logs->take($limit);
        }

        $messages = $logs
            ->sortBy('id')
            ->values()
            ->map(function (AiChatLog $log): array {
                return [
                    'id' => $log->id,
                    'role' => (string) $log->role,
                    'message' => (string) $log->message,
                    'model_used' => $log->model_used,
                    'intent' => $log->intent,
                    'order_id' => $log->order_id,
                    'service_type' => $this->resolveServiceTypeFromLog($log),
                    'ai_response' => $log->ai_response,
                    'created_at' => optional($log->created_at)?->toISOString(),
                ];
            })
            ->all();

        $nextBeforeId = null;
        if ($hasMore && $logs->isNotEmpty()) {
            $nextBeforeId = (int) $logs->last()->id;
        }

        return response()->json([
            'status' => 'success',
            'session_id' => $normalizedSessionId,
            'data' => [
                'messages' => $messages,
                'pagination' => [
                    'limit' => $limit,
                    'has_more' => $hasMore,
                    'next_before_id' => $nextBeforeId,
                ],
            ],
        ], 200);
    }

    public function patchSessionLocation(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'service_type' => ['nullable', Rule::in(array_keys(self::SERVICE_TYPE_MAP))],
            'target' => ['required', Rule::in(['pickup', 'destination', 'dropoff', 'delivery'])],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $normalizedSessionId = substr(trim($sessionId), 0, 100);
        if ($normalizedSessionId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Session ID tidak valid.',
            ], 422);
        }

        $latestAssistantLog = AiChatLog::query()
            ->where('user_id', $user->id)
            ->where('session_id', $normalizedSessionId)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        if ($latestAssistantLog === null || !is_array($latestAssistantLog->ai_response)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Draft chatbot belum tersedia untuk diperbarui.',
            ], 422);
        }

        $existingPayload = $latestAssistantLog->ai_response;
        $serviceType = isset($validated['service_type'])
            ? (string) $validated['service_type']
            : $this->resolveServiceTypeFromLog($latestAssistantLog);
        $target = (string) $validated['target'];
        $latitude = (float) $validated['latitude'];
        $longitude = (float) $validated['longitude'];
        $rawAddress = isset($validated['address']) ? trim((string) $validated['address']) : '';
        $address = $this->resolveMapPinAddress($latitude, $longitude, $rawAddress);

        try {
            $patchedPayload = match ($serviceType) {
                'antar_jemput' => $this->patchRideDraftPayload($user, $existingPayload, $target, $latitude, $longitude, $address),
                'kurir' => $this->patchCourierDraftPayload($user, $existingPayload, $target, $latitude, $longitude, $address),
                'nitip' => $this->patchShoppingDraftPayload($existingPayload, $target, $latitude, $longitude, $address),
                default => throw new ApiException('Service type tidak didukung untuk patch lokasi.', 422),
            };
        } catch (ApiException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status());
        }

        $intent = (string) ($patchedPayload['intent'] ?? 'unknown');
        $orderId = $this->resolveOrderId($patchedPayload);
        $assistantText = trim((string) ($patchedPayload['assistant_text'] ?? 'Titik lokasi berhasil diperbarui.'));

        AiChatLog::query()->create([
            'user_id' => $user->id,
            'session_id' => $normalizedSessionId,
            'role' => 'user',
            'message' => '[MAP_PIN] '.$target.' => '.$address,
            'ai_response' => null,
            'model_used' => null,
            'intent' => $intent,
            'order_id' => $orderId,
            'created_at' => now(),
        ]);

        AiChatLog::query()->create([
            'user_id' => $user->id,
            'session_id' => $normalizedSessionId,
            'role' => 'assistant',
            'message' => $assistantText,
            'ai_response' => $patchedPayload,
            'model_used' => 'map-pin-action',
            'intent' => $intent,
            'order_id' => $orderId,
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'session_id' => $normalizedSessionId,
            'service_context' => [
                'service_type' => $serviceType,
                'service_code' => self::SERVICE_TYPE_MAP[$serviceType],
            ],
            'data' => $patchedPayload,
            'model_used' => 'map-pin-action',
        ], 200);
    }

    private function resolveMapPinAddress(float $latitude, float $longitude, string $providedAddress): string
    {
        $normalizedAddress = trim($providedAddress);

        if ($normalizedAddress !== '' && !$this->isPinPlaceholderAddress($normalizedAddress)) {
            // Even when a raw address is provided from the client, still enrich it
            // with a nearby place name so the format is "[Place Name], [Address]"
            try {
                $resolved = $this->geocodingService->reverseGeocodeWithPlaceName($latitude, $longitude);
                $enriched = trim((string) ($resolved['formatted_address'] ?? ''));
                if ($enriched !== '') {
                    return $enriched;
                }
            } catch (ApiException $exception) {
                Log::warning('Enrichment of provided map pin address failed; using raw address.', [
                    'latitude'  => $latitude,
                    'longitude' => $longitude,
                    'message'   => $exception->getMessage(),
                ]);
            }

            return $normalizedAddress;
        }

        try {
            $resolved = $this->geocodingService->reverseGeocodeWithPlaceName($latitude, $longitude);
            $formattedAddress = trim((string) ($resolved['formatted_address'] ?? ''));
            if ($formattedAddress !== '') {
                return $formattedAddress;
            }
        } catch (ApiException $exception) {
            Log::warning('Reverse geocoding map pin failed.', [
                'latitude'  => $latitude,
                'longitude' => $longitude,
                'message'   => $exception->getMessage(),
                'status'    => $exception->status(),
            ]);
        }

        return sprintf('Pin %.6f, %.6f', $latitude, $longitude);
    }

    private function isPinPlaceholderAddress(string $address): bool
    {
        return preg_match('/^pin\s+-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?$/iu', $address) === 1;
    }

    public function clearSession(Request $request, string $sessionId): JsonResponse
    {
        $normalizedSessionId = substr(trim($sessionId), 0, 100);
        if ($normalizedSessionId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Session ID tidak valid.',
            ], 422);
        }

        $deleted = AiChatLog::query()
            ->where('user_id', $request->user()->id)
            ->where('session_id', $normalizedSessionId)
            ->delete();

        return response()->json([
            'status' => 'success',
            'session_id' => $normalizedSessionId,
            'deleted' => $deleted,
        ], 200);
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
                    $modelContext = $this->buildModelContext($user, $sessionId, $serviceType);
                    $nluResult = $this->geminiService->interpretTransportMessage($serviceType, $message, $modelContext);
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

            $payload = $this->enrichTransportActionPayload($payload, $serviceType);

            $this->storeChatLogs(
                user: $user,
                sessionId: $sessionId,
                userMessage: $message,
                assistantPayload: $payload,
                modelUsed: $modelUsed
            );

            return response()->json([
                'status' => 'success',
                'session_id' => $sessionId,
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
            $modelContext = $this->buildModelContext($user, $sessionId, $serviceType);
            $parsed = $this->geminiService->parseFoodOrder($message, $modelContext);
            $rawPayload = is_array($parsed['payload'] ?? null)
                ? $parsed['payload']
                : [
                    'intent' => 'out_of_domain',
                    'resto' => null,
                    'items' => [],
                ];

            $validatedPayload = $this->validator->validate($rawPayload);
            $validatedPayload = $this->enrichShoppingActionPayload($validatedPayload);
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
                'session_id' => $sessionId,
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

    /**
     * @return array<string, mixed>
     */
    private function buildModelContext(User $user, string $sessionId, string $serviceType): array
    {
        $logs = AiChatLog::query()
            ->where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->orderByDesc('id')
            ->limit(self::MODEL_CONTEXT_TURN_LIMIT)
            ->get();

        $recentTurns = $logs
            ->reverse()
            ->map(function (AiChatLog $log): array {
                return [
                    'role' => (string) $log->role,
                    'message' => $this->truncateContextText((string) $log->message, 240),
                    'intent' => $this->normalizeOptionalContextString($log->intent),
                    'model_used' => $this->normalizeOptionalContextString($log->model_used),
                    'created_at' => optional($log->created_at)?->toISOString(),
                ];
            })
            ->values()
            ->all();

        $latestAssistantPayload = $logs
            ->first(static fn (AiChatLog $log): bool => $log->role === 'assistant' && is_array($log->ai_response));

        return [
            'service_type' => $serviceType,
            'session_id' => $sessionId,
            'current_time' => now()->toIso8601String(),
            'timezone' => (string) config('app.timezone', 'Asia/Jakarta'),
            'recent_turns' => $recentTurns,
            'draft_state' => $this->extractDraftStateSummary(
                is_array($latestAssistantPayload?->ai_response ?? null)
                    ? $latestAssistantPayload->ai_response
                    : null
            ),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function extractDraftStateSummary(?array $payload): array
    {
        if ($payload === null) {
            return [];
        }

        $validation = is_array($payload['validation'] ?? null)
            ? $payload['validation']
            : [];
        $summary = [
            'intent' => (string) ($payload['intent'] ?? 'unknown'),
            'service_type' => (string) ($payload['service_type'] ?? ''),
            'validation' => [
                'is_valid_order' => ($validation['is_valid_order'] ?? false) === true,
                'missing_fields' => $this->normalizeStringList($validation['missing_fields'] ?? []),
                'next_actions' => $this->normalizeStringList($validation['next_actions'] ?? []),
            ],
        ];

        if (is_array($payload['ride'] ?? null)) {
            $ride = $payload['ride'];
            $summary['ride'] = [
                'pickup_address' => $this->normalizeOptionalContextString($ride['pickup_address'] ?? null),
                'destination_address' => $this->normalizeOptionalContextString($ride['destination_address'] ?? null),
            ];
        }

        if (is_array($payload['courier'] ?? null)) {
            $courier = $payload['courier'];
            $summary['courier'] = [
                'pickup_address' => $this->normalizeOptionalContextString($courier['pickup_address'] ?? null),
                'dropoff_address' => $this->normalizeOptionalContextString($courier['dropoff_address'] ?? null),
                'package_description' => $this->normalizeOptionalContextString($courier['package_description'] ?? null),
                'estimated_weight_kg' => $courier['estimated_weight_kg'] ?? null,
                'package_length_cm' => $courier['package_length_cm'] ?? null,
                'package_width_cm' => $courier['package_width_cm'] ?? null,
                'package_height_cm' => $courier['package_height_cm'] ?? null,
                'packing_note' => $this->normalizeOptionalContextString($courier['packing_note'] ?? null),
                'safety_status' => $courier['safety_status'] ?? null,
                'safety_reason' => $this->normalizeOptionalContextString($courier['safety_reason'] ?? null),
            ];
        }

        if (is_array($payload['delivery'] ?? null)) {
            $delivery = $payload['delivery'];
            $summary['delivery'] = [
                'address' => $this->normalizeOptionalContextString($delivery['address'] ?? null),
            ];
        }

        return $summary;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $valueString = trim((string) $value);
            if ($valueString !== '') {
                $normalized[] = $valueString;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeOptionalContextString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $this->truncateContextText($normalized, 240);
    }

    /**
     * @param  array<string, mixed>  $courier
     */
    private function formatCourierPackageSizeLine(array $courier): string
    {
        $parts = [];
        if (is_numeric($courier['estimated_weight_kg'] ?? null)) {
            $parts[] = rtrim(rtrim(number_format((float) $courier['estimated_weight_kg'], 2, ',', '.'), '0'), ',').' kg';
        }

        $dimensions = array_filter([
            $courier['package_length_cm'] ?? null,
            $courier['package_width_cm'] ?? null,
            $courier['package_height_cm'] ?? null,
        ], fn ($value): bool => is_numeric($value) && (int) $value > 0);

        if ($dimensions !== []) {
            $parts[] = implode('x', array_map(fn ($value): string => (string) (int) $value, $dimensions)).' cm';
        }

        return $parts === [] ? 'kecil/ringan untuk motor' : implode(' - ', $parts);
    }

    private function truncateContextText(string $text, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(1, $maxLength - 3))).'...';
    }

    private function resolveServiceTypeFromLog(AiChatLog $log): string
    {
        $aiResponse = $log->ai_response;
        if (is_array($aiResponse)) {
            $serviceType = trim((string) ($aiResponse['service_type'] ?? ''));
            if (array_key_exists($serviceType, self::SERVICE_TYPE_MAP)) {
                return $serviceType;
            }
        }

        $intent = strtolower(trim((string) ($log->intent ?? '')));

        return self::INTENT_TO_SERVICE_TYPE[$intent] ?? 'nitip';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichTransportActionPayload(array $payload, string $serviceType): array
    {
        $validation = is_array($payload['validation'] ?? null)
            ? $payload['validation']
            : [];

        $nextActionsRaw = is_array($validation['next_actions'] ?? null)
            ? $validation['next_actions']
            : [];
        $nextActions = [];
        foreach ($nextActionsRaw as $action) {
            $normalized = strtoupper(trim((string) $action));
            if ($normalized !== '') {
                $nextActions[] = $normalized;
            }
        }

        $missingFields = is_array($validation['missing_fields'] ?? null)
            ? $validation['missing_fields']
            : [];
        $missingFieldSet = [];
        foreach ($missingFields as $field) {
            $missingFieldSet[] = strtolower(trim((string) $field));
        }

        if ($serviceType === 'antar_jemput') {
            if (in_array('pickup_address', $missingFieldSet, true)) {
                $nextActions[] = 'OPEN_MAP_PICKER_PICKUP';
            }
            if (in_array('destination_address', $missingFieldSet, true)) {
                $nextActions[] = 'OPEN_MAP_PICKER_DESTINATION';
            }
        }

        if ($serviceType === 'kurir') {
            if (in_array('pickup_address', $missingFieldSet, true)) {
                $nextActions[] = 'OPEN_MAP_PICKER_PICKUP';
            }
            if (in_array('dropoff_address', $missingFieldSet, true)) {
                $nextActions[] = 'OPEN_MAP_PICKER_DROPOFF';
            }
        }

        $nextActions = array_values(array_unique($nextActions));
        $nextActions = $this->orderTransportActions($nextActions, $serviceType);
        $validation['next_actions'] = $nextActions;
        $payload['validation'] = $validation;

        $actionPayloads = is_array($payload['action_payloads'] ?? null)
            ? $payload['action_payloads']
            : [];

        if ($serviceType === 'antar_jemput') {
            $ride = is_array($payload['ride'] ?? null) ? $payload['ride'] : [];

            if (in_array('OPEN_MAP_PICKER_PICKUP', $nextActions, true)) {
                $existing = is_array($actionPayloads['OPEN_MAP_PICKER_PICKUP'] ?? null)
                    ? $actionPayloads['OPEN_MAP_PICKER_PICKUP']
                    : [];
                $actionPayloads['OPEN_MAP_PICKER_PICKUP'] = array_merge([
                    'target' => 'pickup',
                    'label' => 'Pilih Titik Jemput',
                    'initial_latitude' => $ride['pickup_latitude'] ?? null,
                    'initial_longitude' => $ride['pickup_longitude'] ?? null,
                ], $existing);
            }

            if (in_array('OPEN_MAP_PICKER_DESTINATION', $nextActions, true)) {
                $existing = is_array($actionPayloads['OPEN_MAP_PICKER_DESTINATION'] ?? null)
                    ? $actionPayloads['OPEN_MAP_PICKER_DESTINATION']
                    : [];
                $actionPayloads['OPEN_MAP_PICKER_DESTINATION'] = array_merge([
                    'target' => 'destination',
                    'label' => 'Pilih Titik Tujuan',
                    'initial_latitude' => $ride['destination_latitude'] ?? null,
                    'initial_longitude' => $ride['destination_longitude'] ?? null,
                ], $existing);
            }
        }

        if ($serviceType === 'kurir') {
            $courier = is_array($payload['courier'] ?? null) ? $payload['courier'] : [];

            if (in_array('OPEN_MAP_PICKER_PICKUP', $nextActions, true)) {
                $existing = is_array($actionPayloads['OPEN_MAP_PICKER_PICKUP'] ?? null)
                    ? $actionPayloads['OPEN_MAP_PICKER_PICKUP']
                    : [];
                $actionPayloads['OPEN_MAP_PICKER_PICKUP'] = array_merge([
                    'target' => 'pickup',
                    'label' => 'Pilih Titik Ambil',
                    'initial_latitude' => $courier['pickup_latitude'] ?? null,
                    'initial_longitude' => $courier['pickup_longitude'] ?? null,
                ], $existing);
            }

            if (in_array('OPEN_MAP_PICKER_DROPOFF', $nextActions, true)) {
                $existing = is_array($actionPayloads['OPEN_MAP_PICKER_DROPOFF'] ?? null)
                    ? $actionPayloads['OPEN_MAP_PICKER_DROPOFF']
                    : [];
                $actionPayloads['OPEN_MAP_PICKER_DROPOFF'] = array_merge([
                    'target' => 'dropoff',
                    'label' => 'Pilih Titik Tujuan',
                    'initial_latitude' => $courier['dropoff_latitude'] ?? null,
                    'initial_longitude' => $courier['dropoff_longitude'] ?? null,
                ], $existing);
            }
        }

        if (in_array('CONFIRM_DRAFT', $nextActions, true)) {
            $actionPayloads['CONFIRM_DRAFT'] = [
                'label' => 'Konfirmasi',
                'message' => 'Konfirmasi',
            ];
        }

        if (in_array('RESET_DESTINATION', $nextActions, true)) {
            $actionPayloads['RESET_DESTINATION'] = [
                'label'   => 'Ubah Tujuan',
                'message' => 'Ubah Tujuan',
            ];
        }

        // Optional pickup-change button (shown only on complete draft, not mandatory)
        if (in_array('CHANGE_PICKUP', $nextActions, true)) {
            if ($serviceType === 'antar_jemput') {
                $ride = is_array($payload['ride'] ?? null) ? $payload['ride'] : [];
                $actionPayloads['CHANGE_PICKUP'] = [
                    'target'            => 'pickup',
                    'label'             => 'Ubah Titik Jemput',
                    'initial_latitude'  => $ride['pickup_latitude'] ?? null,
                    'initial_longitude' => $ride['pickup_longitude'] ?? null,
                ];
            } elseif ($serviceType === 'kurir') {
                $courier = is_array($payload['courier'] ?? null) ? $payload['courier'] : [];
                $actionPayloads['CHANGE_PICKUP'] = [
                    'target'            => 'pickup',
                    'label'             => 'Ubah Titik Ambil',
                    'initial_latitude'  => $courier['pickup_latitude'] ?? null,
                    'initial_longitude' => $courier['pickup_longitude'] ?? null,
                ];
            }
        }

        $payload['action_payloads'] = $actionPayloads;

        return $payload;
    }

    /**
     * @param  array<int, string>  $nextActions
     * @return array<int, string>
     */
    private function orderTransportActions(array $nextActions, string $serviceType): array
    {
        $priority = match ($serviceType) {
            'antar_jemput' => [
                'OPEN_ADDRESSES',
                'OPEN_MAP_PICKER_PICKUP',
                'OPEN_MAP_PICKER_DESTINATION',
                'CONFIRM_DRAFT',
                'RESET_DESTINATION',
                'CHANGE_PICKUP',
            ],
            'kurir' => [
                'OPEN_ADDRESSES',
                'OPEN_MAP_PICKER_PICKUP',
                'OPEN_MAP_PICKER_DROPOFF',
                'CONFIRM_DRAFT',
                'RESET_DESTINATION',
                'CHANGE_PICKUP',
            ],
            default => [],
        };

        if ($priority === []) {
            return $nextActions;
        }

        $ordered = [];
        foreach ($priority as $candidate) {
            if (in_array($candidate, $nextActions, true)) {
                $ordered[] = $candidate;
            }
        }

        foreach ($nextActions as $action) {
            if (!in_array($action, $ordered, true)) {
                $ordered[] = $action;
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichShoppingActionPayload(array $payload): array
    {
        $intent = strtolower(trim((string) ($payload['intent'] ?? '')));
        $validation = is_array($payload['validation'] ?? null)
            ? $payload['validation']
            : [];

        if ($intent === 'pesan_makanan') {
            $nextActionsRaw = is_array($validation['next_actions'] ?? null)
                ? $validation['next_actions']
                : [];
            $nextActions = [];
            foreach ($nextActionsRaw as $action) {
                $normalized = strtoupper(trim((string) $action));
                if ($normalized !== '') {
                    $nextActions[] = $normalized;
                }
            }

            $nextActions[] = 'OPEN_MAP_PICKER_DELIVERY';
            $validation['next_actions'] = array_values(array_unique($nextActions));

            $delivery = is_array($payload['delivery'] ?? null)
                ? $payload['delivery']
                : [];

            $actionPayloads = is_array($payload['action_payloads'] ?? null)
                ? $payload['action_payloads']
                : [];
            $actionPayloads['OPEN_MAP_PICKER_DELIVERY'] = [
                'target' => 'delivery',
                'label' => 'Pilih Titik Antar',
                'initial_latitude' => $delivery['latitude'] ?? null,
                'initial_longitude' => $delivery['longitude'] ?? null,
            ];

            $payload['action_payloads'] = $actionPayloads;
        }

        $payload['validation'] = $validation;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function patchRideDraftPayload(
        User $user,
        array $payload,
        string $target,
        float $latitude,
        float $longitude,
        string $address
    ): array {
        if ($target !== 'pickup' && $target !== 'destination') {
            throw new ApiException('Target lokasi antar jemput tidak valid.', 422);
        }

        $ride = is_array($payload['ride'] ?? null) ? $payload['ride'] : [];
        $ride['pickup_address'] = $ride['pickup_address'] ?? null;
        $ride['destination_address'] = $ride['destination_address'] ?? null;
        $ride['pickup_latitude'] = $ride['pickup_latitude'] ?? null;
        $ride['pickup_longitude'] = $ride['pickup_longitude'] ?? null;
        $ride['destination_latitude'] = $ride['destination_latitude'] ?? null;
        $ride['destination_longitude'] = $ride['destination_longitude'] ?? null;

        if ($target === 'pickup') {
            $ride['pickup_address'] = $address;
            $ride['pickup_latitude'] = $latitude;
            $ride['pickup_longitude'] = $longitude;
            $ride['pickup_address_id'] = null;
            $ride['used_default_pickup'] = false;
        }

        if ($target === 'destination') {
            $ride['destination_address'] = $address;
            $ride['destination_latitude'] = $latitude;
            $ride['destination_longitude'] = $longitude;
        }

        // ── If pickup coords are missing but pickup_address is known, resolve them ─────
        if (
            $target === 'destination'
            && trim((string) ($ride['pickup_address'] ?? '')) !== ''
            && !is_numeric($ride['pickup_latitude'] ?? null)
        ) {
            try {
                $resolvedPickup = $this->geocodingService->resolveAddress((string) $ride['pickup_address']);
                if ($resolvedPickup !== null) {
                    $ride['pickup_latitude']  = $resolvedPickup['latitude'];
                    $ride['pickup_longitude'] = $resolvedPickup['longitude'];
                }
            } catch (\Throwable $e) {
                // non-fatal – coords remain null; pickupReady will be false below
                Log::warning('Could not geocode existing pickup address in patchRideDraftPayload.', [
                    'pickup_address' => $ride['pickup_address'],
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        $pickupReady = trim((string) ($ride['pickup_address'] ?? '')) !== ''
            && is_numeric($ride['pickup_latitude'] ?? null)
            && is_numeric($ride['pickup_longitude'] ?? null);
        $destinationReady = trim((string) ($ride['destination_address'] ?? '')) !== ''
            && is_numeric($ride['destination_latitude'] ?? null)
            && is_numeric($ride['destination_longitude'] ?? null);

        $missingFields = [];
        if (!$pickupReady) {
            $missingFields[] = 'pickup_address';
        }
        if (!$destinationReady) {
            $missingFields[] = 'destination_address';
        }

        $isValid = $pickupReady && $destinationReady;

        $validation = is_array($payload['validation'] ?? null)
            ? $payload['validation']
            : [];
        $validation['is_valid_order'] = $isValid;
        $validation['missing_fields'] = $missingFields;
        $validation['rejection_reasons'] = $isValid
            ? []
            : ['Lokasi jemput dan tujuan wajib lengkap sebelum konfirmasi.'];

        $nextActions = [];
        if (in_array('pickup_address', $missingFields, true)) {
            // pickup truly missing — mandatory action
            $nextActions[] = 'OPEN_MAP_PICKER_PICKUP';
        }
        if (in_array('destination_address', $missingFields, true)) {
            $nextActions[] = 'OPEN_MAP_PICKER_DESTINATION';
        }
        if ($isValid) {
            $nextActions[] = 'CONFIRM_DRAFT';
            $nextActions[] = 'RESET_DESTINATION';
            // Optional: let user change pickup without being forced to
            $nextActions[] = 'CHANGE_PICKUP';
        }
        $validation['next_actions'] = $nextActions;

        if ($isValid) {
            try {
                $route = app(\App\Services\GoogleMapsDistanceMatrixService::class)->resolveRoute(
                    $ride['pickup_latitude'],
                    $ride['pickup_longitude'],
                    $ride['destination_latitude'],
                    $ride['destination_longitude'],
                );

                $distanceMeters = (float) $route['distance_meters'];
                $distanceKm = (float) $route['distance_km'];

                if (!app(\App\Services\DeliveryPricingService::class)->isWithinMaxDistance($distanceMeters)) {
                    $isValid = false;
                    $validation['is_valid_order'] = false;
                    $missingFields[] = 'destination_address';
                    $validation['missing_fields'] = $missingFields;
                    $validation['rejection_reasons'] = [sprintf('Jarak %.2f km melebihi batas layanan %.2f km.', $distanceKm, app(\App\Services\DeliveryPricingService::class)->getMaxDistanceKm())];
                    $validation['next_actions'] = ['OPEN_MAP_PICKER_DESTINATION'];
                } else {
                    $ride['distance_km'] = $distanceKm;
                    $ride['delivery_fee'] = (float) app(\App\Services\DeliveryPricingService::class)->calculateFromDistanceMeters($distanceMeters)['total_fee'];
                }
            } catch (\Exception $e) {
                $isValid = false;
                $validation['is_valid_order'] = false;
                $missingFields[] = 'destination_address';
                $validation['missing_fields'] = $missingFields;
                $validation['rejection_reasons'] = ['Rute jemput ke tujuan tidak ditemukan.'];
                $validation['next_actions'] = ['OPEN_MAP_PICKER_DESTINATION'];
            }
        }

        $ride['ready_to_confirm'] = $isValid;
        $payload['intent'] = 'ride_order';
        $payload['service_type'] = 'antar_jemput';
        $payload['ride'] = $ride;
        $payload['validation'] = $validation;

        if ($isValid) {
            $name = trim((string) $user->name) === '' ? 'Kak' : trim((string) $user->name);
            $deliveryFee = number_format((float) ($ride['delivery_fee'] ?? 0), 0, ',', '.');
            $buffer = "Baik {$name}, saya sudah siapkan draft Antar Jemput.\n";
            $buffer .= 'Jemput: '.(string) $ride['pickup_address']."\n";
            $buffer .= 'Tujuan: '.(string) $ride['destination_address']."\n";
            $buffer .= "Estimasi ongkir sementara: Rp {$deliveryFee} (kalkulasi detail menyusul).\n";
            $buffer .= 'Ketik "Konfirmasi" untuk lanjut atau "Ubah Tujuan" untuk ganti tujuan.';
            $payload['assistant_text'] = $buffer;
        } else {
            $payload['assistant_text'] = 'Titik '.$target.' berhasil diperbarui. Lengkapi titik lain agar draft siap dikonfirmasi.';
            if (isset($validation['rejection_reasons'][0])) {
                $payload['assistant_text'] .= "\n" . $validation['rejection_reasons'][0];
            }
        }

        if (!is_array($payload['order'] ?? null)) {
            $payload['order'] = [
                'created' => false,
                'id' => null,
                'order_number' => null,
                'delivery_fee' => null,
            ];
        }

        return $this->enrichTransportActionPayload($payload, 'antar_jemput');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function patchCourierDraftPayload(
        User $user,
        array $payload,
        string $target,
        float $latitude,
        float $longitude,
        string $address
    ): array {
        if ($target !== 'pickup' && $target !== 'dropoff') {
            throw new ApiException('Target lokasi kurir tidak valid.', 422);
        }

        $courier = is_array($payload['courier'] ?? null) ? $payload['courier'] : [];
        $courier['pickup_address'] = $courier['pickup_address'] ?? null;
        $courier['dropoff_address'] = $courier['dropoff_address'] ?? null;
        $courier['pickup_latitude'] = $courier['pickup_latitude'] ?? null;
        $courier['pickup_longitude'] = $courier['pickup_longitude'] ?? null;
        $courier['dropoff_latitude'] = $courier['dropoff_latitude'] ?? null;
        $courier['dropoff_longitude'] = $courier['dropoff_longitude'] ?? null;

        if ($target === 'pickup') {
            $courier['pickup_address'] = $address;
            $courier['pickup_latitude'] = $latitude;
            $courier['pickup_longitude'] = $longitude;
            $courier['pickup_address_id'] = null;
            $courier['used_default_pickup'] = false;
        }

        if ($target === 'dropoff') {
            $courier['dropoff_address'] = $address;
            $courier['dropoff_latitude'] = $latitude;
            $courier['dropoff_longitude'] = $longitude;
        }

        // ── If pickup coords are missing but pickup_address is known, resolve them ─────
        if (
            $target === 'dropoff'
            && trim((string) ($courier['pickup_address'] ?? '')) !== ''
            && !is_numeric($courier['pickup_latitude'] ?? null)
        ) {
            try {
                $resolvedPickup = $this->geocodingService->resolveAddress((string) $courier['pickup_address']);
                if ($resolvedPickup !== null) {
                    $courier['pickup_latitude']  = $resolvedPickup['latitude'];
                    $courier['pickup_longitude'] = $resolvedPickup['longitude'];
                }
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        $pickupReady = trim((string) ($courier['pickup_address'] ?? '')) !== ''
            && is_numeric($courier['pickup_latitude'] ?? null)
            && is_numeric($courier['pickup_longitude'] ?? null);
        $dropoffReady = trim((string) ($courier['dropoff_address'] ?? '')) !== ''
            && is_numeric($courier['dropoff_latitude'] ?? null)
            && is_numeric($courier['dropoff_longitude'] ?? null);
        $packageReady = trim((string) ($courier['package_description'] ?? '')) !== '';

        $missingFields = [];
        if (!$pickupReady) {
            $missingFields[] = 'pickup_address';
        }
        if (!$dropoffReady) {
            $missingFields[] = 'dropoff_address';
        }
        if (!$packageReady) {
            $missingFields[] = 'package_description';
        }

        $packagePolicy = app(\App\Services\CourierPackagePolicyService::class)->evaluate([
            'package_description' => $courier['package_description'] ?? null,
            'estimated_weight_kg' => $courier['estimated_weight_kg'] ?? null,
            'package_length_cm' => $courier['package_length_cm'] ?? null,
            'package_width_cm' => $courier['package_width_cm'] ?? null,
            'package_height_cm' => $courier['package_height_cm'] ?? null,
            'packing_note' => $courier['packing_note'] ?? null,
        ]);

        $courier['safety_status'] = $packagePolicy['safety_status'] ?? null;
        $courier['safety_flags'] = $packagePolicy['safety_flags'] ?? [];
        $courier['safety_reason'] = $packagePolicy['safety_reason'] ?? null;
        $courier['size_class'] = $packagePolicy['size_class'] ?? null;
        $courier['estimated_weight_kg'] = $packagePolicy['estimated_weight_kg'] ?? null;
        $courier['package_length_cm'] = $packagePolicy['package_length_cm'] ?? null;
        $courier['package_width_cm'] = $packagePolicy['package_width_cm'] ?? null;
        $courier['package_height_cm'] = $packagePolicy['package_height_cm'] ?? null;
        $courier['packing_note'] = $packagePolicy['packing_note'] ?? null;

        $isPackageAllowed = ($packagePolicy['safety_status'] ?? null) === \App\Services\CourierPackagePolicyService::STATUS_ALLOWED;
        $isValid = $pickupReady && $dropoffReady && $packageReady && $isPackageAllowed;

        $validation = is_array($payload['validation'] ?? null)
            ? $payload['validation']
            : [];
        $validation['is_valid_order'] = $isValid;
        $validation['missing_fields'] = $missingFields;
        $validation['rejection_reasons'] = $isValid
            ? []
            : ['Lokasi ambil, lokasi tujuan, dan isi paket wajib lengkap sebelum konfirmasi.'];

        $nextActions = [];
        if (in_array('pickup_address', $missingFields, true)) {
            $nextActions[] = 'OPEN_MAP_PICKER_PICKUP';
        }
        if (in_array('dropoff_address', $missingFields, true)) {
            $nextActions[] = 'OPEN_MAP_PICKER_DROPOFF';
        }
        if ($packageReady && !$isPackageAllowed) {
            $validation['is_valid_order'] = false;
            $validation['missing_fields'] = array_values(array_unique([...$missingFields, 'package_description']));
            $validation['rejection_reasons'] = [$packagePolicy['safety_reason'] ?? 'Barang belum memenuhi kebijakan layanan kurir motor.'];
            $validation['next_actions'] = [];
        }

        if ($isValid) {
            $nextActions[] = 'CONFIRM_DRAFT';
            $nextActions[] = 'RESET_DESTINATION';
            $nextActions[] = 'CHANGE_PICKUP';
        }
        $validation['next_actions'] = $nextActions;

        if ($isValid) {
            try {
                $route = app(\App\Services\GoogleMapsDistanceMatrixService::class)->resolveRoute(
                    $courier['pickup_latitude'],
                    $courier['pickup_longitude'],
                    $courier['dropoff_latitude'],
                    $courier['dropoff_longitude'],
                );

                $distanceMeters = (float) $route['distance_meters'];
                $distanceKm = (float) $route['distance_km'];

                if (!app(\App\Services\DeliveryPricingService::class)->isWithinMaxDistance($distanceMeters)) {
                    $isValid = false;
                    $validation['is_valid_order'] = false;
                    $missingFields[] = 'dropoff_address';
                    $validation['missing_fields'] = $missingFields;
                    $validation['rejection_reasons'] = [sprintf('Jarak %.2f km melebihi batas layanan %.2f km.', $distanceKm, app(\App\Services\DeliveryPricingService::class)->getMaxDistanceKm())];
                    $validation['next_actions'] = ['OPEN_MAP_PICKER_DROPOFF'];
                } else {
                    $courier['distance_km'] = $distanceKm;
                    $courier['delivery_fee'] = (float) app(\App\Services\DeliveryPricingService::class)->calculateFromDistanceMeters($distanceMeters)['total_fee'];
                }
            } catch (\Exception $e) {
                $isValid = false;
                $validation['is_valid_order'] = false;
                $missingFields[] = 'dropoff_address';
                $validation['missing_fields'] = $missingFields;
                $validation['rejection_reasons'] = ['Rute jemput ke tujuan tidak ditemukan.'];
                $validation['next_actions'] = ['OPEN_MAP_PICKER_DROPOFF'];
            }
        }

        $courier['ready_to_confirm'] = $isValid;
        $payload['intent'] = 'courier_order';
        $payload['service_type'] = 'kurir';
        $payload['courier'] = $courier;
        $payload['validation'] = $validation;

        if ($isValid) {
            $name = trim((string) $user->name) === '' ? 'Kak' : trim((string) $user->name);
            $deliveryFee = number_format((float) ($courier['delivery_fee'] ?? 0), 0, ',', '.');
            $buffer = "Baik {$name}, saya sudah siapkan draft pengiriman Kurir.\n";
            $buffer .= 'Ambil: '.(string) $courier['pickup_address']."\n";
            $buffer .= 'Tujuan: '.(string) $courier['dropoff_address']."\n";
            $buffer .= 'Barang: '.(string) $courier['package_description']."\n";
            $buffer .= 'Ukuran/Berat: '.$this->formatCourierPackageSizeLine($courier)."\n";
            $buffer .= 'Status barang: '.(string) ($courier['safety_reason'] ?? 'Paket aman untuk layanan kurir motor.')."\n";
            $buffer .= "Estimasi ongkir sementara: Rp {$deliveryFee} (kalkulasi detail menyusul).\n";
            $buffer .= 'Ketik "Konfirmasi" untuk lanjut. Pembayaran dilakukan tunai saat driver tiba dan mengecek barang di titik ambil.';
            $payload['assistant_text'] = $buffer;
        } else {
            $payload['assistant_text'] = 'Titik '.$target.' berhasil diperbarui. Lengkapi data lain agar draft siap dikonfirmasi.';
            if (isset($validation['rejection_reasons'][0])) {
                $payload['assistant_text'] .= "\n" . $validation['rejection_reasons'][0];
            }
        }

        if (!is_array($payload['order'] ?? null)) {
            $payload['order'] = [
                'created' => false,
                'id' => null,
                'order_number' => null,
                'delivery_fee' => null,
            ];
        }

        return $this->enrichTransportActionPayload($payload, 'kurir');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function patchShoppingDraftPayload(
        array $payload,
        string $target,
        float $latitude,
        float $longitude,
        string $address
    ): array {
        if ($target !== 'delivery') {
            throw new ApiException('Target lokasi nitip tidak valid.', 422);
        }

        $delivery = is_array($payload['delivery'] ?? null) ? $payload['delivery'] : [];
        $delivery['address'] = $address;
        $delivery['latitude'] = $latitude;
        $delivery['longitude'] = $longitude;
        $delivery['source'] = 'map_pin';

        $payload['delivery'] = $delivery;
        $payload['intent'] = (string) ($payload['intent'] ?? 'pesan_makanan');
        $payload['service_type'] = 'nitip';
        $payload['assistant_text'] = 'Titik antar berhasil diperbarui. Lanjutkan detail pesananmu, ya.';

        return $this->enrichShoppingActionPayload($payload);
    }
}
