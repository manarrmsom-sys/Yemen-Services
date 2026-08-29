<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('governorate_id')->constrained()->restrictOnDelete();
            $table->string('label', 40)->nullable();          // "المنزل", "المكتب"
            $table->string('district')->nullable();            // الحي
            $table->string('street')->nullable();
            $table->string('building')->nullable();
            $table->text('directions')->nullable();            // landmark-based, common in Yemen
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['governorate_id', 'district']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
