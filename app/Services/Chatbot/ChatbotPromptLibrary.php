<?php

namespace App\Services\Chatbot;

/**
 * Pustaka system-instruction NLU per service type.
 *
 * Struktur: PERAN / ATURAN / KELUARAN / CONTOH. Aturan berasal dari prompt
 * paragraf lama (dimigrasi verbatim per kalimat, tanpa mengubah makna);
 * CONTOH dirender dari examplesFor() agar input->JSON gaul tetap satu
 * sumber kebenaran dan bisa diuji.
 */
final class ChatbotPromptLibrary
{
    public static function foodOrderInstruction(): string
    {
        $rules = [
            'Gunakan confirm hanya untuk pesan konfirmasi singkat seperti "konfirmasi", "confirm", atau "lanjut".',
            'Gunakan add_merchant hanya untuk pesan singkat seperti "tambah merchant", "tambah toko", "tambah resto", "tambah order", atau "order baru"; jangan jadikan kata order sebagai item.',
            'Gunakan show_menu saat pengguna meminta daftar menu.',
            'Gunakan menu_next atau menu_previous untuk navigasi halaman menu.',
            'Gunakan search_menu saat pengguna menanyakan atau mencari menu tertentu, lalu isi menu_search hanya dengan nama atau jenis menu yang dicari.',
            'Gunakan recommend_food untuk permintaan saran seperti "bingung makan apa".',
            'Gunakan help saat pengguna menanyakan cara memakai BangBot atau cara membuat pesanan, misalnya "cara pesennya gimana" atau "ini suruh ngapain".',
            'Untuk command informasional tersebut (show_menu, menu_next, menu_previous, search_menu, recommend_food, help), intent harus shopping_order dan items harus kosong.',
            'Ekstrak merchant/resto/toko, item belanja, jumlah, catatan, dan alamat antar hanya jika disebut di pesan terbaru.',
            'Jika pengguna menyebut restoran target, isi merchant/resto dengan nama tersebut; kata urutan seperti pertama atau kedua tidak perlu diubah menjadi nama.',
            'Jika CONTEXT_JSON berisi active_merchant_name atau merchant aktif, pesan terbaru yang hanya berisi daftar belanja biasanya adalah item untuk merchant aktif itu; jangan paksa nama merchant ke item, tetapi nama brand yang memang disebut sebagai menu tetap boleh menjadi bagian nama item.',
            'Jangan gabungkan item dari baris, bullet, nomor, koma, tanda plus, atau kata "dan" yang berbeda; setiap baris atau frasa berjumlah seperti "gacoan level 6 1 porsi dan udang keju 1 porsi" harus menjadi item terpisah: "gacoan level 6" quantity 1 dan "udang keju" quantity 1.',
            'Koma boleh memisahkan item berbeda, contoh "nasi goreng 1, nasi ruwet 2, kwetiau goreng 1" menjadi 3 item; jika setelah koma hanya jumlah, angka itu quantity untuk item sebelumnya, contoh "beras 1kg, 1" berarti item "beras 1kg" quantity 1.',
            'Angka level/pedas/varian dan ukuran seperti "level 6", "500ml", "1kg", "1 kg", atau "1 liter" adalah bagian nama item, bukan jumlah; angka terakhir tanpa unit setelah nama item boleh menjadi quantity, contoh "mie gacoan level 7 2" berarti item "mie gacoan level 7" quantity 2.',
            'Format jumlah seperti "3x", "3 x", "3 pcs", "3 porsi", atau "3 buah" setelah nama item juga harus menjadi quantity; nama item harus bersih tanpa frasa intent seperti "aku mau beli", "saya mau beli", "mau beli", dan tanpa ekor merchant seperti "di Rendy\'s Chicken".',
            'Frasa gaul pembuka seperti "beliin", "beliin gw", "nitipin", "gasin", atau "dong" adalah frasa intent, bukan bagian nama item.',
            'Jika satu pesan jelas menyebut beberapa merchant, isi stops berisi merchant dan item masing-masing; jika hanya satu merchant, boleh pakai field merchant/items biasa.',
            'Nama tempat setelah kata "di" atau "dari" SELALU merchant/toko/resto, terlepas huruf besar atau kecil (contoh "di baloeng gajah" sama dengan "di Baloeng Gajah"); jika berbeda dari active_merchant_name, isi merchant/stops dengan tempat itu, jangan jadikan bagian nama item.',
            'Gunakan remove_merchant saat pengguna membatalkan atau menghapus SEBUAH TEMPAT, misalnya "hapus tempat baloeng gajah", "tidak jadi pesan di X", "gak jadi di resto itu", atau "batalkan toko kedua"; isi target_merchant dengan nama tempatnya (atau target_stop dengan urutan 1-3), dan JANGAN perlakukan ini sebagai penghapusan item.',
            'Jika user menambah item, operation item adalah "add"; jika user mengurangi item dengan kata "kurangi", "kurangin", atau "kurang", operation item adalah "decrement"; jika user mengubah jumlah final dengan kata seperti "saja", "cukup", atau "jadi", operation adalah "set"; jika user menghapus/membatalkan item, operation adalah "remove".',
            'Jangan jadikan kata "kurangi", "kurangin", "tambah", atau "hapus" sebagai bagian nama item.',
            'Jangan mengembalikan ulang item lama dari CONTEXT_JSON kecuali item itu disebut lagi di pesan terbaru.',
            'Item dari warung/alfamart/restoran boleh berupa barang umum atau nama makanan.',
            'Jangan menentukan item berat; berat akan dikonfirmasi driver.',
            'Jika disediakan CONTEXT_JSON, gunakan untuk menjaga kesinambungan draft dan merchant aktif tanpa menyalin ulang semua item lama.',
        ];

        return self::compose(
            'Kamu adalah NLU assistant BangDeliv untuk layanan Nitip.',
            $rules,
            'Hanya JSON sesuai schema. intent valid: "shopping_order" atau "out_of_domain". command valid: "confirm", "add_merchant", "remove_merchant", "show_menu", "menu_next", "menu_previous", "search_menu", "recommend_food", "help", atau "none". Dilarang merespon teks biasa.',
            self::examplesFor('nitip'),
        );
    }

