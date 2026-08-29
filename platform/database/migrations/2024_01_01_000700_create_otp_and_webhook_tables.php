<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->string('code_hash');                          // hashed, never stored in clear
            $table->enum('channel', ['whatsapp', 'sms']);
            $table->enum('purpose', ['login', 'phone_change', 'order_confirm'])->default('login');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('request_ip', 45)->nullable();
            $table->string('session_token', 64)->nullable()->index();  // opaque handle returned to client
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        // Endpoints registered by ops in the admin "Integration Suite".
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 1024);
            $table->string('secret');                             // used to HMAC-sign payloads
            $table->json('events');                               // ["order.created","order.completed","kyc.verified",...]
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('disabled_at')->nullable();         // auto-disabled after repeated failures
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id')->index();
            $table->string('event_type')->index();
            $table->json('payload');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->enum('status', ['pending', 'success', 'failed', 'dead'])->default('pending')->index();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        // Inbound webhook receipts (WhatsApp status callbacks, n8n confirmations, SMS DLRs).
        Schema::create('inbound_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40)->index();                // whatsapp | n8n | sms | payment
            $table->string('external_id')->nullable();
            $table->string('signature_status', 20)->default('unverified');
            $table->json('headers')->nullable();
            $table->json('payload');
            $table->string('handler')->nullable();
            $table->enum('status', ['received', 'processed', 'ignored', 'error'])->default('received')->index();
            $table->text('note')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_events');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('otp_codes');
    }
};
