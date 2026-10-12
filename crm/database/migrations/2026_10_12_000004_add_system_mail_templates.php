<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Emails the CRM sends on its own (today, the check-in QR) live next to the campaign templates
 * so they are edited with the same editor, marked by a system key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_templates', function (Blueprint $table) {
            $table->string('system_key', 40)->nullable()->unique()->after('id');
        });

        DB::table('mail_templates')->insert([
            'system_key' => 'checkin_qr',
            'name' => 'Correo del código QR',
            'subject' => 'Tu código de ingreso a Colombia Birdfair · {{nombre_completo}}',
            'preheader' => 'Muéstralo en la entrada y en el punto de encuentro de tus salidas',
            'body' => <<<'MD'
                # Hola {{nombre}}

                Este es tu código para entrar a **Colombia Birdfair**. Muéstralo en la entrada y en el punto de encuentro de tus salidas; también puedes dar tu número de documento.

                {{qr}}

                {{actividades}}

                El código es personal. Si alguien más va contigo, cada persona recibe el suyo.
                MD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('mail_templates')->whereNotNull('system_key')->delete();

        Schema::table('mail_templates', function (Blueprint $table) {
            $table->dropUnique(['system_key']);
            $table->dropColumn('system_key');
        });
    }
};
