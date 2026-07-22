<?php

namespace App\Services\Chatbot;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotGeminiService
{
    /**
     * @return array{payload: array<string, mixed>, model_used: string|null}
     */
    public function parseFoodOrder(string $message, ?array $context = null): array
    {
        $systemInstruction = ChatbotPromptLibrary::foodOrderInstruction();

        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'intent' => ['type' => 'STRING'],
                'command' => ['type' => 'STRING'],
                'merchant' => ['type' => 'STRING', 'nullable' => true],
                'resto' => ['type' => 'STRING', 'nullable' => true],
                'menu_search' => ['type' => 'STRING', 'nullable' => true],
                'delivery_address' => ['type' => 'STRING', 'nullable' => true],
                'items' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'name' => ['type' => 'STRING'],
                            'menu' => ['type' => 'STRING'],
                            'quantity' => ['type' => 'INTEGER'],
                            'qty' => ['type' => 'INTEGER'],
                            'operation' => ['type' => 'STRING', 'nullable' => true],
                            'notes' => ['type' => 'STRING', 'nullable' => true],
                        ],
                        'required' => ['name', 'quantity'],
                    ],
                ],
                'stops' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'merchant' => ['type' => 'STRING', 'nullable' => true],
                            'resto' => ['type' => 'STRING', 'nullable' => true],
                            'items' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'name' => ['type' => 'STRING'],
                                        'menu' => ['type' => 'STRING'],
                                        'quantity' => ['type' => 'INTEGER'],
                                        'qty' => ['type' => 'INTEGER'],
                                        'operation' => ['type' => 'STRING', 'nullable' => true],
                                        'notes' => ['type' => 'STRING', 'nullable' => true],
                                    ],
                                    'required' => ['name', 'quantity'],
                                ],
                            ],
                        ],
                        'required' => ['items'],
                    ],
                ],
            ],
            'required' => ['intent', 'command', 'items'],
        ];

        $parsed = $this->generateJson($message, $systemInstruction, $schema, [
            'intent' => 'out_of_domain',
            'command' => 'none',
            'merchant' => null,
            'resto' => null,
            'menu_search' => null,
            'delivery_address' => null,
            'items' => [],
            'stops' => [],
        ], $context);

        return [
            'payload' => $this->normalizeFoodPayload($parsed['payload']),
            'model_used' => $parsed['model_used'],
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, model_used: string|null}
     */
    public function interpretTransportMessage(string $serviceType, string $message, ?array $context = null): array
    {
        if ($serviceType !== 'kurir' && $serviceType !== 'antar_jemput') {
            throw new ApiException('Service type tidak didukung untuk interpretasi transport.', 422);
        }

        if ($serviceType === 'kurir') {
            $systemInstruction = ChatbotPromptLibrary::courierInstruction();
            $schema = [
                'type' => 'OBJECT',
                'properties' => [
                    'intent' => ['type' => 'STRING'],
                    'command' => ['type' => 'STRING'],
                    'pickup_address' => ['type' => 'STRING', 'nullable' => true],
                    'dropoff_address' => ['type' => 'STRING', 'nullable' => true],
                    'package_description' => ['type' => 'STRING', 'nullable' => true],
                    'payment_method' => ['type' => 'STRING', 'nullable' => true],
                ],
                'required' => ['intent', 'command'],
            ];

            $parsed = $this->generateJson($message, $systemInstruction, $schema, [
                'intent' => 'courier_order',
                'command' => 'none',
                'pickup_address' => null,
                'dropoff_address' => null,
                'package_description' => null,
                'payment_method' => null,
            ], $context);

            return [
                'payload' => $this->normalizeCourierPayload($parsed['payload']),
                'model_used' => $parsed['model_used'],
            ];
        }

        $systemInstruction = ChatbotPromptLibrary::rideInstruction();
        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'intent' => ['type' => 'STRING'],
                'command' => ['type' => 'STRING'],
                'destination_address' => ['type' => 'STRING', 'nullable' => true],
                'notes' => ['type' => 'STRING', 'nullable' => true],
            ],
            'required' => ['intent', 'command'],
        ];

        $parsed = $this->generateJson($message, $systemInstruction, $schema, [
            'intent' => 'ride_order',
            'command' => 'none',
            'destination_address' => null,
            'notes' => null,
        ], $context);

        return [
            'payload' => $this->normalizeRidePayload($parsed['payload']),
            'model_used' => $parsed['model_used'],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $fallback
     * @return array{payload: array<string, mixed>, model_used: string|null}
     */
    private function generateJson(string $message, string $systemInstruction, array $schema, array $fallback, ?array $context = null): array
    {
        $apiKey = (string) config('bangdeliv.chatbot.gemini.api_key', '');
        $timeout = (int) config('bangdeliv.chatbot.gemini.timeout_seconds', 12);
        $models = config('bangdeliv.chatbot.gemini.models', [
            'gemini-3.1-flash-lite',
            'gemini-2.5-flash-lite',
            'gemini-2.5-flash',
            'gemini-3-flash-preview',
        ]);

        if (! is_array($models) || $models === []) {
            $models = ['gemini-2.5-flash'];
        }

        $userParts = [
            ['text' => $message],
        ];

        if (is_array($context) && $context !== []) {
            $encodedContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encodedContext)) {
                $userParts[] = ['text' => 'CONTEXT_JSON: '.$encodedContext];
            }
        }

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $userParts,
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];

        $lastError = null;

        foreach ($models as $model) {
            if (! is_string($model) || trim($model) === '') {
                continue;
            }

            $resolvedModel = trim($model);
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.$resolvedModel.':generateContent?key='.$apiKey;

            try {
                $response = Http::timeout($timeout)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                    ])
                    ->post($url, $payload);

                if ($response->successful()) {
                    $result = $response->json();
                    $textResponse = (string) data_get($result, 'candidates.0.content.parts.0.text', '{}');
                    $decodedResponse = json_decode($textResponse, true);

                    if (! is_array($decodedResponse)) {
                        $decodedResponse = $fallback;
                    }

                    return [
                        'payload' => $decodedResponse,
                        'model_used' => $resolvedModel,
                    ];
                }

                if ($response->status() === 429) {
                    Log::warning('Gemini API rate limit hit for model '.$resolvedModel);

                    continue;
                }

                $lastError = 'API Error '.$response->status().': '.$response->body();
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
                Log::error('Gemini API exception for model '.$resolvedModel.': '.$exception->getMessage());

                continue;
            }
        }

        throw new ApiException(
            'Layanan AI sedang sibuk atau melampaui batas kuota harian. Silakan coba beberapa saat lagi.',
            503,
            [
                'debug_error' => config('app.debug') ? $lastError : null,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeFoodPayload(array $payload): array
    {
        $intent = strtolower(trim((string) ($payload['intent'] ?? 'out_of_domain')));
        if ($intent === 'pesan_makanan') {
            $intent = 'shopping_order';
        }
        if ($intent !== 'shopping_order') {
            $intent = 'out_of_domain';
        }

        $merchant = $this->normalizeOptionalString($payload['merchant'] ?? $payload['resto'] ?? null);

        $items = ChatbotShoppingItemNormalizer::geminiItems($payload['items'] ?? []);

        $stops = [];
        if (is_array($payload['stops'] ?? null)) {
            foreach ($payload['stops'] as $stop) {
                if (! is_array($stop)) {
                    continue;
                }

                $stopMerchant = $this->normalizeOptionalString($stop['merchant'] ?? $stop['resto'] ?? null);
                $stopItems = ChatbotShoppingItemNormalizer::geminiItems($stop['items'] ?? []);

                if ($stopMerchant !== null || $stopItems !== []) {
                    $stops[] = [
                        'merchant' => $stopMerchant,
                        'resto' => $stopMerchant,
                        'items' => $stopItems,
                    ];
                }
            }
        }

        return [
            'intent' => $intent,
            'command' => $this->normalizeCommand($payload['command'] ?? null),
            'merchant' => $merchant,
            'resto' => $merchant,
            'menu_search' => $this->normalizeOptionalString($payload['menu_search'] ?? null),
            'delivery_address' => $this->normalizeOptionalString($payload['delivery_address'] ?? null),
            'items' => $items,
            'stops' => $stops,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeCourierPayload(array $payload): array
    {
        $intent = strtolower(trim((string) ($payload['intent'] ?? 'courier_order')));
        if ($intent !== 'courier_order' && $intent !== 'out_of_domain') {
            $intent = 'courier_order';
        }

        return [
            'intent' => $intent,
            'command' => $this->normalizeCommand($payload['command'] ?? null),
            'pickup_address' => $this->normalizeOptionalString($payload['pickup_address'] ?? null),
            'dropoff_address' => $this->normalizeOptionalString($payload['dropoff_address'] ?? null),
            'package_description' => $this->normalizeOptionalString($payload['package_description'] ?? null),
            'payment_method' => $this->normalizeOptionalString($payload['payment_method'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeRidePayload(array $payload): array
    {
        $intent = strtolower(trim((string) ($payload['intent'] ?? 'ride_order')));
        if ($intent !== 'ride_order' && $intent !== 'out_of_domain') {
            $intent = 'ride_order';
        }

        return [
            'intent' => $intent,
            'command' => $this->normalizeCommand($payload['command'] ?? null),
            'destination_address' => $this->normalizeOptionalString($payload['destination_address'] ?? null),
            'notes' => $this->normalizeOptionalString($payload['notes'] ?? null),
        ];
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        return ChatbotTransportSupport::normalizeOptionalString($value);
    }

    private function normalizeCommand(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'confirm', 'konfirmasi', 'lanjut' => 'confirm',
            'add_merchant', 'tambah_merchant', 'tambah merchant', 'tambah toko', 'tambah resto', 'tambah order', 'order baru' => 'add_merchant',
            'show_menu', 'lihat_menu', 'tampilkan_menu' => 'show_menu',
            'menu_next', 'menu_berikutnya', 'menu_selanjutnya' => 'menu_next',
            'menu_previous', 'menu_sebelumnya' => 'menu_previous',
            'search_menu', 'cari_menu' => 'search_menu',
            'recommend_food', 'rekomendasi_makanan', 'rekomendasi_menu' => 'recommend_food',
            'help', 'bantuan', 'panduan' => 'help',
            'reset_destination', 'ubah tujuan', 'ganti tujuan', 'reset tujuan' => 'reset_destination',
            default => 'none',
        };
    }
}
