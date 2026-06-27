<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\DriverVerificationController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\OrderChatController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\RestaurantController;
use Illuminate\Support\Facades\Route;

// Public Auth Routes
Route::post('/auth/register/customer', [AuthController::class, 'registerCustomer'])->name('api.auth.register-customer');
Route::post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');

// Protected Auth Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/chatbot/process', [ChatbotController::class, 'processChat'])->middleware('throttle:chatbot')->name('api.chatbot.process');
    Route::post('/chatbot/sessions/{sessionId}/location', [ChatbotController::class, 'patchSessionLocation'])->name('api.chatbot.sessions.location');
    Route::post('/chatbot/sessions/{sessionId}/locations', [ChatbotController::class, 'patchSessionLocations'])->name('api.chatbot.sessions.locations');
    Route::post('/chatbot/sessions/{sessionId}/merchant', [ChatbotController::class, 'patchSessionMerchant'])->name('api.chatbot.sessions.merchant');
    Route::delete('/chatbot/sessions/{sessionId}', [ChatbotController::class, 'clearSession'])->name('api.chatbot.sessions.destroy');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('/user', [AuthController::class, 'me'])->name('api.user.show');
    Route::post('/user/upgrade-to-driver', [AuthController::class, 'upgradeToDriver'])->middleware('role:customer')->name('api.user.upgrade-to-driver');
    Route::put('/user', [AuthController::class, 'updateProfile'])->name('api.user.update');
    Route::put('/user/password', [AuthController::class, 'changePassword'])->name('api.user.password.update');
    Route::post('/user/addresses/validate', [AuthController::class, 'validateAddress'])->name('api.user.addresses.validate');
    Route::post('/user/addresses', [AuthController::class, 'storeAddress'])->name('api.user.addresses.store');
    Route::put('/user/addresses/{addressId}', [AuthController::class, 'updateAddress'])->name('api.user.addresses.update');
    Route::delete('/user/addresses/{addressId}', [AuthController::class, 'deleteAddress'])->name('api.user.addresses.destroy');
});

