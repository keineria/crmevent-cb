<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subject');
            $table->string('preheader')->nullable();
            $table->longText('body');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subject');
            $table->string('preheader')->nullable();
            $table->longText('body');
            $table->json('segment')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('token', 40)->unique();
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('attempted_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamps();
            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('campaign_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_recipient_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->timestamp('created_at')->nullable();
        });

        $now = now();
        DB::table('mail_templates')->insert(array_map(fn (array $t) => $t + ['created_at' => $now, 'updated_at' => $now], [
            [
                'name' => 'Boletín',
                'subject' => 'Novedades de Colombia Birdfair',
                'preheader' => 'Lo nuevo de la feria, en pocas líneas.',
                'body' => "# Hola {{nombre|amigo}}\n\nTe contamos las novedades de **Colombia Birdfair**.\n\n## Lo nuevo\n\n- Primera novedad.\n- Segunda novedad.\n\n[[Ver más en el sitio|https://colombiabirdfair.com]]\n\nNos vemos pronto,\n\nEquipo Colombia Birdfair",
            ],
            [
                'name' => 'Lanzamiento de la edición 2027',
                'subject' => '{{nombre|Hola}}, ya abrimos inscripciones para Colombia Birdfair 2027',
                'preheader' => 'Del 30 de septiembre al 3 de octubre de 2027.',
                'body' => "# {{nombre|Hola}}, ¡volvemos en 2027!\n\nColombia Birdfair 2027 será del **jueves 30 de septiembre al domingo 3 de octubre**.\n\n- **Jueves:** conferencias y actividades.\n- **Viernes a domingo:** salidas de avistamiento, talleres y networking.\n\nLos cupos de las salidas son limitados.\n\n[[Inscribirme|https://colombiabirdfair.com]]\n\nEquipo Colombia Birdfair",
            ],
            [
                'name' => 'Te extrañamos',
                'subject' => '{{nombre|Hola}}, este año te esperamos de nuevo',
                'preheader' => 'Gracias por haber estado en la feria.',
                'body' => "# Gracias por acompañarnos, {{nombre|amigo}}\n\nFuiste parte de Colombia Birdfair y nos encantaría verte de nuevo este año.\n\nYa están abiertas las inscripciones a conferencias, talleres y salidas.\n\n[[Ver la programación|https://colombiabirdfair.com]]\n\nEquipo Colombia Birdfair",
            ],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_clicks');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('mail_templates');
    }
};
