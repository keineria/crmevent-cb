<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance certificates: categories hold the design, each certificate ties a category to one plugin activity,
 * and recipients are the people it went to, each with a public verification code.
 */
return new class extends Migration
{
    public function up(): void
    {
        // People who sign certificates, kept once and picked in each category.
        Schema::create('certificate_signers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('role', 80)->nullable();
            $table->string('image_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('certificate_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // conference or workshop: the plugin activity type whose activities can use this design.
            $table->string('activity_type', 20)->index();
            $table->string('template', 20);
            $table->string('primary_color', 7);
            $table->string('accent_color', 7);
            $table->string('heading', 120);
            $table->string('intro', 200)->nullable();
            $table->text('body')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('background_path')->nullable();
            // Ids of certificate_signers, in the order they are printed (up to three).
            $table->json('signer_ids')->nullable();
            // Only for the "propio" template: where each block goes on the uploaded design, in mm.
            $table->json('layout')->nullable();
            $table->boolean('show_qr')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_category_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('evr_activity_id');
            $table->string('activity_name');
            $table->date('activity_date')->nullable();
            $table->decimal('hours', 5, 1)->nullable();
            // On by default: only people checked in at the entrance get it. Off: everyone with a paid seat.
            $table->boolean('require_check_in')->default(true);
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['certificate_category_id', 'evr_activity_id']);
            $table->index('evr_activity_id');
        });

        Schema::create('certificate_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_participant_id')->nullable()->constrained()->nullOnDelete();
            // One certificate per person: the plugin participant id, or "row{id}" when the plugin has none.
            $table->string('person_key', 40);
            $table->string('full_name');
            $table->string('document_number', 40)->nullable();
            $table->string('email')->nullable();
            // Printed on the certificate and in its QR; opens the public verification page.
            $table->string('code', 16)->unique();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['certificate_id', 'person_key']);
        });

        DB::table('mail_templates')->insert([
            'system_key' => 'certificate',
            'name' => 'Correo del certificado',
            'subject' => 'Tu certificado de asistencia · {{actividad}}',
            'preheader' => 'Gracias por acompañarnos en Colombia Birdfair',
            'body' => <<<'MD'
                # Hola {{nombre}}

                Gracias por acompañarnos en **{{actividad}}**. Adjunto encuentras tu certificado de asistencia en PDF.

                {{certificado}}

                Cualquier persona puede comprobar que es auténtico con el código QR del certificado o en este enlace: {{enlace}}

                ¡Nos vemos en la próxima edición!
                MD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('mail_templates')->where('system_key', 'certificate')->delete();
        Schema::dropIfExists('certificate_recipients');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('certificate_categories');
        Schema::dropIfExists('certificate_signers');
    }
};
