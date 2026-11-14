<?php

use App\Http\Controllers\Api\Admin\AppearanceController;
use App\Http\Controllers\Api\Admin\B2bAdminController;
use App\Http\Controllers\Api\Admin\CatalogAdminController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\DispatchController as AdminDispatchController;
use App\Http\Controllers\Api\Admin\KycReviewController;
use App\Http\Controllers\Api\Admin\OrderAdminController;
use App\Http\Controllers\Api\Admin\TradespersonAdminController;
use App\Http\Controllers\Api\Admin\ZoneAdminController;
use App\Http\Controllers\Api\Admin\WalletAdminController;
use App\Http\Controllers\Api\Admin\WebhookEndpointController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\B2bRequestController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\EstimateController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TradespersonController;
use App\Http\Controllers\Api\ZoneController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ---- Public catalog & estimator (no auth; feed the pre-registration flow) ----
    Route::get('catalog', [CatalogController::class, 'index']);
    Route::get('catalog/categories/{category:slug}', [CatalogController::class, 'category']);
    Route::get('catalog/services/{service:slug}', [CatalogController::class, 'service']);
    Route::get('governorates', [CatalogController::class, 'governorates']);
    Route::get('tradespeople', [TradespersonController::class, 'index']);
    Route::get('tradespeople/{profile}', [TradespersonController::class, 'show']);

    // ---- Launch zones + expansion waitlist ----
    Route::get('zones', [ZoneController::class, 'index']);
    Route::post('waitlist', [ZoneController::class, 'joinWaitlist'])->middleware('throttle:waitlist');

    // ---- B2B maintenance-contract quote requests ----
    Route::post('b2b-requests', [B2bRequestController::class, 'store'])->middleware('throttle:b2b');

    Route::post('estimate', [EstimateController::class, 'store'])->middleware('throttle:booking');

    // ---- Passwordless auth ----
    Route::prefix('auth')->group(function () {
        Route::post('otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request');
        Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-request');
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
        Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    });

    // ---- Booking: create as guest OR authenticated ----
    Route::post('bookings', [BookingController::class, 'store'])->middleware('throttle:booking');
    Route::post('bookings/{order:reference}/claim', [BookingController::class, 'claim'])->middleware('auth:sanctum');
    Route::get('bookings/{order:reference}/dispatch-link', [BookingController::class, 'dispatchLink'])->middleware('throttle:track');

    // ---- Authenticated customer / tradesperson area ----
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order:reference}', [OrderController::class, 'show']);
        Route::post('orders/{order:reference}/cancel', [OrderController::class, 'cancel']);
        Route::post('orders/{order:reference}/review', [OrderController::class, 'review']);

        Route::get('profile', [ProfileController::class, 'show']);
        Route::patch('profile', [ProfileController::class, 'update']);
        Route::apiResource('addresses', \App\Http\Controllers\Api\AddressController::class)->except('show');

        // Tradesperson self-service
        Route::prefix('trade')->group(function () {
            Route::post('register', [TradespersonController::class, 'register']);
            Route::get('me', [TradespersonController::class, 'me']);
            Route::patch('me', [TradespersonController::class, 'update']);
            Route::post('kyc/documents', [TradespersonController::class, 'uploadKyc']);
            Route::post('kyc/submit', [TradespersonController::class, 'submitKyc']);
            Route::get('wallet', [TradespersonController::class, 'wallet']);
            Route::get('wallet/transactions', [TradespersonController::class, 'walletTransactions']);
            Route::post('wallet/topups', [TradespersonController::class, 'requestTopup']);

            Route::middleware('verified.trade')->group(function () {
                Route::get('jobs', [TradespersonController::class, 'availableJobs']);
                Route::post('orders/{order:reference}/accept', [TradespersonController::class, 'acceptOrder']);
                Route::post('orders/{order:reference}/status', [TradespersonController::class, 'updateOrderStatus']);
            });
        });
    });

    // ---- Enterprise admin API (dispatcher + admin) ----
    Route::prefix('admin')->middleware(['auth:sanctum', 'admin:dispatcher', 'throttle:admin'])->group(function () {
        Route::get('dashboard/metrics', [DashboardController::class, 'metrics']);
        Route::get('dashboard/live', [DashboardController::class, 'live']);

        Route::get('orders', [OrderAdminController::class, 'index']);
        Route::get('orders/{order:reference}', [OrderAdminController::class, 'show']);
        Route::post('orders/{order:reference}/assign', [OrderAdminController::class, 'assign']);
        Route::post('orders/{order:reference}/transition', [OrderAdminController::class, 'transition']);
        Route::post('orders/{order:reference}/note', [OrderAdminController::class, 'note']);

        Route::post('orders/{order:reference}/dispatch', [AdminDispatchController::class, 'dispatch']);
        Route::get('dispatch/board', [AdminDispatchController::class, 'board']);
        Route::get('dispatch/{job}/payload', [AdminDispatchController::class, 'payload']);
        Route::get('dispatch/{job}/alternates', [AdminDispatchController::class, 'alternates']);
        Route::post('dispatch/{job}/nudge', [AdminDispatchController::class, 'nudge']);

        // B2B maintenance-contract leads
        Route::get('b2b-requests', [B2bAdminController::class, 'index']);
        Route::patch('b2b-requests/{b2bRequest}', [B2bAdminController::class, 'update']);

        // KYC pipeline
        Route::get('kyc/queue', [KycReviewController::class, 'queue']);
        Route::get('kyc/{profile}', [KycReviewController::class, 'show']);
        Route::get('kyc/documents/{document}/file', [KycReviewController::class, 'file']);
        Route::post('kyc/documents/{document}/review', [KycReviewController::class, 'reviewDocument']);
        Route::post('kyc/{profile}/decide', [KycReviewController::class, 'decide']);

        // Dual-wallet management
        Route::get('wallets', [WalletAdminController::class, 'index']);
        Route::get('wallets/{wallet}', [WalletAdminController::class, 'show']);
        Route::get('wallets/{wallet}/transactions', [WalletAdminController::class, 'transactions']);
        Route::post('wallets/{wallet}/adjust', [WalletAdminController::class, 'adjust']);
        Route::post('wallets/{wallet}/freeze', [WalletAdminController::class, 'freeze']);
        Route::get('topups', [WalletAdminController::class, 'topups']);
        Route::post('topups/{topup}/confirm', [WalletAdminController::class, 'confirmTopup']);
        Route::post('topups/{topup}/reject', [WalletAdminController::class, 'rejectTopup']);
        Route::get('commissions', [WalletAdminController::class, 'commissions']);

        // Catalogue: staff read; only admins change categories, services and prices.
        Route::get('catalog', [CatalogAdminController::class, 'index']);
        Route::middleware('admin')->prefix('catalog')->group(function () {
            Route::post('categories', [CatalogAdminController::class, 'storeCategory']);
            Route::patch('categories/{category}', [CatalogAdminController::class, 'updateCategory']);
            Route::delete('categories/{category}', [CatalogAdminController::class, 'destroyCategory']);
            Route::post('services', [CatalogAdminController::class, 'storeService']);
            Route::patch('services/{service}', [CatalogAdminController::class, 'updateService']);
            Route::delete('services/{service}', [CatalogAdminController::class, 'destroyService']);
        });

        // Tradespeople + readiness, launch zones, waitlist
        Route::get('tradespeople', [TradespersonAdminController::class, 'index']);
        Route::get('zones', [ZoneAdminController::class, 'index']);
        Route::get('zones/waitlist', [ZoneAdminController::class, 'waitlist']);
        Route::middleware('admin')->group(function () {
            Route::patch('zones/governorates/{governorate}', [ZoneAdminController::class, 'updateGovernorate']);
            Route::post('zones/districts', [ZoneAdminController::class, 'storeDistrict']);
            Route::patch('zones/districts/{district}', [ZoneAdminController::class, 'updateDistrict']);
            Route::delete('zones/districts/{district}', [ZoneAdminController::class, 'destroyDistrict']);
        });

        // Integration suite
        Route::middleware('admin')->group(function () {
            // Storefront appearance: announcement bar, home banners, featured services.
            Route::get('appearance', [AppearanceController::class, 'show']);
            Route::put('appearance', [AppearanceController::class, 'update']);

            Route::apiResource('webhooks', WebhookEndpointController::class);
            Route::post('webhooks/{webhook}/test', [WebhookEndpointController::class, 'test']);
            Route::get('webhooks/{webhook}/deliveries', [WebhookEndpointController::class, 'deliveries']);
            Route::post('deliveries/{delivery}/retry', [WebhookEndpointController::class, 'retry']);
            Route::get('inbound-events', [WebhookEndpointController::class, 'inbound']);
        });
    });
});
