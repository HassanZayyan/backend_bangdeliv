<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotGeminiService
{
    /**
     * @return array{payload: array<string, mixed>, model_used: string|null}
     */
    public function parseFoodOrder(string $message): array
    {
        $systemInstruction = 'Kamu adalah AI asisten BangDeliv. Ekstrak pesan menjadi JSON. Format wajib: {"intent": "pesan_makanan" atau "out_of_domain", "resto": "string/null", "items": [{"menu": "string", "qty": integer}]}. Pastikan mengekstrak setiap pesanan menu secara terpisah ke dalam array items. Dilarang merespon teks biasa.';

        $schema = [
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
                            'qty' => ['type' => 'INTEGER'],
                        ],
                        'required' => ['menu', 'qty'],
                    ],
                ],
            ],
            'required' => ['intent', 'items'],
        ];

        $parsed = $this->generateJson($message, $systemInstruction, $schema, [
            'intent' => 'out_of_domain',
            'resto' => null,
            'items' => [],
        ]);

        return [
            'payload' => $this->normalizeFoodPayload($parsed['payload']),
            'model_used' => $parsed['model_used'],
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, model_used: string|null}
     */
    public function interpretTransportMessage(string $serviceType, string $message): array
    {
        if ($serviceType !== 'kurir' && $serviceType !== 'antar_jemput') {
            throw new ApiException('Service type tidak didukung untuk interpretasi transport.', 422);
        }

        if ($serviceType === 'kurir') {
            $systemInstruction = 'Kamu adalah NLU assistant BangDeliv untuk layanan Kurir. Keluarkan hanya JSON sesuai schema. command valid: "confirm", "reset_destination", atau "none". Jika user memberi pickup/tujuan/isi paket, isi field terkait. Jika tidak ada, null. intent harus "courier_order" atau "out_of_domain".';
            $schema = [
                'type' => 'OBJECT',
                'properties' => [
                    'intent' => ['type' => 'STRING'],
                    'command' => ['type' => 'STRING'],
                    'pickup_address' => ['type' => 'STRING', 'nullable' => true],
                    'dropoff_address' => ['type' => 'STRING', 'nullable' => true],
                    'package_description' => ['type' => 'STRING', 'nullable' => true],
                ],
                'required' => ['intent', 'command'],
            ];

            $parsed = $this->generateJson($message, $systemInstruction, $schema, [
                'intent' => 'courier_order',
                'command' => 'none',
                'pickup_address' => null,
                'dropoff_address' => null,
                'package_description' => null,
            ]);

            return [
                'payload' => $this->normalizeCourierPayload($parsed['payload']),
                'model_used' => $parsed['model_used'],
            ];
        }

        $systemInstruction = 'Kamu adalah NLU assistant BangDeliv untuk layanan Antar Jemput. Keluarkan hanya JSON sesuai schema. command valid: "confirm", "reset_destination", atau "none". Jika user memberi tujuan, isi destination_address. intent harus "ride_order" atau "out_of_domain".';
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
        ]);

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
    private function generateJson(string $message, string $systemInstruction, array $schema, array $fallback): array
    {
        $apiKey = (string) config('bangdeliv.chatbot.gemini.api_key', env('GEMINI_API_KEY', ''));
        $timeout = (int) config('bangdeliv.chatbot.gemini.timeout_seconds', 12);
        $models = config('bangdeliv.chatbot.gemini.models', [
            'gemini-3.1-flash-lite-preview',
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
            'gemini-3-flash-preview',
        ]);

        if (!is_array($models) || $models === []) {
            $models = ['gemini-2.5-flash'];
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
                    'parts' => [
                        ['text' => $message],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];

        $lastError = null;

        foreach ($models as $model) {
            if (!is_string($model) || trim($model) === '') {
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

                    if (!is_array($decodedResponse)) {
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
        if ($intent !== 'pesan_makanan') {
            $intent = 'out_of_domain';
        }

        $restoRaw = $payload['resto'] ?? null;
        $resto = is_string($restoRaw) ? trim($restoRaw) : null;
        if ($resto === '') {
            $resto = null;
        }

        $items = [];
        if (is_array($payload['items'] ?? null)) {
            foreach ($payload['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $menu = trim((string) ($item['menu'] ?? ''));
                if ($menu === '') {
                    continue;
                }

                $items[] = [
                    'menu' => $menu,
                    'qty' => max(1, (int) ($item['qty'] ?? 1)),
                ];
            }
        }

        return [
            'intent' => $intent,
            'resto' => $resto,
            'items' => $items,
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
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeCommand(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'confirm', 'konfirmasi', 'lanjut' => 'confirm',
            'reset_destination', 'ubah tujuan', 'ganti tujuan', 'reset tujuan' => 'reset_destination',
            default => 'none',
        };
    }
}
