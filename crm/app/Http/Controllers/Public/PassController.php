<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\RegistrationParticipant;
use App\Services\Passes\AppleWalletPass;
use App\Services\Passes\CalendarFeed;
use App\Services\Passes\GoogleWalletPass;
use App\Services\Passes\ParticipantSchedule;
use App\Services\Passes\PassLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Links in the QR email and the portal: the participant's calendar (.ics) and their QR as a wallet pass.
 */
class PassController extends Controller
{
    public function calendar(RegistrationParticipant $participant, string $token, ParticipantSchedule $schedule, CalendarFeed $feed): Response
    {
        abort_unless(PassLinks::valid($participant, PassLinks::CALENDAR, $token), 404);

        $ics = $feed->ics($schedule->for($participant), config('crm.wallet.event_name'));

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="colombia-birdfair.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }

    public function apple(RegistrationParticipant $participant, string $token, AppleWalletPass $pass): Response
    {
        abort_unless(PassLinks::valid($participant, PassLinks::WALLET, $token) && AppleWalletPass::configured(), 404);

        return response($pass->build($participant), 200, [
            'Content-Type' => 'application/vnd.apple.pkpass',
            'Content-Disposition' => 'attachment; filename="'.$participant->checkin_code.'.pkpass"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function google(RegistrationParticipant $participant, string $token, GoogleWalletPass $pass): RedirectResponse
    {
        abort_unless(PassLinks::valid($participant, PassLinks::WALLET, $token) && GoogleWalletPass::configured(), 404);

        return redirect()->away($pass->saveUrl($participant));
    }
}
