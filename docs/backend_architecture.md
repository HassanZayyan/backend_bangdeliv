# Backend Architecture

Dokumen ini menjelaskan struktur backend BangDeliv setelah service layer dirapikan menjadi folder per domain.

## Prinsip Utama

- Route publik tetap berada di `routes/api.php` dan tidak berubah karena frontend Flutter bergantung pada URL tersebut.
- Controller tetap berada di `app/Http/Controllers` dan bertugas menerima request, memanggil service, lalu mengembalikan response.
- Business logic utama berada di `app/Services/<Domain>`.
- Model Eloquent tetap berada di `app/Models` dan tidak menjadi tempat orchestration flow.
- Enum domain berada di `app/Enums` sebagai sumber kode status, service type, payment, proof, dan driver action.

## Service Domains

- `Services/Address`: manajemen alamat customer dan kesiapan alamat chatbot.
- `Services/Catalog`: katalog restoran, merchant, kategori, dan menu.
- `Services/Chatbot`: flow pemesanan chatbot untuk RIDE, COURIER, SHOPPING, validasi order, Gemini, dan policy paket.
- `Services/Device`: device token untuk notifikasi.
- `Services/Driver`: onboarding, verifikasi, payload driver order, dan realtime order driver.
- `Services/Driver/Dispatch`: pemilihan kandidat driver, jarak driver, metadata dispatch, dan titik pickup order.
- `Services/Home`: data beranda aplikasi customer.
- `Services/Maps`: integrasi Google Maps, geocoding, dan distance matrix.
- `Services/Notification`: broadcast realtime dan push notification.
- `Services/Order`: lifecycle order, ride order, chat order, payment, proof, transfer evidence, dan ETA driver ke customer.
- `Services/Pricing`: perhitungan ongkir dan biaya shopping.
- `Services/Shopping`: perhitungan rute multi-stop shopping.

## Dependency Rule

Service boleh memakai service domain lain jika memang dibutuhkan untuk orchestration, tetapi import harus eksplisit melalui namespace domain, misalnya:

```php
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Notification\OrderRealtimeBroadcaster;
```

Laravel autowiring menyelesaikan dependency lewat constructor, sehingga `AppServiceProvider` tidak perlu binding manual selama dependency berupa concrete class.

## Route Contract

Refactor folder service tidak mengubah route, middleware, method, parameter, atau bentuk JSON response. Endpoint yang dipakai Flutter dikunci oleh `Tests\Feature\Api\ApiRouteContractTest`.
