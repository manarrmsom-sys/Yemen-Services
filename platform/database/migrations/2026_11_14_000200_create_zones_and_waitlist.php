<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Launch zones. A governorate is "active" via governorates.is_active;
        // inside it, each district is either served now or coming soon.
        Schema::create('districts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('governorate_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar', 80);
            $table->string('name_en', 80)->nullable();
            $table->string('status', 20)->default('coming_soon')->index();   // active | coming_soon
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['governorate_id', 'name_ar']);
        });

        // People asking for a zone that is not served yet.
        Schema::create('zone_waitlist', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('governorate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('district_name', 80)->nullable();                  // free text when the district is not listed
            $table->foreignId('service_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 20);
            $table->string('name', 120)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['governorate_id', 'district_id']);
            $table->index('phone');
        });

        Schema::table('tradesperson_profiles', function (Blueprint $table): void {
            $table->foreignId('base_district_id')->nullable()->after('base_governorate_id')->constrained('districts')->nullOnDelete();
        });

        Schema::table('addresses', function (Blueprint $table): void {
            $table->foreignId('district_id')->nullable()->after('governorate_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('addresses', fn (Blueprint $table) => $table->dropConstrainedForeignId('district_id'));
        Schema::table('tradesperson_profiles', fn (Blueprint $table) => $table->dropConstrainedForeignId('base_district_id'));
        Schema::dropIfExists('zone_waitlist');
        Schema::dropIfExists('districts');
    }
};
