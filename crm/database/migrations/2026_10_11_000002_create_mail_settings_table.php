<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            // transactional: acceso, recomendaciones, reenvíos · broadcast: campañas
            $table->string('profile', 20)->unique();
            $table->string('provider', 20)->default('smtp');
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('encryption', 10)->default('tls');
            $table->string('aws_region', 30)->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_address')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('dkim_selector', 63)->nullable();
            $table->unsignedInteger('hourly_limit')->nullable();
            $table->unsignedInteger('daily_limit')->nullable();
            $table->boolean('track_opens')->default(true);
            $table->boolean('track_clicks')->default(true);
            $table->text('footer_text')->nullable();
            // Snapshot of the last configuration that passed a test send; the app only sends with this one.
            $table->text('active_config')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('last_test_error')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->nullableMorphs('subject');
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('mail_settings');
    }
};
