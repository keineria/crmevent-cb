<?php

use App\Models\RegistrationParticipant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_participants', function (Blueprint $table) {
            // Printed in the participant's QR. Random so nobody can guess someone else's code.
            $table->string('checkin_code', 12)->nullable()->unique()->after('registered_by');
            $table->timestamp('qr_queued_at')->nullable()->after('checkin_code');
            $table->timestamp('qr_sent_at')->nullable()->after('qr_queued_at');
        });

        Schema::table('registration_activities', function (Blueprint $table) {
            // Name of the plugin's discount rule behind `discount`, shown on the participant's record.
            $table->string('discount_label')->nullable()->after('total');
        });

        DB::table('registration_participants')->whereNull('checkin_code')->orderBy('id')->select('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('registration_participants')->where('id', $row->id)->update(['checkin_code' => RegistrationParticipant::newCheckinCode()]);
                }
            });

        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_activity_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('registration_participant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('evr_activity_id')->index();
            $table->string('method', 12);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('checked_in_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');

        Schema::table('registration_activities', function (Blueprint $table) {
            $table->dropColumn('discount_label');
        });

        Schema::table('registration_participants', function (Blueprint $table) {
            $table->dropUnique(['checkin_code']);
            $table->dropColumn(['checkin_code', 'qr_queued_at', 'qr_sent_at']);
        });
    }
};
