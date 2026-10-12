<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('remember_token');
            $table->timestamp('last_login_at')->nullable()->after('deactivated_at');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            // Who did it, kept as text so the history still reads right after the account is deleted.
            $table->string('user_name', 191)->nullable()->after('user_id');
            // What it was done to, as text, for the same reason (a deleted draft, a removed expense).
            $table->string('subject_label', 191)->nullable()->after('subject_id');
            $table->string('user_agent', 255)->nullable()->after('ip');
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['user_name', 'subject_label', 'user_agent']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['deactivated_at', 'last_login_at']);
        });
    }
};
