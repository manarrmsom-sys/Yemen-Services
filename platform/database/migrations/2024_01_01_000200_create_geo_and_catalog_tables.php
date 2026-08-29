<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governorates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();       // e.g. "sanaa"
            $table->string('name_ar');
            $table->string('name_en');
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            // Dispatch cost multiplier — remote governorates cost more to serve.
            $table->decimal('dispatch_multiplier', 4, 2)->default(1.00);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('description_ar')->nullable();
            $table->string('description_en')->nullable();
            $table->string('icon', 60)->nullable();      // font-awesome / lucide key
            $table->string('accent_color', 9)->default('#CE1126');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('requires_kyc')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_category_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            // Pricing model drives which estimator inputs are shown.
            $table->enum('pricing_model', ['fixed', 'hourly', 'per_unit', 'quote'])->default('fixed');
            $table->unsignedInteger('base_price')->default(0);        // in YER minor unit = 1 (YER has no subunit in practice)
            $table->unsignedInteger('hourly_rate')->default(0);
            $table->unsignedInteger('unit_price')->default(0);
            $table->string('unit_label_ar', 40)->nullable();          // "متر", "قطعة"
            $table->string('unit_label_en', 40)->nullable();
            $table->unsignedInteger('callout_fee')->default(0);       // fixed visit fee
            $table->unsignedSmallInteger('min_duration_minutes')->default(60);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Optional overrides layered on top of a service's base pricing.
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('governorate_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('rule_type', ['multiplier', 'flat_add', 'flat_override'])->default('multiplier');
            $table->decimal('value', 12, 2);
            $table->enum('applies_to', ['subtotal', 'callout', 'total'])->default('subtotal');
            $table->json('conditions')->nullable();      // { "urgency": "urgent", "weekday": [5,6] }
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
        Schema::dropIfExists('governorates');
    }
};
