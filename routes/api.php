<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\HomeController;

Route::post('/chatbot/process', [ChatbotController::class, 'processChat']);

// Public Auth Routes
Route::post('/auth/register/customer', [AuthController::class, 'registerCustomer']);
Route::post('/auth/register/driver', [AuthController::class, 'registerDriver']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected Auth Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::put('/user', [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'changePassword']);
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
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{orderId}', [OrderController::class, 'show']);
        Route::post('/orders/{orderId}/cancel', [OrderController::class, 'cancel']);
    });
});
