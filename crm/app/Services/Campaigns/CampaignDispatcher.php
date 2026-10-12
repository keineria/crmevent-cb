<?php

namespace App\Services\Campaigns;

use App\Jobs\SendCampaignEmail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MailSetting;
use Illuminate\Support\Facades\DB;

/**
 * Sends campaigns little by little so the mailbox never goes over its provider's limits.
 * Runs every minute from the scheduler (campaigns:send).
 */
class CampaignDispatcher
{
    /**
     * Freezes the recipient list: later sign-ups don't get it, and unsubscribes are rechecked at send time.
     */
    public function snapshot(Campaign $campaign): int
    {
        $now = now();

        Segment::query($campaign->segment)
            ->select(['id', 'email'])
            ->chunkById(500, function ($contacts) use ($campaign, $now) {
                CampaignRecipient::insertOrIgnore($contacts->map(fn ($c) => [
                    'campaign_id' => $campaign->id,
                    'contact_id' => $c->id,
                    'email' => $c->email,
                    'token' => CampaignRecipient::newToken(),
                    'status' => CampaignRecipient::PENDING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

        return $campaign->recipients()->count();
    }

    /**
     * Starts the campaign now, or leaves it waiting for its date.
     */
    public function launch(Campaign $campaign): void
    {
        if ($campaign->scheduled_at && $campaign->scheduled_at->isFuture()) {
            $campaign->update(['status' => Campaign::SCHEDULED]);

            return;
        }

        DB::transaction(function () use ($campaign) {
            $this->snapshot($campaign);
            $campaign->update(['status' => Campaign::SENDING, 'started_at' => $campaign->started_at ?? now()]);
        });
    }

    /**
     * @return int emails handed to the queue in this run
     */
    public function tick(): int
    {
        Campaign::where('status', Campaign::SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->get()
            ->each(fn (Campaign $c) => $this->launch($c));

        // A worker that died leaves rows queued forever; after an hour they go back in line.
        CampaignRecipient::where('status', CampaignRecipient::QUEUED)
            ->where('attempted_at', '<', now()->subHour())
            ->update(['status' => CampaignRecipient::PENDING]);

        $budget = $this->budget();
        $queued = 0;

        foreach (Campaign::where('status', Campaign::SENDING)->orderBy('started_at')->orderBy('id')->get() as $campaign) {
            if ($queued < $budget) {
                $ids = $campaign->recipients()->where('status', CampaignRecipient::PENDING)->orderBy('id')->limit($budget - $queued)->pluck('id');

                foreach ($ids as $id) {
                    $claimed = CampaignRecipient::whereKey($id)->where('status', CampaignRecipient::PENDING)
                        ->update(['status' => CampaignRecipient::QUEUED, 'attempted_at' => now()]);
                    if ($claimed) {
                        SendCampaignEmail::dispatch($id);
                        $queued++;
                    }
                }
            }

            $this->finishIfDone($campaign);
        }

        return $queued;
    }

    public function finishIfDone(Campaign $campaign): void
    {
        $open = $campaign->recipients()->whereIn('status', [CampaignRecipient::PENDING, CampaignRecipient::QUEUED])->exists();

        if (! $open && $campaign->status === Campaign::SENDING) {
            $campaign->update(['status' => Campaign::SENT, 'finished_at' => now()]);
        }
    }

    /**
     * How many emails may go out this minute: an even share of the hourly limit, never above
     * what is left of the hourly and daily limits counted over the last hour and day.
     */
    public function budget(): int
    {
        [$hourly, $daily] = $this->limits();

        $perMinute = (int) ceil($hourly / 60);
        $lastHour = CampaignRecipient::where('attempted_at', '>=', now()->subHour())->count();
        $left = min($perMinute, $hourly - $lastHour);

        if ($daily) {
            $left = min($left, $daily - CampaignRecipient::where('attempted_at', '>=', now()->subDay())->count());
        }

        return max(0, $left);
    }

    /**
     * @return array{0: int, 1: int|null} emails per hour and per day (null = no daily limit)
     */
    public function limits(): array
    {
        $setting = MailSetting::where('profile', MailSetting::PROFILE_BROADCAST)->first();

        return [
            max(1, (int) ($setting?->hourly_limit ?: config('crm.campaign_emails_per_hour'))),
            $setting?->daily_limit ?: null,
        ];
    }
}
