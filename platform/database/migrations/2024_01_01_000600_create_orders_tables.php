<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 16)->unique();            // human code e.g. YS-8F3K2A
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('governorate_id')->constrained()->restrictOnDelete();
            $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tradesperson_profile_id')->nullable()->constrained()->nullOnDelete();

            // Lifecycle. draft -> pending_dispatch -> dispatched -> accepted -> en_route -> in_progress -> completed -> closed
            //            (cancelled / expired at any pre-completion stage)
            $table->enum('status', [
                'draft', 'pending_dispatch', 'dispatched', 'accepted', 'en_route',
                'in_progress', 'completed', 'closed', 'cancelled', 'expired',
            ])->default('draft')->index();

            $table->enum('channel', ['pwa', 'whatsapp', 'admin', 'phone'])->default('pwa');
            $table->enum('urgency', ['flexible', 'today', 'urgent'])->default('today');
            $table->timestamp('scheduled_for')->nullable();

            // Snapshot of the estimator output at booking time (immutable).
            $table->char('currency', 3)->default('YER');
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('callout_fee')->default(0);
            $table->bigInteger('urgent_surcharge')->default(0);
            $table->bigInteger('governorate_adjustment')->default(0);
            $table->bigInteger('vat_amount')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('total_estimate')->default(0);
            $table->bigInteger('total_final')->nullable();        // set on completion by the tradesperson/dispatcher
            $table->json('price_breakdown')->nullable();

            $table->enum('payment_method', ['cod', 'local_wallet', 'bank_transfer', 'card'])->default('cod');
            $table->enum('payment_status', ['unpaid', 'partially_paid', 'paid', 'refunded'])->default('unpaid')->index();

            // Commission snapshot.
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->bigInteger('commission_amount')->default(0);

            $table->text('customer_notes')->nullable();
            $table->json('estimator_inputs')->nullable();         // {quantity, hours, options[...]}
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_name')->nullable();

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->ipAddress('created_ip')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'governorate_id']);
            $table->index(['created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->string('name_snapshot');
            $table->enum('pricing_model', ['fixed', 'hourly', 'per_unit', 'quote']);
            $table->decimal('quantity', 8, 2)->default(1);
            $table->bigInteger('unit_price');
            $table->bigInteger('line_total');
            $table->json('options')->nullable();
            $table->timestamps();
        });

        // Append-only audit log of everything that happens to an order.
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();                      // status_changed | dispatched | note | payment | assignment
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('message')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // The "Gap Play" — each attempt to hand an order to a tradesperson via WhatsApp.
        Schema::create('dispatch_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tradesperson_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('mode', ['broadcast', 'targeted', 'manual'])->default('targeted');
            $table->enum('status', ['queued', 'sent', 'delivered', 'accepted', 'declined', 'expired', 'failed'])
                ->default('queued')->index();
            $table->string('wa_deeplink', 1024)->nullable();       // wa.me/... prefilled payload
            $table->json('payload')->nullable();                   // exact JSON pushed to n8n
            $table->json('geo_tags')->nullable();                  // {governorate, district, lat, lng}
            $table->string('n8n_execution_id')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tradesperson_profile_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('order_subtotal');
            $table->decimal('commission_percent', 5, 2);
            $table->bigInteger('commission_amount');
            $table->enum('settlement', ['prepaid_balance', 'escrow_deduction', 'invoice'])->default('prepaid_balance');
            $table->enum('status', ['accrued', 'settled', 'waived', 'reversed'])->default('accrued')->index();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tradesperson_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');                 // 1..5
            $table->text('comment')->nullable();
            $table->boolean('is_published')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('dispatch_jobs');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
