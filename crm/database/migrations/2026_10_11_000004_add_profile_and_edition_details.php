<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('document_type', 30)->nullable()->after('phone');
            $table->string('gender', 30)->nullable()->after('last_name');
            $table->string('state', 120)->nullable()->after('country');
        });

        Schema::table('contact_editions', function (Blueprint $table) {
            $table->json('details')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('contact_editions', fn (Blueprint $table) => $table->dropColumn('details'));
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn(['document_type', 'gender', 'state']));
    }
};
