<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminAuthController;

Route::get('/', function () {
    return redirect()->route('admin.dashboard');
});

// Auth routes (guest only)
Route::get('/admin/login',  [AdminAuthController::class, 'showLoginForm'])->name('login');
Route::post('/admin/login', [AdminAuthController::class, 'login']);
Route::post('/admin/logout',[AdminAuthController::class, 'logout'])->name('logout');

// Admin protected routes
Route::prefix('admin')->middleware(['auth', 'admin'])->group(function () {

    // Dashboard
    Route::get('/dashboard', fn() => view('admin.dashboard.index'))
        ->name('admin.dashboard');

    // Orders
    Route::get('/pesanan', fn() => view('admin.orders.index'))
        ->name('admin.orders.index');

    // Drivers
    Route::get('/driver', fn() => view('admin.drivers.index'))
        ->name('admin.drivers.index');
    Route::get('/driver/verifikasi', fn() => view('admin.drivers.verification.index'))
        ->name('admin.verification');

    // Customers
    Route::get('/pelanggan', fn() => view('admin.customers.index'))
        ->name('admin.customers.index');

    // Restaurants + nested Menus
    Route::get('/restoran', fn() => view('admin.restaurants.index'))
        ->name('admin.restaurants.index');

    // AI Monitor
    Route::get('/ai-monitor', fn() => view('admin.ai-monitor.index'))
        ->name('admin.ai-monitor');

    // Settings
    Route::get('/pengaturan', fn() => view('admin.settings.index'))
        ->name('admin.settings');

});