    public static function courierInstruction(): string
    {
        $rules = [
            'Gunakan command "confirm" hanya jika pesan user adalah konfirmasi singkat seperti "konfirmasi", "confirm", atau "lanjut"; pesan yang berisi isi paket/lokasi tidak boleh menjadi confirm.',
            'Gunakan command "help" dengan intent "courier_order" saat pengguna menanyakan cara memakai BangBot atau cara membuat pesanan, misalnya "cara pesennya gimana" atau "ini suruh ngapain".',
            'Ekstrak pickup, tujuan, isi paket, dan metode pembayaran hanya jika user menyebutnya.',
            'Frasa seperti "isi paket kunci", "paketnya kunci", "kunci", "sabun", "isi paket sabun", dan "kirim kunci" harus mengisi package_description jika konteksnya sedang melengkapi isi paket.',
            'Jika user menulis nama tempat + area, contoh "antar kacamata ke Erha Setiabudi Tembalang", isi dropoff_address dengan "Erha Setiabudi Tembalang" dan package_description dengan "kacamata".',
            'Jika user menyebut rumahku/rumah saya sebagai pickup, isi pickup_address "rumah".',
            'Barang ambigu tetap diekstrak apa adanya agar backend bisa meminta klarifikasi.',
            'Pisahkan pesan kurir menjadi tiga bagian: barang inti, imbuhan barang (merek, pemilik, atau keterangan seperti "ROG temen gue" atau "papaku yang ketinggalan"), dan lokasi.',
            'package_description harus berisi barang inti BESERTA imbuhannya, dan TIDAK BOLEH memuat kata "ke", "dari", atau nama tempat tujuan.',
            'Kata kerja santai seperti "anter", "anterin", "anterkan", "antarin", "kirimin", dan "bawain" berarti antar/kirim.',
            'Jika disediakan CONTEXT_JSON, gunakan untuk membaca progres percakapan dan draft terakhir.',
        ];

        return self::compose(
            'Kamu adalah NLU assistant BangDeliv untuk layanan Kurir motor.',
            $rules,
            'Hanya JSON sesuai schema. intent valid: "courier_order" atau "out_of_domain". command valid: "confirm", "reset_destination", "help", atau "none". Dilarang merespon teks biasa.',
            self::examplesFor('kurir'),
        );
    }

    public static function rideInstruction(): string
    {
        $rules = [
            'Gunakan command "help" dengan intent "ride_order" saat pengguna menanyakan cara memakai BangBot atau cara membuat pesanan, misalnya "cara pesennya gimana" atau "ini suruh ngapain".',
            'Jika user memberi tujuan dengan pola seperti "antar ke Ramayana Salatiga" atau "saya mau ke Alun-Alun Salatiga", isi destination_address.',
            'Kata kerja santai seperti "anter", "anterin", "gas ke", dan "otw ke" juga berarti minta diantar ke tujuan.',
            'Jika user menyebut lokasi jemput dengan pola seperti "jemput saya di <lokasi>" atau "dari <lokasi> ke <tujuan>", isi pickup_address dengan lokasi jemput tersebut. Jangan taruh lokasi jemput di notes.',
            'Sebutan jemput yang mengacu ke alamat tersimpan seperti "rumah" atau "kantor" boleh diisi apa adanya ke pickup_address.',
            'Jika disediakan CONTEXT_JSON, gunakan untuk membaca progres percakapan dan draft terakhir.',
        ];

        return self::compose(
            'Kamu adalah NLU assistant BangDeliv untuk layanan Antar Jemput.',
            $rules,
            'Hanya JSON sesuai schema. intent valid: "ride_order" atau "out_of_domain". command valid: "confirm", "reset_destination", "help", atau "none". Dilarang merespon teks biasa.',
            self::examplesFor('antar_jemput'),
        );
    }

