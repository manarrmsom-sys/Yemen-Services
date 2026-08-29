<?php

use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

// Public PWA shell — all rendered through Inertia.
Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/services/{category:slug}', [PageController::class, 'category'])->name('services.category');
Route::get('/book/{service:slug?}', [PageController::class, 'book'])->name('book');
Route::get('/track/{reference?}', [PageController::class, 'track'])->name('track');
Route::get('/pros', [PageController::class, 'pros'])->name('pros');
Route::get('/join', [PageController::class, 'join'])->name('join');       // tradesperson onboarding
Route::get('/offline', fn () => inertia('Offline'))->name('offline');
Route::get('/login', [PageController::class, 'login'])->name('login');

// Authenticated account area (customer + tradesperson dashboards).
Route::middleware('auth')->group(function () {
    Route::get('/account', [PageController::class, 'account'])->name('account');
    Route::get('/account/orders/{reference}', [PageController::class, 'accountOrder'])->name('account.order');
    Route::get('/trade', [PageController::class, 'tradeDashboard'])->name('trade.dashboard');
});

// Enterprise admin dashboard (Inertia SPA; data via /api/v1/admin/*).
Route::middleware(['auth', 'admin:dispatcher'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [PageController::class, 'adminHome'])->name('home');
    Route::get('/orders', fn () => inertia('Admin/Orders'))->name('orders');
    Route::get('/dispatch', fn () => inertia('Admin/Dispatch'))->name('dispatch');
    Route::get('/kyc', fn () => inertia('Admin/Kyc'))->name('kyc');
    Route::get('/wallets', fn () => inertia('Admin/Wallets'))->name('wallets');
    Route::get('/integrations', fn () => inertia('Admin/Integrations'))->middleware('admin')->name('integrations');
});

require __DIR__.'/auth.php';
