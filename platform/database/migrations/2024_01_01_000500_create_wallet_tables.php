<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One wallet per tradesperson + system wallets (platform_escrow, platform_revenue).
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('owner');                       // tradesperson_profile OR null for system wallets
            $table->string('type')->index();                      // tradesperson | platform_escrow | platform_revenue
            $table->char('currency', 3)->default('YER');

            // Denormalised balances (authoritative source is wallet_transactions; these are kept in sync in a txn).
            $table->bigInteger('available_balance')->default(0);   // withdrawable / spendable
            $table->bigInteger('pending_balance')->default(0);     // held in escrow for in-progress orders
            $table->bigInteger('commission_due')->default(0);      // COD commission the tradesperson owes the platform
            $table->bigInteger('lifetime_earnings')->default(0);
            $table->bigInteger('lifetime_commission_paid')->default(0);

            $table->boolean('is_frozen')->default(false);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'type']);
        });

        // Double-entry ledger. Every money movement writes >= 2 rows sharing a `group_uuid`.
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('group_uuid')->index();                  // ties both legs of a transfer together
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->enum('direction', ['credit', 'debit']);
            $table->string('bucket')->default('available');       // available | pending | commission_due
            $table->bigInteger('amount');                         // always positive
            $table->bigInteger('balance_after');                  // snapshot of the affected bucket
            $table->string('type');                               // order_earning | commission_charge | escrow_hold | escrow_release | topup | withdrawal | adjustment | reversal
            $table->nullableMorphs('reference');                  // order, wallet_topup, etc.
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        // Tradesperson tops up their commission balance (bank transfer / local wallet / cash at office).
        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('tradesperson_profile_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->char('currency', 3)->default('YER');
            $table->enum('method', ['bank_transfer', 'local_wallet', 'cash_office', 'card', 'other'])->default('bank_transfer');
            $table->string('reference_code')->nullable();          // transfer receipt no.
            $table->string('proof_path')->nullable();              // uploaded transfer screenshot
            $table->enum('status', ['pending', 'confirmed', 'rejected'])->default('pending')->index();
            $table->string('review_note')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            // Set when reconciled automatically via n8n/SMS parsing.
            $table->string('auto_matched_via')->nullable();
            $table->timestamps();
        });

        // NOTE: commission_entries is created in the orders migration (needs orders.id).
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_topups');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
