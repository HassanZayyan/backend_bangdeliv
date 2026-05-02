<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\Admin\DriverVerificationController;
use App\Http\Controllers\Admin\RestaurantController;
use App\Http\Controllers\Admin\RestaurantMenuController;
use App\Models\Order;

Route::get('/', function () {
    return redirect()->route('admin.dashboard');
});

// Auth routes (guest only)
Route::get('/admin/login',  [AdminAuthController::class, 'showLoginForm'])->name('login');
Route::post('/admin/login', [AdminAuthController::class, 'login']);
Route::post('/admin/logout',[AdminAuthController::class, 'logout'])->name('logout');

// Admin protected routes
Route::prefix('admin')->middleware(['auth', 'role:admin'])->group(function () {

    // Dashboard
    Route::get('/dashboard', fn() => view('admin.dashboard.index'))
        ->name('admin.dashboard');

    // Orders
    Route::get('/pesanan', fn() => view('admin.orders.index'))
        ->name('admin.orders.index');
    Route::get('/pesanan/{order}', function (Order $order) {
        $order->load([
            'user',
            'driver.user',
            'restaurant',
            'serviceType',
            'statusRef',
            'items',
            'shoppingOrder',
            'courierOrder',
            'rideOrder',
            'orderLocations',
            'payments.recordedBy',
            'payments.driver.user',
            'statusHistories.statusRef',
            'statusHistories.changedBy',
            'logs.changedBy',
        ]);

        $backUrl = request()->query('back');
        if (!is_string($backUrl) || !str_starts_with($backUrl, url('/admin/pesanan'))) {
            $backUrl = route('admin.orders.index');
        }

        return view('admin.orders.show', [
            'order' => $order,
            'backUrl' => $backUrl,
        ]);
    })->name('admin.orders.show');

    // Drivers
    Route::get('/driver', fn() => view('admin.drivers.index'))
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
    Route::get('/pelanggan', fn() => view('admin.customers.index'))
        ->name('admin.customers.index');

    // Restaurants + nested Menus
    Route::prefix('restoran')->name('admin.restaurants.')->group(function () {
        Route::get('/', [RestaurantController::class, 'index'])->name('index');
        Route::get('/create', [RestaurantController::class, 'create'])->name('create');
        Route::post('/', [RestaurantController::class, 'store'])->name('store');
        Route::get('/{restaurant}/edit', [RestaurantController::class, 'edit'])->name('edit');
        Route::put('/{restaurant}', [RestaurantController::class, 'update'])->name('update');
        Route::delete('/{restaurant}', [RestaurantController::class, 'destroy'])->name('destroy');
        Route::patch('/{restaurant}/toggle-status', [RestaurantController::class, 'toggleStatus'])->name('toggle-status');

        Route::get('/{restaurant}/menus', [RestaurantMenuController::class, 'index'])->name('menus.index');
        Route::post('/{restaurant}/menus', [RestaurantMenuController::class, 'store'])->name('menus.store');
        Route::put('/{restaurant}/menus/{menu}', [RestaurantMenuController::class, 'update'])->name('menus.update');
        Route::delete('/{restaurant}/menus/{menu}', [RestaurantMenuController::class, 'destroy'])->name('menus.destroy');
    });

    // AI Monitor
    Route::get('/ai-monitor', fn() => view('admin.ai-monitor.index'))
        ->name('admin.ai-monitor');

    // Settings
    Route::get('/pengaturan', fn() => view('admin.settings.index'))
        ->name('admin.settings');

});