Route::prefix('v1')->group(function () {
    Route::get('/home', [HomeController::class, 'index'])->name('api.v1.home');
    Route::get('/restaurants', [RestaurantController::class, 'index'])->name('api.v1.restaurants.index');
    Route::get('/restaurants/{restaurantIdOrSlug}', [RestaurantController::class, 'show'])->name('api.v1.restaurants.show');
    Route::get('/restaurants/{restaurantIdOrSlug}/menus', [RestaurantController::class, 'menus'])->name('api.v1.restaurants.menus');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/device-tokens', [DeviceTokenController::class, 'store'])->name('api.v1.device-tokens.store');
        Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy'])->name('api.v1.device-tokens.destroy');
        Route::get('/orders/{orderId}/chat/messages', [OrderChatController::class, 'index'])->name('api.v1.orders.chat.messages.index');
        Route::get('/orders/{orderId}/chat/unread', [OrderChatController::class, 'unread'])->name('api.v1.orders.chat.unread');
        Route::post('/orders/{orderId}/chat/read', [OrderChatController::class, 'markRead'])->name('api.v1.orders.chat.read');
        Route::post('/orders/{orderId}/chat/messages', [OrderChatController::class, 'store'])->name('api.v1.orders.chat.messages.store');
    });

    Route::middleware(['auth:sanctum', 'customer.ordering'])->group(function () {
        Route::post('/orders/ride/validate-destination', [OrderController::class, 'validateRideDestination'])->name('api.v1.orders.ride.validate-destination');
        Route::post('/orders/ride', [OrderController::class, 'createRideOrder'])->name('api.v1.orders.ride.store');
        Route::get('/orders', [OrderController::class, 'index'])->name('api.v1.orders.index');
        Route::get('/orders/{orderId}', [OrderController::class, 'show'])->name('api.v1.orders.show');
        Route::post('/orders/{orderId}/cancel', [OrderController::class, 'cancel'])->name('api.v1.orders.cancel');
        Route::patch('/orders/{orderId}/payment-method', [OrderController::class, 'updatePaymentMethod'])->name('api.v1.orders.payment-method.update');
        Route::post('/orders/{orderId}/payment/transfer/evidence', [OrderController::class, 'uploadTransferEvidence'])->name('api.v1.orders.payment.transfer.evidence');
        Route::post('/orders/{orderId}/items', [OrderController::class, 'addShoppingItem'])->name('api.v1.orders.items.store');
        Route::post('/orders/{orderId}/items/bulk', [OrderController::class, 'addShoppingItems'])->name('api.v1.orders.items.bulk-store');
        Route::patch('/orders/{orderId}/items/{itemId}', [OrderController::class, 'updateShoppingItem'])->name('api.v1.orders.items.update');
        Route::delete('/orders/{orderId}/items/{itemId}', [OrderController::class, 'removeShoppingItem'])->name('api.v1.orders.items.destroy');
        Route::post('/orders/{orderId}/shopping/item-change-request', [OrderController::class, 'requestShoppingItemChange'])->name('api.v1.orders.shopping.item-change-request.store');
        Route::post('/orders/{orderId}/shopping-stops/{pickupLocationId}/skip', [OrderController::class, 'skipFailedShoppingStop'])->name('api.v1.orders.shopping-stops.skip');
        Route::post('/orders/{orderId}/shopping/price-quote/respond', [OrderController::class, 'respondShoppingPriceQuote'])->name('api.v1.orders.shopping.price-quote.respond');
        Route::post('/orders/{orderId}/delivery-fee-override/respond', [OrderController::class, 'respondDeliveryFeeOverride'])->name('api.v1.orders.delivery-fee-override.respond');
    });

    Route::middleware(['auth:sanctum', 'role:driver'])->group(function () {
        Route::get('/driver/verification', [DriverVerificationController::class, 'myStatus'])->name('api.v1.driver.verification.show');
        Route::post('/driver/verification/documents', [DriverVerificationController::class, 'submitDocuments'])->name('api.v1.driver.verification.documents.store');
        Route::delete('/driver/verification', [DriverVerificationController::class, 'cancel'])->name('api.v1.driver.verification.cancel');

        Route::middleware('driver.active')->group(function () {
            Route::get('/driver/availability', [OrderController::class, 'driverAvailability'])->name('api.v1.driver.availability.show');
            Route::patch('/driver/availability', [OrderController::class, 'updateDriverAvailability'])->name('api.v1.driver.availability.update');
            Route::patch('/driver/location', [OrderController::class, 'updateCurrentDriverLocation'])->name('api.v1.driver.location.update');
            Route::get('/driver/history', [OrderController::class, 'driverHistory'])->name('api.v1.driver.history');
            Route::get('/driver/orders', [OrderController::class, 'driverOrders'])->name('api.v1.driver.orders.index');
            Route::get('/driver/orders/{orderId}', [OrderController::class, 'driverOrderDetail'])->name('api.v1.driver.orders.show');
            Route::post('/driver/orders/{orderId}/accept', [OrderController::class, 'acceptByDriver'])->name('api.v1.driver.orders.accept');
            Route::post('/driver/orders/{orderId}/reject', [OrderController::class, 'rejectByDriver'])->name('api.v1.driver.orders.reject');
            Route::patch('/driver/orders/{orderId}/shopping-items', [OrderController::class, 'updateDriverShoppingItems'])->name('api.v1.driver.orders.shopping-items.update');
            Route::patch('/driver/orders/{orderId}/shopping-checkout', [OrderController::class, 'updateShoppingCheckout'])->name('api.v1.driver.orders.shopping-checkout.update');
            Route::post('/driver/orders/{orderId}/shopping-stops/{pickupLocationId}/open', [OrderController::class, 'openShoppingStop'])->name('api.v1.driver.orders.shopping-stops.open');
            Route::post('/driver/orders/{orderId}/shopping/price-quote', [OrderController::class, 'submitShoppingPriceQuote'])->name('api.v1.driver.orders.shopping.price-quote.store');
            Route::post('/driver/orders/{orderId}/shopping/price-quote/bypass', [OrderController::class, 'bypassShoppingPriceQuote'])->name('api.v1.driver.orders.shopping.price-quote.bypass');
            Route::post('/driver/orders/{orderId}/shopping/price-quote/accept-counter', [OrderController::class, 'acceptShoppingCounter'])->name('api.v1.driver.orders.shopping.price-quote.accept-counter');
            Route::post('/driver/orders/{orderId}/shopping/item-change-request/respond', [OrderController::class, 'respondShoppingItemChange'])->name('api.v1.driver.orders.shopping.item-change-request.respond');
            Route::post('/driver/orders/{orderId}/delivery-fee-override', [OrderController::class, 'updateDeliveryFeeOverride'])->name('api.v1.driver.orders.delivery-fee-override');
            Route::post('/driver/orders/{orderId}/delivery-fee-override/bypass', [OrderController::class, 'bypassDeliveryFeeOverride'])->name('api.v1.driver.orders.delivery-fee-override.bypass');
            Route::post('/driver/orders/{orderId}/delivery-fee-override/accept-counter', [OrderController::class, 'acceptDeliveryFeeCounter'])->name('api.v1.driver.orders.delivery-fee-override.accept-counter');
            Route::patch('/driver/orders/{orderId}/location', [OrderController::class, 'updateDriverLocation'])->name('api.v1.driver.orders.location.update');
            Route::post('/driver/orders/{orderId}/proofs', [OrderController::class, 'uploadDriverProof'])->name('api.v1.driver.orders.proofs.store');
            Route::post('/driver/orders/{orderId}/status-transition', [OrderController::class, 'transitionStatusByDriver'])->name('api.v1.driver.orders.status-transition');
            Route::post('/orders/{orderId}/attempt-failed', [OrderController::class, 'recordFailedAttemptByDriver'])->name('api.v1.driver.orders.attempt-failed');
            Route::post('/orders/{orderId}/payment/collect-cod', [OrderController::class, 'recordCodCollectionByDriver'])->name('api.v1.driver.orders.payment.collect-cod');
            Route::post('/orders/{orderId}/payment/transfer/confirm', [OrderController::class, 'recordTransferPaymentByDriver'])->name('api.v1.driver.orders.payment.transfer.confirm');
            Route::post('/orders/{orderId}/payment/transfer/reject', [OrderController::class, 'rejectTransferPaymentByDriver'])->name('api.v1.driver.orders.payment.transfer.reject');
        });
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::get('/admin/payments/cod-settlement', [OrderController::class, 'codSettlementReport'])->name('api.v1.admin.payments.cod-settlement');

        Route::get('/admin/drivers/verification', [DriverVerificationController::class, 'adminIndex'])->name('api.v1.admin.drivers.verification.index');
        Route::get('/admin/drivers/{driverId}/verification', [DriverVerificationController::class, 'adminShow'])->name('api.v1.admin.drivers.verification.show');
        Route::post('/admin/drivers/{driverId}/verification/review', [DriverVerificationController::class, 'adminReview'])->name('api.v1.admin.drivers.verification.review');
    });
});
