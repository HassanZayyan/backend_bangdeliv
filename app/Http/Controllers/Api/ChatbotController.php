<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AiChatLog;
use App\Models\User;
use App\Services\ChatbotCourierOrderService;
use App\Services\ChatbotGeminiService;
use App\Services\ChatbotRideOrderService;
use App\Services\ChatbotShoppingOrderService;
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
        'shopping_order' => 'nitip',
        'pesan_makanan' => 'nitip',
        'out_of_domain' => 'nitip',
    ];

    public function __construct(
        private readonly ChatbotCourierOrderService $courierOrderService,
        private readonly ChatbotRideOrderService $rideOrderService,
        private readonly ChatbotShoppingOrderService $shoppingOrderService,
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

        $serviceType = isset($validated['service_type'])
            ? (string) $validated['service_type']
            : ($latestAssistantLog === null ? 'nitip' : $this->resolveServiceTypeFromLog($latestAssistantLog));
        $target = (string) $validated['target'];
        $latitude = (float) $validated['latitude'];
        $longitude = (float) $validated['longitude'];
        $rawAddress = isset($validated['address']) ? trim((string) $validated['address']) : '';
        $address = $this->resolveMapPinAddress($latitude, $longitude, $rawAddress);

        try {
            $patchedPayload = match ($serviceType) {
                'antar_jemput' => $this->rideOrderService->applyLocationPatch($user, $normalizedSessionId, $target, $latitude, $longitude, $address),
                'kurir' => $this->courierOrderService->applyLocationPatch($user, $normalizedSessionId, $target, $latitude, $longitude, $address),
                'nitip' => $this->shoppingOrderService->applyLocationPatch($user, $normalizedSessionId, $target, $latitude, $longitude, $address),
                default => throw new ApiException('Service type tidak didukung untuk patch lokasi.', 422),
            };
        } catch (ApiException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status());
        }

        if ($serviceType === 'antar_jemput' || $serviceType === 'kurir') {
            $patchedPayload = $this->enrichTransportActionPayload($patchedPayload, $serviceType);
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

    public function patchSessionLocations(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'service_type' => ['required', Rule::in(['antar_jemput', 'kurir'])],
            'locations' => ['required', 'array', 'min:1', 'max:2'],
            'locations.*.target' => ['required', Rule::in(['pickup', 'destination', 'dropoff'])],
            'locations.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'locations.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'locations.*.address' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $normalizedSessionId = substr(trim($sessionId), 0, 100);
        if ($normalizedSessionId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Session ID tidak valid.',
            ], 422);
        }

        $serviceType = (string) $validated['service_type'];
        $locations = [];
        foreach ($validated['locations'] as $location) {
            $target = (string) $location['target'];
            if ($serviceType === 'antar_jemput' && ! in_array($target, ['pickup', 'destination'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Target lokasi antar jemput tidak valid.',
                ], 422);
            }

            if ($serviceType === 'kurir' && ! in_array($target, ['pickup', 'dropoff'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Target lokasi kurir tidak valid.',
                ], 422);
            }

            $latitude = (float) $location['latitude'];
            $longitude = (float) $location['longitude'];
            $rawAddress = isset($location['address']) ? trim((string) $location['address']) : '';

            $locations[] = [
                'target' => $target,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'address' => $this->resolveMapPinAddress($latitude, $longitude, $rawAddress),
            ];
        }

        try {
            $patchedPayload = match ($serviceType) {
                'antar_jemput' => $this->rideOrderService->applyLocationPatches($user, $normalizedSessionId, $locations),
                'kurir' => $this->courierOrderService->applyLocationPatches($user, $normalizedSessionId, $locations),
            };
        } catch (ApiException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status());
        }

        $patchedPayload = $this->enrichTransportActionPayload($patchedPayload, $serviceType);
        $intent = (string) ($patchedPayload['intent'] ?? 'unknown');
        $orderId = $this->resolveOrderId($patchedPayload);
        $assistantText = trim((string) ($patchedPayload['assistant_text'] ?? 'Titik rute berhasil diperbarui.'));

        $routeSummary = collect($locations)
            ->map(static fn (array $location): string => $location['target'].' => '.$location['address'])
            ->implode('; ');

        AiChatLog::query()->create([
            'user_id' => $user->id,
            'session_id' => $normalizedSessionId,
            'role' => 'user',
            'message' => '[MAP_ROUTE] '.$routeSummary,
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
            'model_used' => 'map-route-action',
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
            'model_used' => 'map-route-action',
        ], 200);
    }

    private function resolveMapPinAddress(float $latitude, float $longitude, string $providedAddress): string
    {
        $normalizedAddress = trim($providedAddress);

        if ($normalizedAddress !== '' && !$this->isPinPlaceholderAddress($normalizedAddress)) {
            return $normalizedAddress;
        }

        try {
            $resolved = $this->geocodingService->reverseGeocodeWithPlaceName($latitude, $longitude);
            $formattedAddress = trim((string) ($resolved['formatted_address'] ?? ''));
            if ($formattedAddress !== '' && !$this->isPinPlaceholderAddress($formattedAddress)) {
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

        return 'Titik dipilih di peta';
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
            $nluPayload = null;
            $modelUsed = null;
            $fastCommand = $this->detectTransportFastCommand($message);

            if ($fastCommand !== null) {
                $nluPayload = ['command' => $fastCommand];
                $modelUsed = 'deterministic-command';
            } else {
                try {
                    $modelContext = $this->buildModelContext($user, $sessionId, $serviceType);
                    $parsed = $this->geminiService->parseFoodOrder($message, $modelContext);
                    $nluPayload = is_array($parsed['payload'] ?? null) ? $parsed['payload'] : null;
                    $modelUsed = isset($parsed['model_used']) ? (string) $parsed['model_used'] : null;
                } catch (ApiException $exception) {
                    $nluPayload = null;
                    $modelUsed = null;
                }
            }

            $shoppingPayload = $this->shoppingOrderService->process($user, $message, $sessionId, $nluPayload);

            $this->storeChatLogs(
                user: $user,
                sessionId: $sessionId,
                userMessage: $message,
                assistantPayload: $shoppingPayload,
                modelUsed: $modelUsed
            );

            return response()->json([
                'status' => 'success',
                'session_id' => $sessionId,
                'service_context' => [
                    'service_type' => $serviceType,
                    'service_code' => $serviceCode,
                ],
                'data' => $shoppingPayload,
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

        if (is_array($payload['shopping'] ?? null)) {
            $shopping = $payload['shopping'];
            $merchant = is_array($shopping['merchant'] ?? null) ? $shopping['merchant'] : [];
            $delivery = is_array($shopping['delivery'] ?? null) ? $shopping['delivery'] : [];
            $items = is_array($shopping['items'] ?? null) ? $shopping['items'] : [];
            $summary['shopping'] = [
                'merchant_name' => $this->normalizeOptionalContextString($merchant['name'] ?? null),
                'delivery_address' => $this->normalizeOptionalContextString($delivery['address'] ?? null),
                'item_count' => count($items),
                'ready_to_confirm' => (bool) ($shopping['ready_to_confirm'] ?? false),
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

        $transportMapActions = match ($serviceType) {
            'antar_jemput' => ['OPEN_MAP_PICKER_PICKUP', 'OPEN_MAP_PICKER_DESTINATION'],
            'kurir' => ['OPEN_MAP_PICKER_PICKUP', 'OPEN_MAP_PICKER_DROPOFF'],
            default => [],
        };

        if (in_array('OPEN_ADDRESSES', $nextActions, true)) {
            $nextActions = array_values(array_filter(
                $nextActions,
                static fn (string $action): bool => $action === 'OPEN_ADDRESSES'
            ));
        }

        $hasTransportMapAction = false;
        foreach ($transportMapActions as $action) {
            if (in_array($action, $nextActions, true)) {
                $hasTransportMapAction = true;
                break;
            }
        }
        if (
            ($hasTransportMapAction || in_array('OPEN_ROUTE_PICKER', $nextActions, true)) &&
            ! in_array('OPEN_ADDRESSES', $nextActions, true)
        ) {
            $nextActions = array_values(array_filter(
                $nextActions,
                static fn (string $action): bool => ! in_array($action, $transportMapActions, true)
            ));
            $nextActions[] = 'OPEN_ROUTE_PICKER';
        }

        $nextActions = array_values(array_unique($nextActions));
        $nextActions = $this->orderTransportActions($nextActions, $serviceType);
        $validation['next_actions'] = $nextActions;
        $payload['validation'] = $validation;

        $actionPayloads = is_array($payload['action_payloads'] ?? null)
            ? $payload['action_payloads']
            : [];

        if (in_array('OPEN_ADDRESSES', $nextActions, true)) {
            $actionPayloads['OPEN_ADDRESSES'] = [
                'label' => 'Isi Alamat Saya',
            ];
        }

        if (in_array('OPEN_ROUTE_PICKER', $nextActions, true)) {
            if ($serviceType === 'antar_jemput') {
                $ride = is_array($payload['ride'] ?? null) ? $payload['ride'] : [];
                $actionPayloads['OPEN_ROUTE_PICKER'] = [
                    'service_type' => 'antar_jemput',
                    'label' => 'Atur Titik Jemput & Tujuan',
                    'points' => [
                        'pickup' => [
                            'target' => 'pickup',
                            'label' => 'Titik Jemput',
                            'initial_latitude' => $ride['pickup_latitude'] ?? null,
                            'initial_longitude' => $ride['pickup_longitude'] ?? null,
                            'address' => $ride['pickup_address'] ?? null,
                            'formatted_address' => $ride['pickup_address'] ?? null,
                        ],
                        'destination' => [
                            'target' => 'destination',
                            'label' => 'Titik Tujuan',
                            'initial_latitude' => $ride['destination_latitude'] ?? null,
                            'initial_longitude' => $ride['destination_longitude'] ?? null,
                            'address' => $ride['destination_address'] ?? null,
                            'formatted_address' => $ride['destination_address'] ?? null,
                        ],
                    ],
                ];
            } elseif ($serviceType === 'kurir') {
                $courier = is_array($payload['courier'] ?? null) ? $payload['courier'] : [];
                $actionPayloads['OPEN_ROUTE_PICKER'] = [
                    'service_type' => 'kurir',
                    'label' => 'Atur Titik Ambil & Tujuan',
                    'points' => [
                        'pickup' => [
                            'target' => 'pickup',
                            'label' => 'Titik Ambil',
                            'initial_latitude' => $courier['pickup_latitude'] ?? null,
                            'initial_longitude' => $courier['pickup_longitude'] ?? null,
                            'address' => $courier['pickup_address'] ?? null,
                            'formatted_address' => $courier['pickup_address'] ?? null,
                        ],
                        'dropoff' => [
                            'target' => 'dropoff',
                            'label' => 'Titik Tujuan',
                            'initial_latitude' => $courier['dropoff_latitude'] ?? null,
                            'initial_longitude' => $courier['dropoff_longitude'] ?? null,
                            'address' => $courier['dropoff_address'] ?? null,
                            'formatted_address' => $courier['dropoff_address'] ?? null,
                        ],
                    ],
                ];
            }
        }

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
                'OPEN_ROUTE_PICKER',
                'OPEN_MAP_PICKER_PICKUP',
                'OPEN_MAP_PICKER_DESTINATION',
                'SET_PAYMENT_COD',
                'SET_PAYMENT_TRANSFER',
                'CONFIRM_DRAFT',
                'RESET_DESTINATION',
                'CHANGE_PICKUP',
            ],
            'kurir' => [
                'OPEN_ADDRESSES',
                'OPEN_ROUTE_PICKER',
                'OPEN_MAP_PICKER_PICKUP',
                'OPEN_MAP_PICKER_DROPOFF',
                'SET_PAYMENT_COD',
                'SET_PAYMENT_TRANSFER',
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

}
