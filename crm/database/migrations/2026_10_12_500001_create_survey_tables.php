<?php

use App\Models\SurveyForm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satisfaction surveys sent when an activity ends: one form of questions per activity type, one survey per
 * activity (with a copy of the questions it went out with) and one invitation per person, holding their answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_forms', function (Blueprint $table) {
            $table->id();
            // conference, workshop, birding or other: one form per plugin activity type.
            $table->string('activity_type', 20)->unique();
            $table->string('title', 120);
            $table->string('intro', 500)->nullable();
            // [{key, type: rating|nps|choice|text, label, required, options?}]
            $table->json('questions');
            $table->boolean('enabled')->default(true);
            // Only activities that end after this moment are surveyed on their own, so turning a form on never mails past activities.
            $table->timestamp('enabled_at')->nullable();
            $table->unsignedSmallInteger('delay_minutes')->default(60);
            // On by default: only people checked in get it. Off: everyone with a paid seat.
            $table->boolean('require_check_in')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evr_activity_id')->unique();
            $table->string('activity_type', 20)->index();
            $table->string('activity_name');
            $table->date('activity_date')->nullable()->index();
            $table->string('title', 120);
            $table->string('intro', 500)->nullable();
            // The questions as they were sent; editing the form later never changes what people answered.
            $table->json('questions');
            $table->boolean('require_check_in')->default(true);
            // auto (when the activity ended) or manual (Enviar ahora).
            $table->string('source', 10);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('survey_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_participant_id')->nullable()->constrained()->nullOnDelete();
            // One invitation per person: the plugin participant id, or "row{id}" when the plugin has none.
            $table->string('person_key', 40);
            $table->string('full_name');
            $table->string('email')->nullable();
            // Opens the person's survey without logging in.
            $table->string('token', 40)->unique();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            // question key => answer (int for rating and nps, string for choice and text).
            $table->json('answers')->nullable();
            $table->timestamps();

            $table->unique(['survey_id', 'person_key']);
        });

        $now = now();
        foreach (SurveyForm::DEFAULTS as $type => $form) {
            DB::table('survey_forms')->insert([
                'activity_type' => $type,
                'title' => $form['title'],
                'intro' => $form['intro'],
                'questions' => json_encode($form['questions'], JSON_UNESCAPED_UNICODE),
                'enabled' => true,
                'enabled_at' => $now,
                'delay_minutes' => 60,
                'require_check_in' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('mail_templates')->insert([
            'system_key' => 'survey',
            'name' => 'Correo de la encuesta',
            'subject' => '¿Cómo te fue en {{actividad}}?',
            'preheader' => 'Cuéntanos en un minuto qué te pareció',
            'body' => <<<'MD'
                # Hola {{nombre}}

                Gracias por acompañarnos en **{{actividad}}**. Queremos saber qué te pareció: son pocas preguntas y te toma un minuto.

                {{encuesta}}

                Tus respuestas nos ayudan a preparar una mejor feria.
                MD,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('mail_templates')->where('system_key', 'survey')->delete();
        Schema::dropIfExists('survey_invitations');
        Schema::dropIfExists('surveys');
        Schema::dropIfExists('survey_forms');
    }
};
