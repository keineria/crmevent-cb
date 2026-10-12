<?php

namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Mail\BroadcastSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class SendCampaignEmail implements ShouldQueue
{
    use Queueable;

    /** A failed send is marked and can be retried from the campaign page; retrying here would break the hourly pacing. */
    public int $tries = 1;

    public function __construct(public int $recipientId) {}

    public function handle(BroadcastSender $sender): void
    {
        $recipient = CampaignRecipient::with(['campaign', 'contact'])->find($this->recipientId);

        if (! $recipient || $recipient->status !== CampaignRecipient::QUEUED) {
            return;
        }

        $campaign = $recipient->campaign;

        if ($campaign->status !== Campaign::SENDING) {
            // Paused or cancelled after it was queued: back to the line (or out of it).
            $recipient->update(['status' => $campaign->status === Campaign::PAUSED ? CampaignRecipient::PENDING : CampaignRecipient::SKIPPED]);

            return;
        }

        if (! $recipient->contact) {
            $recipient->update(['status' => CampaignRecipient::SKIPPED, 'error' => 'El contacto fue eliminado.']);

            return;
        }

        try {
            $sent = $sender->send(new CampaignMail($recipient->contact, $campaign->subject, $campaign->preheader, $campaign->body, $recipient));
        } catch (Throwable $e) {
            $recipient->update(['status' => CampaignRecipient::FAILED, 'error' => Str::limit($e->getMessage(), 500)]);
            report($e);

            return;
        }

        $recipient->update($sent
            ? ['status' => CampaignRecipient::SENT, 'sent_at' => now(), 'error' => null]
            : ['status' => CampaignRecipient::SKIPPED, 'error' => 'Se dio de baja o ya no acepta difusiones.']);
    }
}
