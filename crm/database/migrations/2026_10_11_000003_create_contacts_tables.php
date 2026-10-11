<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('kind', 20);
            $table->unsignedSmallInteger('edition_year')->nullable();
            $table->boolean('has_consent')->default(false);
            $table->json('headers')->nullable();
            $table->json('sample')->nullable();
            $table->json('mapping')->nullable();
            $table->string('status', 20)->default('mapping');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone', 40)->nullable();
            $table->string('document_number', 40)->nullable()->index();
            $table->string('country', 80)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('organization')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_attendee')->default(false)->index();
            $table->boolean('is_interested')->default(false)->index();
            $table->boolean('is_subscriber')->default(false)->index();
            $table->string('source', 20);
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_source')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->timestamp('subscription_requested_at')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('unsubscribe_token', 64)->unique();
            $table->timestamps();
        });

        Schema::create('contact_editions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('role', 20);
            $table->foreignId('contact_import_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['contact_id', 'year', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_editions');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('contact_imports');
    }
};
