<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A book can also be one tab of a Google Sheet, read and written through the Sheets API.
        Schema::table('stand_workbooks', function (Blueprint $table) {
            $table->string('source', 10)->default('excel')->after('name');
            $table->string('google_spreadsheet_id')->nullable()->after('source');
            $table->string('google_sheet')->nullable()->after('google_spreadsheet_id');
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->string('path')->nullable()->change();
        });

        // What the team tracks for each sold stand; the stand itself lives in the sheet.
        Schema::create('stand_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stand_workbook_id')->constrained()->cascadeOnDelete();
            $table->string('stand_key', 191);
            $table->string('name')->nullable();
            $table->boolean('paid')->default(false);
            $table->boolean('documents')->default(false);
            $table->boolean('invoiced')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['stand_workbook_id', 'stand_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stand_purchases');
        Schema::table('stand_workbooks', function (Blueprint $table) {
            $table->dropColumn(['source', 'google_spreadsheet_id', 'google_sheet', 'synced_at', 'sync_error']);
        });
    }
};
