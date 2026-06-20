<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DriverController;
use App\Http\Controllers\Admin\DriverVerificationController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PaymentProofController;
use App\Http\Controllers\Admin\RestaurantController;
use App\Http\Controllers\Admin\RestaurantMenuController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\AdminAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('admin.dashboard');
});

// Auth routes (guest only)
Route::get('/admin/login', [AdminAuthController::class, 'showLoginForm'])->name('login');
Route::post('/admin/login', [AdminAuthController::class, 'login']);
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('logout');

// Admin protected routes
Route::prefix('admin')->middleware(['auth', 'role:admin'])->group(function () {

    // Dashboard
    Route::get('/dashboard', DashboardController::class)->name('admin.dashboard');

    // Orders
    Route::get('/pesanan', [AdminOrderController::class, 'index'])
        ->name('admin.orders.index');
    Route::get('/pesanan/{order}', [AdminOrderController::class, 'show'])
        ->name('admin.orders.show');
    Route::post('/pesanan/{order}/payment-proofs/{evidence}/approve', [PaymentProofController::class, 'approve'])
        ->name('admin.orders.payment-proofs.approve');
    Route::post('/pesanan/{order}/payment-proofs/{evidence}/reject', [PaymentProofController::class, 'reject'])
        ->name('admin.orders.payment-proofs.reject');

    // Notifications
    Route::get('/notifikasi/pending', [NotificationController::class, 'index'])
        ->name('admin.notifications.pending');

    // Drivers
    Route::get('/driver', [DriverController::class, 'index'])
        ->name('admin.drivers.index');
    Route::get('/driver/verifikasi', [DriverVerificationController::class, 'index'])
        ->name('admin.verification');
    Route::get('/driver/verifikasi/{driverId}', [DriverVerificationController::class, 'show'])
        ->name('admin.verification.show');
    Route::post('/driver/verifikasi/{driverId}/review', [DriverVerificationController::class, 'review'])
        ->name('admin.verification.review');
    Route::get('/driver/verifikasi/{driverId}/documents/{documentType}/preview', [DriverVerificationController::class, 'previewDocument'])
        ->name('admin.verification.documents.preview');
    Route::delete('/driver/verifikasi/{driverId}/documents/{documentType}', [DriverVerificationController::class, 'deleteDocument'])
        ->name('admin.verification.documents.destroy');

    // Customers
    Route::get('/pelanggan', [CustomerController::class, 'index'])
        ->name('admin.customers.index');
    Route::patch('/pelanggan/{customer}/blacklist', [CustomerController::class, 'toggleBlacklist'])
        ->name('admin.customers.blacklist');

    // Restaurants + nested Menus
    Route::prefix('restoran')->name('admin.restaurants.')->group(function () {
        Route::get('/', [RestaurantController::class, 'index'])->name('index');
        Route::get('/create', [RestaurantController::class, 'create'])->name('create');
        Route::post('/', [RestaurantController::class, 'store'])->name('store');
        Route::get('/{restaurant}/edit', [RestaurantController::class, 'edit'])->name('edit');
        Route::put('/{restaurant}', [RestaurantController::class, 'update'])->name('update');
        Route::delete('/{restaurant}', [RestaurantController::class, 'destroy'])->name('destroy');

        Route::get('/{restaurant}/menus', [RestaurantMenuController::class, 'index'])->name('menus.index');
        Route::post('/{restaurant}/menus', [RestaurantMenuController::class, 'store'])->name('menus.store');
        Route::put('/{restaurant}/menus/{menu}', [RestaurantMenuController::class, 'update'])->name('menus.update');
        Route::delete('/{restaurant}/menus/{menu}', [RestaurantMenuController::class, 'destroy'])->name('menus.destroy');
    });

    // Settings
    Route::get('/pengaturan', SettingsController::class)->name('admin.settings');

});
