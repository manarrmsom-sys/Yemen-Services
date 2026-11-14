<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Maintenance-contract leads from businesses (hotels, restaurants, clinics...).
        Schema::create('b2b_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 16)->unique();
            $table->string('business_name', 160);
            $table->string('business_type', 40)->index();
            $table->foreignId('governorate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->string('district_name', 80)->nullable();
            $table->string('facility_size', 255);                 // units, rooms, devices: free text
            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 20);                          // WhatsApp number
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('new')->index(); // new | contacted | quoted | won | lost
            $table->text('admin_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });

        // Hyper-local launch: a governorate that has no *active* district is not
        // live. Applied once to data that predates this rule; from now on the
        // seeders create governorates inactive and admins switch them on.
        // (On a fresh install this runs before seeding and touches nothing.)
        DB::table('governorates')
            ->whereNotIn('id', DB::table('districts')->where('status', 'active')->select('governorate_id'))
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_requests');
    }
};
