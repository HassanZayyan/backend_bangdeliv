<?php

namespace App\Services\Chatbot;

use App\Models\User;
use App\Services\Address\ChatbotAddressReadinessService;

class ChatbotHelpService
{
    public function __construct(
        private readonly ChatbotDraftStore $draftStore,
        private readonly ChatbotAddressReadinessService $addressReadinessService,
    ) {}

    public function matches(string $message): bool
    {
        $normalized = $this->normalize($message);
        if ($normalized === '' || mb_strlen($normalized) > 160) {
            return false;
        }

        if (preg_match('/^(?:tolong\s+)?(?:help|bantuan|panduan|bantu(?:in)?(?:\s+dong)?)$/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(?:cara|gimana|bagaimana)(?:\s+cara)?\s+(?:pesan|pesen|pesannya|pesennya|order|ordernya|pakai|pakainya|pakenya|memakainya|menggunakan|menggunakannya)\b/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(?:gimana|bagaimana)\s+caranya\b/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(?:ini\s+)?(?:aku\s+|saya\s+)?(?:harus\s+)?(?:disuruh|suruh)\s+(?:apa|ngapain)\b/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(?:aku|saya)?\s*harus\s+(?:apa|ngapain|isi\s+apa)\b/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(?:mulai|mulainya)\s+(?:gimana|bagaimana|dari\s+mana)\b/u', $normalized) === 1) {
            return true;
        }

        return preg_match('/\b(?:apa\s+yang\s+harus\s+diisi|harus\s+isi\s+apa|ini\s+isi\s+apa)\b/u', $normalized) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function responsePayload(User $user, string $sessionId, string $serviceType): array
    {
        $latestPayload = $this->matchingLatestPayload($user, $sessionId, $serviceType);
        if ($latestPayload !== null) {
            $latestPayload['intent'] = $this->intent($serviceType);
            $latestPayload['service_type'] = $serviceType;
            $latestPayload['assistant_text'] = $this->draftHelpText($latestPayload, $serviceType);

            return $latestPayload;
        }

        $hasSavedAddress = $this->addressReadinessService->hasUsableSavedAddress($user);
        $nextActions = $hasSavedAddress
            ? $this->initialActions($serviceType)
            : ['OPEN_ADDRESSES'];

        return [
            'intent' => $this->intent($serviceType),
            'service_type' => $serviceType,
            'assistant_text' => $hasSavedAddress
                ? $this->initialHelpText($serviceType)
                : $this->missingAddressHelpText($serviceType),
            'validation' => [
                'is_valid_order' => false,
                'rejection_reasons' => [],
                'missing_fields' => $hasSavedAddress
                    ? $this->initialMissingFields($serviceType)
                    : [$this->pickupField($serviceType)],
                'next_actions' => $nextActions,
            ],
            'action_payloads' => $this->initialActionPayloads($serviceType, $hasSavedAddress),
            'order' => [
                'created' => false,
                'id' => null,
                'order_number' => null,
                'delivery_fee' => null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchingLatestPayload(User $user, string $sessionId, string $serviceType): ?array
    {
        $payload = $this->draftStore->latestPayload($user, $sessionId);
        if ($payload === null) {
            return null;
        }

        $payloadServiceType = strtolower(trim((string) ($payload['service_type'] ?? '')));
        if ($payloadServiceType !== '' && $payloadServiceType !== $serviceType) {
            return null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function draftHelpText(array $payload, string $serviceType): string
    {
        $validation = is_array($payload['validation'] ?? null)
            ? $payload['validation']
            : [];
        $missingFields = is_array($validation['missing_fields'] ?? null)
            ? $validation['missing_fields']
            : [];
        $nextActions = is_array($validation['next_actions'] ?? null)
            ? array_map(static fn (mixed $action): string => strtoupper(trim((string) $action)), $validation['next_actions'])
            : [];

        $labels = [];
        foreach ($missingFields as $field) {
            $label = $this->missingFieldLabel((string) $field, $serviceType);
            if ($label !== null) {
                $labels[] = $label;
            }
        }
        $labels = array_values(array_unique($labels));

        $text = 'Tentu, saya bantu. Data pesanan yang sudah kamu isi tetap tersimpan.';
        if ($labels !== []) {
            $text .= "\n\nLangkah yang masih perlu dilengkapi: ".implode(', ', $labels).'. Kamu bisa mengetiknya lewat chat atau menggunakan tombol di bawah.';
        } elseif (in_array('SET_PAYMENT_COD', $nextActions, true) || in_array('SET_PAYMENT_TRANSFER', $nextActions, true)) {
            $text .= "\n\nPilih metode pembayaran melalui tombol di bawah untuk melanjutkan.";
        } elseif (in_array('CONFIRM_DRAFT', $nextActions, true)) {
            $text .= "\n\nDraft sudah lengkap. Periksa kembali lalu ketuk tombol Buat Pesanan.";
        } elseif ($nextActions !== []) {
            $text .= "\n\nLanjutkan pesanan menggunakan tombol yang tersedia di bawah.";
        } else {
            $text .= "\n\nKetik detail yang masih diperlukan untuk melanjutkan pesanan.";
        }

        if ($serviceType === 'nitip' && $this->hasMerchantField($missingFields)) {
            $text .= "\n\nJika toko/resto belum terdaftar di BangDeliv, ketuk tombol Cari lewat Maps yang muncul untuk memilih lokasinya agar driver mendapatkan titik yang tepat.";
        }

        if ($this->hasAddressLocationField($missingFields)) {
            $text .= "\n\nUntuk lokasi yang diketik manual, pastikan tempatnya dapat ditemukan di Google Maps.";
        }

        return $text;
    }

    private function initialHelpText(string $serviceType): string
    {
        return match ($serviceType) {
            'antar_jemput' => "Tentu, saya bantu. Untuk membuat pesanan Antar Jemput, kamu bisa memilih salah satu cara:\n\n1. Ketik tujuan perjalanan, misalnya: \"Antar ke Ramayana Salatiga\".\n2. Atau ketuk tombol Atur Lokasi Jemput/Tujuan di bawah untuk memilih titik melalui peta.\n\nJika mengetik tujuan secara manual, pastikan lokasinya dapat ditemukan di Google Maps.",
            'kurir' => "Tentu, saya bantu. Untuk membuat pesanan Kurir, kirim detail dengan format:\n\nAmbil: Laundry Berkah Salatiga\nTujuan: Universitas Kristen Satya Wacana\nBarang: 1 tas laundry\n\nAtau ketuk tombol Atur Lokasi Ambil/Tujuan di bawah. Pastikan lokasi ambil dan tujuan dapat ditemukan di Google Maps.",
            default => "Tentu, saya bantu. Untuk membuat pesanan Nitip:\n\n1. Ketuk Pilih Toko/Resto, atau ketik nama toko/resto yang sudah terdaftar di BangDeliv beserta barang yang ingin dibeli.\n2. Ketuk Pilih Alamat Antar untuk menentukan tujuan pengiriman.\n\nJika toko/resto belum terdaftar di BangDeliv, ketuk tombol Cari lewat Maps yang muncul untuk memilih lokasinya agar driver mendapatkan titik yang tepat.\n\nKamu bisa menambahkan maksimal 3 toko/resto dalam satu pesanan.",
        };
    }

    private function missingAddressHelpText(string $serviceType): string
    {
        $serviceName = match ($serviceType) {
            'antar_jemput' => 'Antar Jemput',
            'kurir' => 'Kurir',
            default => 'Nitip',
        };
        $addressRole = match ($serviceType) {
            'antar_jemput' => 'alamat jemput utama',
            'kurir' => 'alamat ambil utama',
            default => 'alamat antar utama',
        };

        return "Tentu, saya bantu. Sebelum membuat pesanan {$serviceName}, ketuk tombol Isi Alamat Saya di bawah untuk menyimpan {$addressRole}. Pastikan alamat tersebut dapat ditemukan di Google Maps. Setelah alamat tersimpan, saya akan menampilkan langkah pemesanan berikutnya.";
    }

    /**
     * @return array<int, string>
     */
    private function initialActions(string $serviceType): array
    {
        return match ($serviceType) {
            'antar_jemput', 'kurir' => ['OPEN_ROUTE_PICKER'],
            default => ['OPEN_MERCHANT_PICKER', 'OPEN_MAP_PICKER_DELIVERY'],
        };
    }

    /**
     * @return array<int, string>
     */
    private function initialMissingFields(string $serviceType): array
    {
        return match ($serviceType) {
            'antar_jemput' => ['destination_address'],
            'kurir' => ['dropoff_address', 'package_description'],
            default => ['merchant', 'items', 'delivery_address'],
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function initialActionPayloads(string $serviceType, bool $hasSavedAddress): array
    {
        if (! $hasSavedAddress) {
            return [
                'OPEN_ADDRESSES' => ['label' => 'Isi Alamat Saya'],
            ];
        }

        if ($serviceType !== 'nitip') {
            return [];
        }

        return [
            'OPEN_MERCHANT_PICKER' => [
                'label' => 'Pilih Toko/Resto',
                'mode' => 'select',
            ],
            'OPEN_MAP_PICKER_DELIVERY' => [
                'target' => 'delivery',
                'label' => 'Pilih Alamat Antar',
            ],
        ];
    }

    private function pickupField(string $serviceType): string
    {
        return $serviceType === 'nitip' ? 'delivery_address' : 'pickup_address';
    }

    private function intent(string $serviceType): string
    {
        return match ($serviceType) {
            'antar_jemput' => 'ride_order',
            'kurir' => 'courier_order',
            default => 'shopping_order',
        };
    }

    private function missingFieldLabel(string $field, string $serviceType): ?string
    {
        $normalized = strtolower(trim($field));

        return match ($normalized) {
            'pickup_address' => $serviceType === 'kurir' ? 'lokasi ambil' : 'lokasi jemput',
            'destination_address', 'dropoff_address' => 'lokasi tujuan',
            'package_description' => 'barang kiriman',
            'merchant', 'merchant_location' => 'toko/resto',
            'items', 'item' => 'barang yang ingin dibeli',
            'delivery_address' => 'alamat antar',
            'payment_method' => 'metode pembayaran',
            default => null,
        };
    }

    /**
     * @param  array<int, mixed>  $missingFields
     */
    private function hasMerchantField(array $missingFields): bool
    {
        foreach ($missingFields as $field) {
            if (in_array(strtolower(trim((string) $field)), [
                'merchant',
                'merchant_location',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $missingFields
     */
    private function hasAddressLocationField(array $missingFields): bool
    {
        foreach ($missingFields as $field) {
            if (in_array(strtolower(trim((string) $field)), [
                'pickup_address',
                'destination_address',
                'dropoff_address',
                'delivery_address',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $message): string
    {
        $normalized = mb_strtolower(trim($message));
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? '';

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }
}
