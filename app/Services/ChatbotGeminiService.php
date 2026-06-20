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
    public function parseFoodOrder(string $message, ?array $context = null): array
    {
        $systemInstruction = 'Kamu adalah NLU assistant BangDeliv untuk layanan Nitip. Keluarkan hanya JSON sesuai schema. intent valid: "shopping_order" atau "out_of_domain". command valid: "confirm" atau "none"; gunakan confirm hanya untuk pesan konfirmasi singkat seperti "konfirmasi", "confirm", atau "lanjut". Ekstrak merchant/resto/toko, item belanja, jumlah, catatan, dan alamat antar jika disebut. Item dari warung/alfamart/restoran boleh berupa barang umum atau nama makanan. Jangan menentukan item berat; berat akan dikonfirmasi driver. Jika disediakan CONTEXT_JSON, gunakan untuk menjaga kesinambungan draft. Dilarang merespon teks biasa.';

        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'intent' => ['type' => 'STRING'],
                'command' => ['type' => 'STRING'],
                'merchant' => ['type' => 'STRING', 'nullable' => true],
                'resto' => ['type' => 'STRING', 'nullable' => true],
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
                            'notes' => ['type' => 'STRING', 'nullable' => true],
                        ],
                        'required' => ['name', 'quantity'],
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
            'delivery_address' => null,
            'items' => [],
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
            $systemInstruction = 'Kamu adalah NLU assistant BangDeliv untuk layanan Kurir motor. Keluarkan hanya JSON sesuai schema. command valid: "confirm", "reset_destination", atau "none". Gunakan command "confirm" hanya jika pesan user adalah konfirmasi singkat seperti "konfirmasi", "confirm", atau "lanjut"; pesan yang berisi isi paket/lokasi tidak boleh menjadi confirm. Ekstrak pickup, tujuan, isi paket, berat, ukuran, dan packing hanya jika user menyebutnya. Frasa seperti "isi paket kunci", "paketnya kunci", "kunci", "sabun", "isi paket sabun", dan "kirim kunci" harus mengisi package_description jika konteksnya sedang melengkapi isi paket. Jangan mengarang berat/ukuran; untuk barang kecil umum seperti kacamata, dokumen, kunci, sabun, buku kecil, baju, charger, atau earphone cukup isi package_description. Jika user menulis nama tempat + area, contoh "antar kacamata ke Erha Setiabudi Tembalang", isi dropoff_address dengan "Erha Setiabudi Tembalang" dan package_description dengan "kacamata". Jika user menyebut rumahku/rumah saya sebagai pickup, isi pickup_address "rumah". Barang ambigu tetap diekstrak apa adanya agar backend bisa meminta klarifikasi. intent harus "courier_order" atau "out_of_domain". Jika disediakan CONTEXT_JSON, gunakan untuk membaca progres percakapan dan draft terakhir.';
            $schema = [
                'type' => 'OBJECT',
                'properties' => [
                    'intent' => ['type' => 'STRING'],
                    'command' => ['type' => 'STRING'],
                    'pickup_address' => ['type' => 'STRING', 'nullable' => true],
                    'dropoff_address' => ['type' => 'STRING', 'nullable' => true],
                    'package_description' => ['type' => 'STRING', 'nullable' => true],
                    'estimated_weight_kg' => ['type' => 'NUMBER', 'nullable' => true],
                    'package_length_cm' => ['type' => 'INTEGER', 'nullable' => true],
                    'package_width_cm' => ['type' => 'INTEGER', 'nullable' => true],
                    'package_height_cm' => ['type' => 'INTEGER', 'nullable' => true],
                    'packing_note' => ['type' => 'STRING', 'nullable' => true],
                ],
                'required' => ['intent', 'command'],
            ];

            $parsed = $this->generateJson($message, $systemInstruction, $schema, [
                'intent' => 'courier_order',
                'command' => 'none',
                'pickup_address' => null,
                'dropoff_address' => null,
                'package_description' => null,
                'estimated_weight_kg' => null,
                'package_length_cm' => null,
                'package_width_cm' => null,
                'package_height_cm' => null,
                'packing_note' => null,
            ], $context);

            return [
                'payload' => $this->normalizeCourierPayload($parsed['payload']),
                'model_used' => $parsed['model_used'],
            ];
        }

        $systemInstruction = 'Kamu adalah NLU assistant BangDeliv untuk layanan Antar Jemput. Keluarkan hanya JSON sesuai schema. command valid: "confirm", "reset_destination", atau "none". Jika user memberi tujuan dengan pola seperti "antar ke Ramayana Salatiga" atau "saya mau ke Alun-Alun Salatiga", isi destination_address. intent harus "ride_order" atau "out_of_domain". Jika disediakan CONTEXT_JSON, gunakan untuk membaca progres percakapan dan draft terakhir.';
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
        $apiKey = (string) config('bangdeliv.chatbot.gemini.api_key', env('GEMINI_API_KEY', ''));
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
            if (is_string($encodedContext) && $encodedContext !== '') {
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

        $items = [];
        if (is_array($payload['items'] ?? null)) {
            foreach ($payload['items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $name = trim((string) ($item['name'] ?? $item['menu'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $quantity = max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1));
                $items[] = [
                    'name' => $name,
                    'menu' => $name,
                    'quantity' => $quantity,
                    'qty' => $quantity,
                    'notes' => $this->normalizeOptionalString($item['notes'] ?? null),
                ];
            }
        }

        return [
            'intent' => $intent,
            'command' => $this->normalizeCommand($payload['command'] ?? null),
            'merchant' => $merchant,
            'resto' => $merchant,
            'delivery_address' => $this->normalizeOptionalString($payload['delivery_address'] ?? null),
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
            'estimated_weight_kg' => $this->normalizeOptionalFloat($payload['estimated_weight_kg'] ?? null),
            'package_length_cm' => $this->normalizeOptionalInt($payload['package_length_cm'] ?? null),
            'package_width_cm' => $this->normalizeOptionalInt($payload['package_width_cm'] ?? null),
            'package_height_cm' => $this->normalizeOptionalInt($payload['package_height_cm'] ?? null),
            'packing_note' => $this->normalizeOptionalString($payload['packing_note'] ?? null),
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
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeOptionalFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $parsed = round((float) $value, 2);

        return $parsed > 0 ? $parsed : null;
    }

    private function normalizeOptionalInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $parsed = (int) round((float) $value);

        return $parsed > 0 ? $parsed : null;
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
