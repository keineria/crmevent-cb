<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\CampaignMail;
use App\Models\CampaignClick;
use App\Models\CampaignRecipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Open pixel and link redirect inside campaign emails. Opens are approximate: Apple Mail loads
 * every image in advance and some clients block images.
 */
class CampaignTrackingController extends Controller
{
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(string $token): Response
    {
        $recipient = CampaignRecipient::where('token', $token)->first();

        if ($recipient) {
            $recipient->forceFill(['opened_at' => $recipient->opened_at ?? now(), 'open_count' => $recipient->open_count + 1])->save();
        }

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, string $token): RedirectResponse
    {
        $url = (string) $request->query('u', '');

        abort_unless(
            preg_match('#^https?://#i', $url) && hash_equals(CampaignMail::linkSignature($token, $url), (string) $request->query('s', '')),
            404,
        );

        $recipient = CampaignRecipient::where('token', $token)->first();

        if ($recipient) {
            $recipient->forceFill([
                'clicked_at' => $recipient->clicked_at ?? now(),
                'click_count' => $recipient->click_count + 1,
                // A click means it was opened, even when images were blocked.
                'opened_at' => $recipient->opened_at ?? now(),
                'open_count' => max(1, $recipient->open_count),
            ])->save();
            CampaignClick::create(['campaign_recipient_id' => $recipient->id, 'url' => mb_substr($url, 0, 2000), 'created_at' => now()]);
        }

        return redirect()->away($url);
    }
}
