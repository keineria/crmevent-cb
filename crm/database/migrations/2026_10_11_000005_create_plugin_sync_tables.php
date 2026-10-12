<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_connections', function (Blueprint $table) {
            $table->id();
            $table->string('base_url')->nullable();
            $table->text('api_key')->nullable();
            $table->date('created_after')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('cursor_updated', 19)->nullable();
            $table->unsignedBigInteger('cursor_order')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->json('last_result')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('event_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evr_id')->unique();
            $table->string('type', 20)->index();
            $table->string('name');
            $table->string('destination')->nullable();
            $table->date('event_date')->nullable()->index();
            $table->string('start_time', 8)->nullable();
            $table->string('end_time', 8)->nullable();
            $table->decimal('price', 14, 2)->default(0);
            $table->unsignedInteger('capacity_total')->default(0);
            $table->unsignedInteger('reserved_count')->default(0);
            $table->unsignedInteger('confirmed_count')->default(0);
            $table->string('status', 20);
            $table->timestamps();
        });

        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wc_order_id')->unique();
            $table->string('number', 40);
            $table->string('status', 30)->index();
            $table->string('currency', 10);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('ordered_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->json('billing')->nullable();
            $table->foreignId('buyer_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->unsignedSmallInteger('edition_year')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('registration_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('evr_order_participant_id')->unique();
            $table->unsignedBigInteger('evr_participant_id')->index();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('document_type', 50)->nullable();
            $table->string('document_number', 60)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('shirt_size', 5)->nullable();
            $table->string('meal_type', 20)->nullable();
            $table->text('allergies')->nullable();
            $table->string('entity')->nullable();
            $table->string('registered_by')->nullable();
            $table->timestamps();
        });

        Schema::create('registration_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_participant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('evr_assignment_id')->unique();
            $table->unsignedBigInteger('evr_activity_id')->index();
            $table->string('name')->nullable();
            $table->string('type', 20)->nullable()->index();
            $table->date('event_date')->nullable();
            $table->string('start_time', 8)->nullable();
            $table->string('status', 20)->index();
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_activities');
        Schema::dropIfExists('registration_participants');
        Schema::dropIfExists('registrations');
        Schema::dropIfExists('event_activities');
        Schema::dropIfExists('plugin_connections');
    }
};
