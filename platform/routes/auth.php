<?php

use App\Http\Controllers\Auth\OtpLoginController;
use Illuminate\Support\Facades\Route;

// Session-based OTP login for the web (Inertia) surface. The API surface uses
// /api/v1/auth/otp/* with Sanctum tokens instead.
Route::middleware('guest')->group(function () {
    Route::post('/auth/otp/request', [OtpLoginController::class, 'request'])
        ->middleware('throttle:otp-request')->name('otp.request');
    Route::post('/auth/otp/verify', [OtpLoginController::class, 'verify'])
        ->middleware('throttle:otp-request')->name('otp.verify');
});

Route::post('/logout', [OtpLoginController::class, 'destroy'])->middleware('auth')->name('logout');
