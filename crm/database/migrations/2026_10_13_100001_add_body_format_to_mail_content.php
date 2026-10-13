<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a template or campaign body is Markdown or HTML pasted from a template builder.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['mail_templates', 'campaigns'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('body_format', 10)->default('markdown')->after('body');
            });
        }
    }

    public function down(): void
    {
        foreach (['mail_templates', 'campaigns'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('body_format');
            });
        }
    }
};
