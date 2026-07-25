<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Chatbot\ChatbotContextLimitService;
use App\Services\Chatbot\ChatbotCourierOrderService;
use App\Services\Chatbot\ChatbotDraftStore;
use App\Services\Chatbot\ChatbotGeminiService;
use App\Services\Chatbot\ChatbotHelpService;
use App\Services\Chatbot\ChatbotRideOrderService;
use App\Services\Chatbot\ChatbotShoppingOrderService;
use App\Services\Maps\GoogleMapsGeocodingService;
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

    public function __construct(
        private readonly ChatbotCourierOrderService $courierOrderService,
        private readonly ChatbotRideOrderService $rideOrderService,
        private readonly ChatbotShoppingOrderService $shoppingOrderService,
        private readonly ChatbotGeminiService $geminiService,
        private readonly GoogleMapsGeocodingService $geocodingService,
        private readonly ChatbotDraftStore $draftStore,
        private readonly ChatbotContextLimitService $contextLimitService,
        private readonly ChatbotHelpService $helpService,
    ) {}

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

    public function patchSessionLocation(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'service_type' => ['required', Rule::in(array_keys(self::SERVICE_TYPE_MAP))],
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

        $serviceType = (string) $validated['service_type'];
        $target = (string) $validated['target'];
        $latitude = (float) $validated['latitude'];
        $longitude = (float) $validated['longitude'];
        $rawAddress = isset($validated['address']) ? trim((string) $validated['address']) : '';
        $address = $this->resolveMapPinAddress(
            $latitude,
            $longitude,
            $rawAddress,
            includeNearbyPlace: ! ($serviceType === 'nitip' && $target === 'delivery')
        );

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

        $assistantText = trim((string) ($patchedPayload['assistant_text'] ?? 'Titik lokasi berhasil diperbarui.'));

        $this->persistChatbotDraftTurn(
            $user,
            $normalizedSessionId,
            '[MAP_PIN] '.$target.' => '.$address,
            $assistantText,
            $patchedPayload,
            $serviceType
        );

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

    public function patchSessionMerchant(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'service_type' => ['required', Rule::in(['nitip'])],
            'mode' => ['nullable', Rule::in(['select', 'add', 'replace'])],
            'replace_target' => ['nullable', 'string', 'max:100', 'required_if:mode,replace'],
            'merchant_id' => ['nullable', 'integer', 'min:1'],
            'merchant_place' => ['nullable', 'array', 'required_without:merchant_id'],
            'merchant_place.place_id' => ['nullable', 'string', 'max:255'],
            'merchant_place.name' => ['required_with:merchant_place', 'string', 'max:255'],
            'merchant_place.address' => ['required_with:merchant_place', 'string', 'max:1000'],
            'merchant_place.latitude' => ['required_with:merchant_place', 'numeric', 'between:-90,90'],
            'merchant_place.longitude' => ['required_with:merchant_place', 'numeric', 'between:-180,180'],
            'merchant_place.types' => ['nullable', 'array', 'max:12'],
            'merchant_place.types.*' => ['string', 'max:80'],
        ]);

        $user = $request->user();
        $normalizedSessionId = substr(trim($sessionId), 0, 100);
        if ($normalizedSessionId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Session ID tidak valid.',
            ], 422);
        }

        $merchantPayload = [];
        if (isset($validated['merchant_id'])) {
            $merchantPayload['merchant_id'] = (int) $validated['merchant_id'];
        }
        if (isset($validated['merchant_place']) && is_array($validated['merchant_place'])) {
            $merchantPayload['merchant_place'] = $validated['merchant_place'];
        }

        try {
            $patchedPayload = $this->shoppingOrderService->applyMerchantPatch(
                $user,
                $normalizedSessionId,
                $merchantPayload,
                (string) ($validated['mode'] ?? 'select'),
                isset($validated['replace_target']) ? (string) $validated['replace_target'] : null,
            );
        } catch (ApiException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status());
        }

        $mode = (string) ($validated['mode'] ?? 'select');
        $activeStop = collect(data_get($patchedPayload, 'shopping.stops', []))
            ->first(static fn ($stop): bool => is_array($stop) && ($stop['is_active'] ?? false) === true);
        $merchantName = trim((string) data_get(
            is_array($activeStop) ? $activeStop : $patchedPayload,
            is_array($activeStop) ? 'merchant.name' : 'shopping.merchant.name',
            ''
        ));
        $assistantText = trim((string) ($patchedPayload['assistant_text'] ?? 'Tempat Nitip berhasil diperbarui.'));

        $this->persistChatbotDraftTurn(
            $user,
            $normalizedSessionId,
            (match ($mode) {
                'add' => '[MERCHANT_PICKER] tambah tempat => ',
                'replace' => '[MERCHANT_PICKER] ganti tempat => ',
                default => '[MERCHANT_PICKER] tempat => ',
            })
                .($merchantName !== '' ? $merchantName : 'Tempat dipilih'),
            $assistantText,
            $patchedPayload,
            'nitip'
        );

        return response()->json([
            'status' => 'success',
            'session_id' => $normalizedSessionId,
            'service_context' => [
                'service_type' => 'nitip',
                'service_code' => self::SERVICE_TYPE_MAP['nitip'],
            ],
            'data' => $patchedPayload,
            'model_used' => 'merchant-picker-action',
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
                default => throw new \UnhandledMatchError,
            };
        } catch (ApiException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status());
        }

        $patchedPayload = $this->enrichTransportActionPayload($patchedPayload, $serviceType);
        $assistantText = trim((string) ($patchedPayload['assistant_text'] ?? 'Titik rute berhasil diperbarui.'));

        $routeSummary = collect($locations)
            ->map(static fn (array $location): string => $location['target'].' => '.$location['address'])
            ->implode('; ');

        $this->persistChatbotDraftTurn(
            $user,
            $normalizedSessionId,
            '[MAP_ROUTE] '.$routeSummary,
            $assistantText,
            $patchedPayload,
            $serviceType
        );

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

    private function resolveMapPinAddress(
        float $latitude,
        float $longitude,
        string $providedAddress,
        bool $includeNearbyPlace = true
    ): string {
        $normalizedAddress = trim($providedAddress);

        if ($normalizedAddress !== '' && ! $this->isPinPlaceholderAddress($normalizedAddress)) {
            return $normalizedAddress;
        }

        try {
            $resolved = $includeNearbyPlace
                ? $this->geocodingService->reverseGeocodeWithPlaceName($latitude, $longitude)
                : $this->geocodingService->reverseGeocode($latitude, $longitude);
            $formattedAddress = trim((string) ($resolved['formatted_address'] ?? ''));
            if ($formattedAddress !== '' && ! $this->isPinPlaceholderAddress($formattedAddress)) {
                return $formattedAddress;
            }
        } catch (ApiException $exception) {
            Log::warning('Reverse geocoding map pin failed.', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'message' => $exception->getMessage(),
                'status' => $exception->status(),
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

        $this->draftStore->forget($request->user(), $normalizedSessionId);

        return response()->json([
            'status' => 'success',
            'session_id' => $normalizedSessionId,
            'cleared' => true,
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
            $this->contextLimitService->ensureNotBlocked($user, $serviceType);

            $nluPayload = null;
            $modelUsed = null;
            $nluFromModel = false;
            $fastPayload = $this->detectTransportFastPayload($message, $serviceType);

            if ($fastPayload !== null) {
                $nluPayload = $fastPayload['payload'];
                $modelUsed = $fastPayload['model_used'];
            } else {
                try {
                    $modelContext = $this->draftStore->context($user, $sessionId, $serviceType);
                    $nluResult = $this->geminiService->interpretTransportMessage($serviceType, $message, $modelContext);
                    $nluPayload = $nluResult['payload'];
                    $modelUsed = $nluResult['model_used'];
                    $nluFromModel = true;
                } catch (ApiException $exception) {
                    // Fallback ke parser deterministik jika Gemini unavailable.
                    $nluPayload = null;
                    $modelUsed = null;
                }
            }

            if ($this->isHelpPayload($nluPayload, $serviceType)) {
                return $this->handleHelpMessage($user, $sessionId, $message, $serviceType, $serviceCode, $modelUsed);
            }

            if (
                $nluFromModel &&
                $this->contextLimitService->isOutOfContext($nluPayload, $this->expectedIntent($serviceType))
            ) {
                return $this->handleOutOfContextMessage($user, $sessionId, $message, $serviceType, $serviceCode, $modelUsed);
            }

            $payload = $serviceType === 'kurir'
                ? $this->courierOrderService->process($user, $message, $sessionId, $nluPayload)
                : $this->rideOrderService->process($user, $message, $sessionId, $nluPayload);

            $payload = $this->enrichTransportActionPayload($payload, $serviceType);

            $this->contextLimitService->clear($user, $serviceType);
            $this->persistChatbotDraftTurn(
                $user,
                $sessionId,
                $message,
                trim((string) ($payload['assistant_text'] ?? 'Respon chatbot berhasil diproses.')),
                $payload,
                $serviceType
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
            $this->contextLimitService->ensureNotBlocked($user, $serviceType);

            $nluPayload = null;
            $modelUsed = null;
            $nluFromModel = false;
            $fastPayload = $this->detectShoppingFastPayload($message, $user, $sessionId);

            if ($fastPayload !== null) {
                $nluPayload = $fastPayload['payload'];
                $modelUsed = $fastPayload['model_used'];
            } else {
                try {
                    $modelContext = $this->draftStore->context($user, $sessionId, $serviceType);
                    $parsed = $this->geminiService->parseFoodOrder($message, $modelContext);
                    $nluPayload = $parsed['payload'];
                    $modelUsed = $parsed['model_used'];
                    $nluFromModel = true;
                } catch (ApiException $exception) {
                    $nluPayload = null;
                    $modelUsed = null;
                }
            }

            if ($this->isHelpPayload($nluPayload, $serviceType)) {
                return $this->handleHelpMessage($user, $sessionId, $message, $serviceType, $serviceCode, $modelUsed);
            }

            if (
                $nluFromModel &&
                $this->contextLimitService->isOutOfContext($nluPayload, $this->expectedIntent($serviceType))
            ) {
                return $this->handleOutOfContextMessage($user, $sessionId, $message, $serviceType, $serviceCode, $modelUsed);
            }

            $shoppingPayload = $this->shoppingOrderService->process($user, $message, $sessionId, $nluPayload);

            $this->contextLimitService->clear($user, $serviceType);
            $this->persistChatbotDraftTurn(
                $user,
                $sessionId,
                $message,
                trim((string) ($shoppingPayload['assistant_text'] ?? 'Respon chatbot berhasil diproses.')),
                $shoppingPayload,
                $serviceType
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

    private function handleOutOfContextMessage(
        User $user,
        string $sessionId,
        string $message,
        string $serviceType,
        string $serviceCode,
        ?string $modelUsed
    ): JsonResponse {
        $this->contextLimitService->recordOutOfContext($user, $serviceType);

        $payload = $this->contextLimitService->responsePayload($serviceType);
        $assistantText = trim((string) ($payload['assistant_text'] ?? 'Pesan tidak sesuai konteks pemesanan.'));
        $this->draftStore->appendTurn($user, $sessionId, 'user', $message);
        $this->draftStore->appendTurn($user, $sessionId, 'assistant', $assistantText);

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
    }

    private function handleHelpMessage(
        User $user,
        string $sessionId,
        string $message,
        string $serviceType,
        string $serviceCode,
        ?string $modelUsed
    ): JsonResponse {
        $payload = $this->helpService->responsePayload($user, $sessionId, $serviceType);
        if ($serviceType === 'antar_jemput' || $serviceType === 'kurir') {
            $payload = $this->enrichTransportActionPayload($payload, $serviceType);
        }

        $assistantText = trim((string) ($payload['assistant_text'] ?? 'Tentu, saya bantu.'));
        $this->contextLimitService->clear($user, $serviceType);
        $this->draftStore->appendTurn($user, $sessionId, 'user', $message);
        $this->draftStore->appendTurn($user, $sessionId, 'assistant', $assistantText);

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
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistChatbotDraftTurn(
        User $user,
        string $sessionId,
        string $userMessage,
        string $assistantMessage,
        array $payload,
        string $serviceType
    ): void {
        $this->draftStore->appendTurn($user, $sessionId, 'user', $userMessage);
        $this->draftStore->appendTurn(
            $user,
            $sessionId,
            'assistant',
            $assistantMessage === '' ? 'Respon chatbot berhasil diproses.' : $assistantMessage,
            $payload
        );

        if ($this->resolveOrderId($payload) !== null) {
            $this->draftStore->forget($user, $sessionId);
            $this->contextLimitService->clear($user, $serviceType);
        }
    }

    private function expectedIntent(string $serviceType): string
    {
        return match ($serviceType) {
            'antar_jemput' => 'ride_order',
            'kurir' => 'courier_order',
            default => 'shopping_order',
        };
    }

    /**
     * @param  array<string, mixed>|null  $nluPayload
     */
    private function isHelpPayload(?array $nluPayload, string $serviceType): bool
    {
        if ($nluPayload === null || strtolower(trim((string) ($nluPayload['command'] ?? ''))) !== 'help') {
            return false;
        }

        $intent = strtolower(trim((string) ($nluPayload['intent'] ?? '')));

        return $intent === '' || $intent === $this->expectedIntent($serviceType);
    }

    /**
     * @param  array<string, mixed>  $assistantPayload
     */
    private function resolveOrderId(array $assistantPayload): ?int
    {
        $order = $assistantPayload['order'] ?? null;
        if (! is_array($order)) {
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
     * @return array{payload: array<string, mixed>, model_used: string}|null
     */
    private function detectTransportFastPayload(string $message, string $serviceType): ?array
    {
        $command = $this->detectTransportFastCommand($message);
        if ($command !== null) {
            return [
                'payload' => ['command' => $command],
                'model_used' => 'deterministic-command',
            ];
        }

        $normalized = strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $message)));
        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $normalized)));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['cod', 'cash', 'tunai'], true)) {
            return [
                'payload' => ['payment_method' => 'COD'],
                'model_used' => 'deterministic-payment',
            ];
        }

        if (in_array($normalized, ['transfer', 'tf', 'bank', 'qris', 'non tunai', 'nontunai'], true)) {
            return [
                'payload' => ['payment_method' => 'TRANSFER'],
                'model_used' => 'deterministic-payment',
            ];
        }

        if ($this->helpService->matches($message)) {
            return [
                'payload' => [
                    'intent' => $this->expectedIntent($serviceType),
                    'command' => 'help',
                ],
                'model_used' => 'deterministic-help',
            ];
        }

        return null;
    }

    /**
     * @return array{payload: array<string, mixed>, model_used: string}|null
     */
    private function detectShoppingFastPayload(string $message, User $user, string $sessionId): ?array
    {
        $normalized = strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $message)));
        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $normalized)));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, self::CONFIRM_COMMANDS, true)) {
            return [
                'payload' => ['command' => 'confirm'],
                'model_used' => 'deterministic-command',
            ];
        }

        if (preg_match('/\bmenu\s+(?:berikutnya|selanjutnya|lanjut)\b/u', $normalized) === 1) {
            return [
                'payload' => ['intent' => 'shopping_order', 'command' => 'menu_next'],
                'model_used' => 'deterministic-assistant',
            ];
        }

        if (preg_match('/\bmenu\s+(?:sebelumnya|mundur|kembali)\b/u', $normalized) === 1) {
            return [
                'payload' => ['intent' => 'shopping_order', 'command' => 'menu_previous'],
                'model_used' => 'deterministic-assistant',
            ];
        }

        if (preg_match('/\b(?:rekomendasi|rekomendasikan|sarankan|saran)\b/u', $normalized) === 1
            || preg_match('/\b(?:bingung|enaknya|baiknya|bagusnya)\s+(?:mau\s+)?(?:makan|pesan|beli|order)\s+apa\b/u', $normalized) === 1
            || preg_match('/\b(?:mau\s+)?(?:makan|pesan|beli|order)\s+apa(?:\s+(?:ya|enaknya|baiknya|bagusnya))?\b/u', $normalized) === 1
            || preg_match('/\bnitip\s+apa\s+(?:enaknya|baiknya|bagusnya)\b/u', $normalized) === 1
            || preg_match('/\b(?:enaknya|baiknya|bagusnya)\s+nitip\s+apa\b/u', $normalized) === 1) {
            return [
                'payload' => ['intent' => 'shopping_order', 'command' => 'recommend_food'],
                'model_used' => 'deterministic-assistant',
            ];
        }

        $hasQuantity = preg_match('/\b\d+\s*(?:x|pcs|porsi|buah|bungkus|gelas)?\b/u', $normalized) === 1;
        $hasExplicitMenuSearch = preg_match('/\b(?:cari|carikan)\s+menu\s+\S+/u', $normalized) === 1;
        if ($hasExplicitMenuSearch || (! $hasQuantity && (
            preg_match('/\b(?:cari|carikan)\s+\S+/u', $normalized) === 1
            || preg_match('/\b(?:ada|punya)\s+(?!menu\s+apa\b)\S+/u', $normalized) === 1
            || preg_match('/\bminuman(?:nya)?\s+apa\b/u', $normalized) === 1
        ))) {
            return [
                'payload' => ['intent' => 'shopping_order', 'command' => 'search_menu'],
                'model_used' => 'deterministic-assistant',
            ];
        }

        if (preg_match('/\b(?:tampilkan|lihat|cek|buka|bukakan)\s+(?:daftar\s+)?menu(?:nya)?\b/u', $normalized) === 1
            || preg_match('/^(?:menu|menunya)\b/u', $normalized) === 1
            || preg_match('/\bada\s+menu\s+apa\b/u', $normalized) === 1
            || preg_match('/\bmakanan(?:nya)?\s+apa\b/u', $normalized) === 1) {
            return [
                'payload' => ['intent' => 'shopping_order', 'command' => 'show_menu'],
                'model_used' => 'deterministic-assistant',
            ];
        }

        if (preg_match('/\b(?:tambah|nambah|add)\s+(?:tempat|merchant|toko|resto|restaurant|warung|minimarket|order)\b/u', $normalized) === 1
            || preg_match('/\border\s+baru\b/u', $normalized) === 1) {
            return [
                'payload' => ['command' => 'add_merchant'],
                'model_used' => 'deterministic-command',
            ];
        }

        if (in_array($normalized, ['cod', 'cash', 'tunai'], true)) {
            return [
                'payload' => ['payment_method' => 'COD'],
                'model_used' => 'deterministic-payment',
            ];
        }

        if (in_array($normalized, ['transfer', 'tf', 'bank', 'qris', 'non tunai', 'nontunai'], true)) {
            return [
                'payload' => ['payment_method' => 'TRANSFER'],
                'model_used' => 'deterministic-payment',
            ];
        }

        if ($this->helpService->matches($message)) {
            return [
                'payload' => [
                    'intent' => 'shopping_order',
                    'command' => 'help',
                    'items' => [],
                ],
                'model_used' => 'deterministic-help',
            ];
        }

        $assistantState = $this->draftStore->shoppingAssistantState($user, $sessionId);
        if (($assistantState['mode'] ?? null) === 'awaiting_restaurant') {
            $choices = is_array($assistantState['restaurant_choices'] ?? null)
                ? $assistantState['restaurant_choices']
                : [];
            $looksLikeOrdinal = preg_match('/^(?:(?:yang|resto|restoran|tempat|nomor|no)\s+)?(?:[1-3]|pertama|kesatu|kedua|ketiga)$/u', $normalized) === 1;
            $looksLikeName = collect($choices)->contains(function ($choice) use ($normalized): bool {
                if (! is_array($choice)) {
                    return false;
                }
                $name = strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) ($choice['name'] ?? ''))));
                if (str_contains($name, $normalized) || str_contains($normalized, $name)) {
                    return true;
                }

                $maxLength = max(strlen($name), strlen($normalized), 1);

                return (1 - (levenshtein($name, $normalized) / $maxLength)) >= 0.70;
            });
            if ($looksLikeOrdinal || (! $hasQuantity && $looksLikeName)) {
                return [
                    'payload' => [
                        'intent' => 'shopping_order',
                        'command' => (string) ($assistantState['pending_command'] ?? 'show_menu'),
                        'menu_search' => $assistantState['menu_search'] ?? null,
                    ],
                    'model_used' => 'deterministic-assistant',
                ];
            }
        }

        return null;
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
                $existing = is_array($actionPayloads['OPEN_ROUTE_PICKER'] ?? null)
                    ? $actionPayloads['OPEN_ROUTE_PICKER']
                    : [];
                $actionPayloads['OPEN_ROUTE_PICKER'] = [
                    'service_type' => 'antar_jemput',
                    'label' => isset($existing['label']) && trim((string) $existing['label']) !== ''
                        ? trim((string) $existing['label'])
                        : 'Atur Lokasi Jemput/Tujuan',
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
                $existing = is_array($actionPayloads['OPEN_ROUTE_PICKER'] ?? null)
                    ? $actionPayloads['OPEN_ROUTE_PICKER']
                    : [];
                $actionPayloads['OPEN_ROUTE_PICKER'] = [
                    'service_type' => 'kurir',
                    'label' => isset($existing['label']) && trim((string) $existing['label']) !== ''
                        ? trim((string) $existing['label'])
                        : 'Atur Lokasi Ambil/Tujuan',
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
                    'label' => 'Pilih Lokasi Jemput',
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
                    'label' => 'Pilih Lokasi Tujuan',
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
                    'label' => 'Pilih Lokasi Ambil',
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
                    'label' => 'Pilih Lokasi Tujuan',
                    'initial_latitude' => $courier['dropoff_latitude'] ?? null,
                    'initial_longitude' => $courier['dropoff_longitude'] ?? null,
                ], $existing);
            }
        }

        if (in_array('CONFIRM_DRAFT', $nextActions, true)) {
            $actionPayloads['CONFIRM_DRAFT'] = [
                'label' => 'Buat Pesanan',
                'message' => 'Konfirmasi',
            ];
        }

        if (in_array('RESET_DESTINATION', $nextActions, true)) {
            $actionPayloads['RESET_DESTINATION'] = [
                'label' => 'Ubah Tujuan',
                'message' => 'Ubah Tujuan',
            ];
        }

        // Optional pickup-change button (shown only on complete draft, not mandatory)
        if (in_array('CHANGE_PICKUP', $nextActions, true)) {
            if ($serviceType === 'antar_jemput') {
                $ride = is_array($payload['ride'] ?? null) ? $payload['ride'] : [];
                $actionPayloads['CHANGE_PICKUP'] = [
                    'target' => 'pickup',
                    'label' => 'Ubah Lokasi Jemput',
                    'initial_latitude' => $ride['pickup_latitude'] ?? null,
                    'initial_longitude' => $ride['pickup_longitude'] ?? null,
                ];
            } elseif ($serviceType === 'kurir') {
                $courier = is_array($payload['courier'] ?? null) ? $payload['courier'] : [];
                $actionPayloads['CHANGE_PICKUP'] = [
                    'target' => 'pickup',
                    'label' => 'Ubah Lokasi Ambil',
                    'initial_latitude' => $courier['pickup_latitude'] ?? null,
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
            if (! in_array($action, $ordered, true)) {
                $ordered[] = $action;
            }
        }

        return $ordered;
    }
}
