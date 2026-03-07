<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatbotController extends Controller
{
    public function processChat(Request $request)
    {
        // Validasi request
        $request->validate([
            'message' => 'required|string',
        ]);

        $apiKey = env('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";

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

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            if ($response->failed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Gagal terhubung ke Gemini API.'
                ], 500);
            }

            $result = $response->json();
            $textResponse = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            $decodedResponse = json_decode($textResponse, true);

            return response()->json([
                'status' => 'success',
                'data' => $decodedResponse
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
            ], 500);
        }
    }
}
