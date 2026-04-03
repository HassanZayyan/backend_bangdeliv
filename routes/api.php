<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\AuthController;

Route::post('/chatbot/process', [ChatbotController::class, 'processChat']);

// Public Auth Routes
Route::post('/auth/register/customer', [AuthController::class, 'registerCustomer']);
Route::post('/auth/register/driver', [AuthController::class, 'registerDriver']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected Auth Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
});