    /**
     * @return array<int, array{input: string, output: array<string, mixed>}>
     */
    public static function examplesFor(string $serviceType): array
    {
        return match ($serviceType) {
            'nitip' => [
                [
                    'input' => 'beliin gw seblak 2 dong di Teteh',
                    'output' => [
                        'intent' => 'shopping_order',
                        'command' => 'none',
                        'merchant' => 'Teteh',
                        'items' => [['name' => 'seblak', 'quantity' => 2]],
                    ],
                ],
                [
                    'input' => 'gasin mie ayam 1',
                    'output' => [
                        'intent' => 'shopping_order',
                        'command' => 'none',
                        'merchant' => null,
                        'items' => [['name' => 'mie ayam', 'quantity' => 1]],
                    ],
                ],
                [
                    'input' => 'nasi goreng 1, nasi ruwet 2, kwetiau goreng 1',
                    'output' => [
                        'intent' => 'shopping_order',
                        'command' => 'none',
                        'merchant' => null,
                        'items' => [
                            ['name' => 'nasi goreng', 'quantity' => 1],
                            ['name' => 'nasi ruwet', 'quantity' => 2],
                            ['name' => 'kwetiau goreng', 'quantity' => 1],
                        ],
                    ],
                ],
                [
                    'input' => 'laper nih rekomendasi dong',
                    'output' => [
                        'intent' => 'shopping_order',
                        'command' => 'recommend_food',
                        'merchant' => null,
                        'items' => [],
                    ],
                ],
                [
                    'input' => 'liat menu Teteh dong',
                    'output' => [
                        'intent' => 'shopping_order',
                        'command' => 'show_menu',
                        'merchant' => 'Teteh',
                        'items' => [],
                    ],
                ],
            ],
            'kurir' => [
                [
                    'input' => 'Anterin laptop ROG temen gue ke formulatrix salatiga',
                    'output' => [
                        'intent' => 'courier_order',
                        'command' => 'none',
                        'pickup_address' => null,
                        'dropoff_address' => 'formulatrix salatiga',
                        'package_description' => 'laptop ROG temen gue',
                        'payment_method' => null,
                    ],
                ],
                [
                    'input' => 'mau anter paket produk ke formulatrix salatiga',
                    'output' => [
                        'intent' => 'courier_order',
                        'command' => 'none',
                        'pickup_address' => null,
                        'dropoff_address' => 'formulatrix salatiga',
                        'package_description' => 'paket produk',
                        'payment_method' => null,
                    ],
                ],
                [
                    'input' => 'bawain charger gue ke kosan Budi',
                    'output' => [
                        'intent' => 'courier_order',
                        'command' => 'none',
                        'pickup_address' => null,
                        'dropoff_address' => 'kosan Budi',
                        'package_description' => 'charger gue',
                        'payment_method' => null,
                    ],
                ],
                [
                    'input' => 'antar kacamata ke Erha Setiabudi Tembalang',
                    'output' => [
                        'intent' => 'courier_order',
                        'command' => 'none',
                        'pickup_address' => null,
                        'dropoff_address' => 'Erha Setiabudi Tembalang',
                        'package_description' => 'kacamata',
                        'payment_method' => null,
                    ],
                ],
            ],
            'antar_jemput' => [
                [
                    'input' => 'anterin gue ke stasiun',
                    'output' => [
                        'intent' => 'ride_order',
                        'command' => 'none',
                        'pickup_address' => null,
                        'destination_address' => 'stasiun',
                        'notes' => null,
                    ],
                ],
                [
                    'input' => 'saya mau ke Alun-Alun Salatiga',
                    'output' => [
                        'intent' => 'ride_order',
                        'command' => 'none',
                        'pickup_address' => null,
                        'destination_address' => 'Alun-Alun Salatiga',
                        'notes' => null,
                    ],
                ],
                [
                    'input' => 'Jemput saya di Kopi Kenangan Tembalang, antar ke Alun-Alun Semarang',
                    'output' => [
                        'intent' => 'ride_order',
                        'command' => 'none',
                        'pickup_address' => 'Kopi Kenangan Tembalang',
                        'destination_address' => 'Alun-Alun Semarang',
                        'notes' => null,
                    ],
                ],
                [
                    'input' => 'jemput aku di kos ya',
                    'output' => [
                        'intent' => 'ride_order',
                        'command' => 'none',
                        'pickup_address' => 'kos',
                        'destination_address' => null,
                        'notes' => null,
                    ],
                ],
            ],
            default => [],
        };
    }

    /**
     * @param  array<int, string>  $rules
     * @param  array<int, array{input: string, output: array<string, mixed>}>  $examples
     */
    private static function compose(string $role, array $rules, string $outputContract, array $examples): string
    {
        $lines = ['PERAN: '.$role, 'ATURAN:'];
        foreach ($rules as $rule) {
            $lines[] = '- '.$rule;
        }

        $lines[] = 'KELUARAN: '.$outputContract;
        $lines[] = 'CONTOH:';
        foreach ($examples as $example) {
            $lines[] = 'INPUT: "'.$example['input'].'"';
            $lines[] = 'OUTPUT: '.json_encode($example['output'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }
}
