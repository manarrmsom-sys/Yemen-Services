<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tradesperson_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('primary_category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->foreignId('base_governorate_id')->nullable()->constrained('governorates')->nullOnDelete();
            $table->string('display_name');
            $table->string('headline_ar')->nullable();
            $table->string('headline_en')->nullable();
            $table->text('bio')->nullable();
            $table->unsignedTinyInteger('years_experience')->default(0);
            $table->json('service_governorate_ids')->nullable();   // where they accept jobs
            $table->json('skills')->nullable();

            // KYC / trust state machine.
            $table->enum('kyc_status', ['unverified', 'pending', 'in_review', 'verified', 'rejected'])
                ->default('unverified')->index();
            $table->timestamp('kyc_submitted_at')->nullable();
            $table->timestamp('kyc_verified_at')->nullable();
            $table->foreignId('kyc_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kyc_rejection_reason')->nullable();
            $table->boolean('background_checked')->default(false);

            // Badges are derived but cached here for fast listing.
            $table->json('badges')->nullable();                    // ["verified","background_checked","top_rated"]

            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('completed_orders')->default(0);
            $table->boolean('is_accepting_jobs')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tradesperson_profile_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['national_id_front', 'national_id_back', 'selfie', 'trade_certificate', 'commercial_register', 'other']);
            $table->string('disk')->default('kyc');
            $table->string('path');                                // never public
            $table->string('original_name')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->enum('review_status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->string('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('tradesperson_profiles');
    }
};
