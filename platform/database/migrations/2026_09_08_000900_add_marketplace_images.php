<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table): void {
            $table->string('image_url', 1024)->nullable()->after('accent_color');
        });

        Schema::table('tradesperson_profiles', function (Blueprint $table): void {
            $table->string('image_url', 1024)->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('tradesperson_profiles', fn (Blueprint $table) => $table->dropColumn('image_url'));
        Schema::table('service_categories', fn (Blueprint $table) => $table->dropColumn('image_url'));
    }
};