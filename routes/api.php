<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\DriverVerificationController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\Driver\OrderExecutionController;

// Public Auth Routes
Route::post('/auth/register/customer', [AuthController::class, 'registerCustomer']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected Auth Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/chatbot/process', [ChatbotController::class, 'processChat'])->middleware('throttle:chatbot');
    Route::get('/chatbot/sessions', [ChatbotController::class, 'listSessions']);
    Route::get('/chatbot/sessions/{sessionId}/history', [ChatbotController::class, 'sessionHistory']);
    Route::post('/chatbot/sessions/{sessionId}/location', [ChatbotController::class, 'patchSessionLocation']);
    Route::delete('/chatbot/sessions/{sessionId}', [ChatbotController::class, 'clearSession']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/user/upgrade-to-driver', [AuthController::class, 'upgradeToDriver'])->middleware('role:customer');
    Route::put('/user', [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'changePassword']);
    Route::post('/user/addresses/validate', [AuthController::class, 'validateAddress']);
    Route::post('/user/addresses', [AuthController::class, 'storeAddress']);
    Route::put('/user/addresses/{addressId}', [AuthController::class, 'updateAddress']);
    Route::delete('/user/addresses/{addressId}', [AuthController::class, 'deleteAddress']);
});

Route::prefix('v1')->group(function () {
    Route::get('/home', [HomeController::class, 'index']);
    Route::get('/restaurants', [RestaurantController::class, 'index']);
    Route::get('/restaurants/{restaurantIdOrSlug}', [RestaurantController::class, 'show']);
    Route::get('/restaurants/{restaurantIdOrSlug}/menus', [RestaurantController::class, 'menus']);

    Route::middleware(['auth:sanctum', 'role:customer'])->group(function () {
        Route::get('/cart', [CartController::class, 'show']);
        Route::post('/cart/items', [CartController::class, 'addItem']);
        Route::patch('/cart/items/{itemId}', [CartController::class, 'updateItem']);
        Route::delete('/cart/items/{itemId}', [CartController::class, 'removeItem']);
        Route::delete('/cart', [CartController::class, 'clear']);

        Route::post('/orders/checkout', [OrderController::class, 'checkout']);
        Route::post('/orders/ride/validate-destination', [OrderController::class, 'validateRideDestination']);
        Route::post('/orders/ride', [OrderController::class, 'createRideOrder']);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{orderId}', [OrderController::class, 'show']);
        Route::post('/orders/{orderId}/cancel', [OrderController::class, 'cancel']);
        Route::post('/orders/{orderId}/items', [OrderController::class, 'addShoppingItem']);
        Route::patch('/orders/{orderId}/items/{itemId}', [OrderController::class, 'updateShoppingItem']);
        Route::delete('/orders/{orderId}/items/{itemId}', [OrderController::class, 'removeShoppingItem']);
    });

    Route::middleware(['auth:sanctum', 'role:driver'])->group(function () {
        Route::get('/driver/verification', [DriverVerificationController::class, 'myStatus']);
        Route::post('/driver/verification/documents', [DriverVerificationController::class, 'submitDocuments']);

        Route::middleware('driver.active')->group(function () {
            Route::get('/driver/availability', [OrderController::class, 'driverAvailability']);
            Route::patch('/driver/availability', [OrderController::class, 'updateDriverAvailability']);
            Route::get('/driver/history', [OrderController::class, 'driverHistory']);
            Route::get('/driver/orders', [OrderController::class, 'driverOrders']);
            Route::get('/driver/orders/{orderId}', [OrderController::class, 'driverOrderDetail']);
            Route::post('/driver/orders/{orderId}/accept', [OrderController::class, 'acceptByDriver']);
            Route::post('/driver/orders/{orderId}/reject', [OrderController::class, 'rejectByDriver']);
            Route::post('/driver/orders/{orderId}/status-transition', [OrderController::class, 'transitionStatusByDriver']);
            Route::patch('/driver/orders/{orderId}/status', [OrderExecutionController::class, 'updateStatus']);
            Route::post('/driver/orders/{orderId}/location', [OrderExecutionController::class, 'updateLocation']);
            Route::post('/orders/{orderId}/attempt-failed', [OrderController::class, 'recordFailedAttemptByDriver']);
            Route::post('/orders/{orderId}/payment/collect-cod', [OrderController::class, 'recordCodCollectionByDriver']);
        });
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::post('/admin/orders/{orderId}/attempt-failed', [OrderController::class, 'recordFailedAttemptByAdmin']);
        Route::post('/admin/orders/{orderId}/payment/record-cod', [OrderController::class, 'recordCodCollectionByAdmin']);
        Route::get('/admin/payments/cod-settlement', [OrderController::class, 'codSettlementReport']);

        Route::get('/admin/drivers/verification', [DriverVerificationController::class, 'adminIndex']);
        Route::get('/admin/drivers/{driverId}/verification', [DriverVerificationController::class, 'adminShow']);
        Route::post('/admin/drivers/{driverId}/verification/review', [DriverVerificationController::class, 'adminReview']);
    });
});
