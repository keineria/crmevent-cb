<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Small CRM-wide settings, such as the uploaded logos.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Colour theme and light/dark mode follow the account across devices.
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 20)->nullable();
            $table->string('appearance', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['theme', 'appearance']);
        });
        Schema::dropIfExists('app_settings');
    }
};
