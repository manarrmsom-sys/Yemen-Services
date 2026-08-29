<?php

use App\Http\Controllers\Webhooks\DispatchCallbackController;
use App\Http\Controllers\Webhooks\N8nInboundController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Stateless inbound webhook receivers.
 * Registered at the root ("/webhooks/*"), CSRF-exempt (see bootstrap/app.php),
 * no session, signature-verified inside each controller.
 */
Route::prefix('webhooks')->middleware(['throttle:webhooks'])->group(function () {
    Route::match(['get', 'post'], 'whatsapp', WhatsAppWebhookController::class)->name('webhooks.whatsapp');
    Route::post('n8n/{topic}', N8nInboundController::class)->name('webhooks.n8n');
    Route::match(['get', 'post'], 'dispatch/{order}/{action}', DispatchCallbackController::class)
        ->name('webhooks.dispatch.callback');
});
