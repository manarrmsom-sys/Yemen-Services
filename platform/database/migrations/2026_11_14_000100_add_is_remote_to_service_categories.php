<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Digital work (design, development, video editing...) is delivered
        // online: no site visit, so no governorate dispatch fee or address.
        Schema::table('service_categories', function (Blueprint $table): void {
            $table->boolean('is_remote')->default(false)->after('requires_kyc');
        });
    }

    public function down(): void
    {
        Schema::table('service_categories', fn (Blueprint $table) => $table->dropColumn('is_remote'));
    }
};
