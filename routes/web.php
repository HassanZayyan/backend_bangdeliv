<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AdminAuthController;

Route::get('/', function () {
    return redirect('/admin/login');
});

Route::get('/admin/login', [AdminAuthController::class, 'showLoginForm'])->name('login');
Route::post('/admin/login', [AdminAuthController::class, 'login']);

Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('logout');

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/admin/dashboard', function () {
        return view('admin.dashboard');
    })->name('admin.dashboard');

    Route::get('/admin/pesanan', function () {
        return view('admin.orders');
    })->name('admin.pesanan');

    Route::get('/admin/driver', function () {
        return view('admin.drivers');
    })->name('admin.driver');

    Route::get('/admin/pelanggan', function () {
        return view('admin.customers');
    })->name('admin.pelanggan');

    Route::get('/admin/restoran', function () {
        return view('admin.restaurants');
    })->name('admin.restoran');

    Route::get('/admin/verifikasi-driver', function () {
        return view('admin.driver-verification');
    })->name('admin.verifikasi-driver');

    Route::get('/admin/ai-monitor', function () {
        return view('admin.ai-monitor');
    })->name('admin.ai-monitor');

    Route::get('/admin/pengaturan', function () {
        return view('admin.settings');
    })->name('admin.pengaturan');
});
