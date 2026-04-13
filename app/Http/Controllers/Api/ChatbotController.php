<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotOrderValidationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotController extends Controller
{
    private const SERVICE_TYPE_MAP = [
        'antar_jemput' => 'RIDE',
        'kurir' => 'COURIER',
        'nitip' => 'SHOPPING',
    ];

    public function __construct(private readonly ChatbotOrderValidationService $validator)
    {
    }

    public function processChat(Request $request)
    {
        // Validasi request
        $validated = $request->validate([
            'message' => 'required|string',
            'service_type' => ['nullable', Rule::in(array_keys(self::SERVICE_TYPE_MAP))],
        ]);

        $serviceType = (string) ($validated['service_type'] ?? 'nitip');
        $serviceCode = self::SERVICE_TYPE_MAP[$serviceType];

        $apiKey = env('GEMINI_API_KEY');

        // Daftar model sesuai prioritas fallback limit
        $models = [
            'gemini-3.1-flash-lite-preview', // 500 RPD
            'gemini-2.5-flash',              // 20 RPD
            'gemini-2.5-flash-lite',         // 20 RPD
            'gemini-3-flash-preview',        // 20 RPD
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
                        ['text' => $request->message]
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

                // Jika Too Many Requests atau Kuota Habis, loop lanjut ke model berikutnya
                if ($response->status() === 429) {
                    Log::warning("Gemini API Rate Limit Hit for model: {$model}");
                    continue;
                }

                // Error selain ratelimit disave untuk di-return jika semua opsi gagal
                $lastError = "API Error {$response->status()}: " . $response->body();

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::error("Gemini API Exception for model {$model}: " . $e->getMessage());
                // Lanjut coba model berikutnya jika exception (misal timeout)
                continue;
            }
        }

        // Jika loop selesai tapi belum return (berarti semua model ter-exhaust)
        return response()->json([
            'status' => 'error',
            'message' => 'Layanan AI sedang sibuk atau melampaui batas kuota harian. Silakan coba beberapa saat lagi.',
            'debug_error' => config('app.debug') ? $lastError : null
        ], 503);

    }
}
