# BangDeliv API Contract

Dokumen ini mencatat endpoint publik yang dipakai aplikasi Flutter. Refactor backend tidak boleh mengubah URI, method, atau envelope JSON `success/message/data` tanpa migrasi terencana.

## Auth, Profile, Address

| Method | Endpoint | Route Name |
| --- | --- | --- |
| POST | `/api/auth/register/customer` | `api.auth.register-customer` |
| POST | `/api/auth/login` | `api.auth.login` |
| POST | `/api/auth/logout` | `api.auth.logout` |
| GET | `/api/user` | `api.user.show` |
| PUT | `/api/user` | `api.user.update` |
| PUT | `/api/user/password` | `api.user.password.update` |
| POST | `/api/user/addresses/validate` | `api.user.addresses.validate` |
| POST | `/api/user/addresses` | `api.user.addresses.store` |
| PUT | `/api/user/addresses/{addressId}` | `api.user.addresses.update` |
| DELETE | `/api/user/addresses/{addressId}` | `api.user.addresses.destroy` |

Address search/geocoding untuk alamat tersimpan dibatasi ke area layanan Salatiga dan sekitarnya. Jika Flutter mengirim latitude/longitude dari GPS atau pin map, backend tetap menyimpan koordinat payload dan tidak melakukan geocoding ulang.

## Chatbot

| Method | Endpoint | Route Name |
| --- | --- | --- |
| POST | `/api/chatbot/process` | `api.chatbot.process` |
| GET | `/api/chatbot/sessions` | `api.chatbot.sessions.index` |
| GET | `/api/chatbot/sessions/{sessionId}/history` | `api.chatbot.sessions.history` |
| POST | `/api/chatbot/sessions/{sessionId}/location` | `api.chatbot.sessions.location` |
| POST | `/api/chatbot/sessions/{sessionId}/locations` | `api.chatbot.sessions.locations` |
| DELETE | `/api/chatbot/sessions/{sessionId}` | `api.chatbot.sessions.destroy` |

## Customer Orders

| Method | Endpoint | Route Name |
| --- | --- | --- |
| GET | `/api/v1/home` | `api.v1.home` |
| GET | `/api/v1/restaurants` | `api.v1.restaurants.index` |
| GET | `/api/v1/restaurants/{restaurantIdOrSlug}/menus` | `api.v1.restaurants.menus` |
| GET | `/api/v1/orders` | `api.v1.orders.index` |
| GET | `/api/v1/orders/{orderId}` | `api.v1.orders.show` |
| POST | `/api/v1/orders/{orderId}/cancel` | `api.v1.orders.cancel` |
| POST | `/api/v1/orders/ride/validate-destination` | `api.v1.orders.ride.validate-destination` |
| POST | `/api/v1/orders/ride` | `api.v1.orders.ride.store` |
| POST | `/api/v1/orders/{orderId}/payment/transfer/evidence` | `api.v1.orders.payment.transfer.evidence` |
| POST | `/api/v1/orders/{orderId}/items/bulk` | `api.v1.orders.items.bulk-store` |
| POST | `/api/v1/orders/{orderId}/shopping-stops/{pickupLocationId}/skip` | `api.v1.orders.shopping-stops.skip` |

## Driver Orders

| Method | Endpoint | Route Name |
| --- | --- | --- |
| GET | `/api/v1/driver/availability` | `api.v1.driver.availability.show` |
| PATCH | `/api/v1/driver/availability` | `api.v1.driver.availability.update` |
| PATCH | `/api/v1/driver/location` | `api.v1.driver.location.update` |
| GET | `/api/v1/driver/orders` | `api.v1.driver.orders.index` |
| GET | `/api/v1/driver/orders/{orderId}` | `api.v1.driver.orders.show` |
| POST | `/api/v1/driver/orders/{orderId}/accept` | `api.v1.driver.orders.accept` |
| POST | `/api/v1/driver/orders/{orderId}/reject` | `api.v1.driver.orders.reject` |
| POST | `/api/v1/driver/orders/{orderId}/status-transition` | `api.v1.driver.orders.status-transition` |
| PATCH | `/api/v1/driver/orders/{orderId}/location` | `api.v1.driver.orders.location.update` |
| POST | `/api/v1/driver/orders/{orderId}/proofs` | `api.v1.driver.orders.proofs.store` |
| PATCH | `/api/v1/driver/orders/{orderId}/shopping-checkout` | `api.v1.driver.orders.shopping-checkout.update` |
| PATCH | `/api/v1/driver/orders/{orderId}/shopping-items` | `api.v1.driver.orders.shopping-items.update` |
| POST | `/api/v1/orders/{orderId}/attempt-failed` | `api.v1.driver.orders.attempt-failed` |
| POST | `/api/v1/orders/{orderId}/payment/collect-cod` | `api.v1.driver.orders.payment.collect-cod` |
| POST | `/api/v1/orders/{orderId}/payment/transfer/confirm` | `api.v1.driver.orders.payment.transfer.confirm` |

Endpoint driver payment tetap berada di path `/api/v1/orders/...` untuk menjaga kompatibilitas Flutter. Jika nanti ingin dirapikan ke `/api/v1/driver/orders/...`, buat endpoint baru dulu dan pertahankan endpoint lama selama masa transisi.

## Contract Guard

`tests/Feature/Api/ApiRouteContractTest.php` mengunci route name, HTTP method, dan URI yang dipakai Flutter. Tambahkan endpoint baru ke test ini setiap kali Flutter mulai memakai route baru.
