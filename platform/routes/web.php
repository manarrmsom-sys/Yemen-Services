<?php

use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

// Public PWA shell — all rendered through Inertia.
Route::get('/', [PageController::class, 'home'])->middleware('throttle:home')->name('home');
Route::get('/sitemap.xml', [PageController::class, 'sitemap'])->name('sitemap');
Route::get('/services/{category:slug}', [PageController::class, 'category'])->name('services.category');
Route::get('/book/{service:slug?}', [PageController::class, 'book'])->name('book');
// Throttled: references are short, so lookups must not be enumerable.
Route::get('/track/{reference?}', [PageController::class, 'track'])->middleware('throttle:track')->name('track');
Route::get('/pros', [PageController::class, 'pros'])->name('pros');
// Tradesperson onboarding. /join is the old address and keeps working.
Route::get('/join-technician', [PageController::class, 'joinTechnician'])->name('join');
Route::redirect('/join', '/join-technician', 301);
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
    Route::get('/categories', fn () => inertia('Admin/Categories'))->name('categories');
    Route::get('/services', fn () => inertia('Admin/Services'))->name('services');
    Route::get('/pros', fn () => inertia('Admin/Pros'))->name('pros');
    Route::get('/zones', fn () => inertia('Admin/Zones'))->name('zones');
    Route::get('/b2b', fn () => inertia('Admin/B2b'))->name('b2b');
    Route::get('/appearance', fn () => inertia('Admin/Appearance'))->middleware('admin')->name('appearance');
    Route::get('/integrations', fn () => inertia('Admin/Integrations'))->middleware('admin')->name('integrations');
});

// Local-only shortcut to preview the admin dashboard without an OTP round-trip.
// It signs in as the seeded admin with no credentials, so it must never be
// registered outside APP_ENV=local.
if (app()->environment('local')) {
    Route::get('/bypass-login', function () {
        $admin = \App\Models\User::where('role', 'admin')->orderBy('id')->first();
        abort_unless($admin, 404, 'No admin user. Run: php artisan migrate --seed');
        \Illuminate\Support\Facades\Auth::login($admin);

        return redirect('/admin');
    })->name('bypass-login');
}

require __DIR__.'/auth.php';
