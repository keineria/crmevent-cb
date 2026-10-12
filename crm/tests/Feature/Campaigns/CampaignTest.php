<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\SendCampaignEmail;
use App\Mail\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactEdition;
use App\Models\MailSetting;
use App\Models\MailTemplate;
use App\Models\Registration;
use App\Models\User;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\Campaigns\Segment;
use App\Services\Mail\BroadcastSender;
use App\Services\Mail\CrmMailer;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function user(string $role = Roles::MARKETING): User
    {
        return tap(User::factory()->create())->assignRole($role);
    }

    private function activeSender(array $overrides = []): MailSetting
    {
        $setting = MailSetting::forProfile(MailSetting::PROFILE_BROADCAST);
        $setting->fill(['from_address' => 'boletin@colombiabirdfair.com', 'from_name' => 'Colombia Birdfair', 'hourly_limit' => 60, ...$overrides]);
        $setting->active_config = ['transport' => 'array'];
        $setting->save();

        return $setting;
    }

    private function fakeMailer(): void
    {
        $this->mock(CrmMailer::class)->shouldReceive('for')->with('broadcast')->andReturn(Mail::mailer('array'));
    }

    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::create([
            'name' => 'Lanzamiento',
            'subject' => '{{nombre|Hola}}, volvemos en 2027',
            'body' => "# Hola {{nombre|amigo}}\n\n[[Inscribirme|https://colombiabirdfair.com/inscripciones]]",
            'segment' => [],
            'status' => Campaign::DRAFT,
            ...$attributes,
        ]);
    }

    private function attended(Contact $contact, int ...$years): Contact
    {
        foreach ($years as $year) {
            ContactEdition::create(['contact_id' => $contact->id, 'year' => $year, 'role' => ContactEdition::ROLE_ATTENDEE]);
        }

        return $contact;
    }

    public function test_starter_templates_exist(): void
    {
        $this->assertGreaterThanOrEqual(3, MailTemplate::count());
    }

    public function test_only_campaign_roles_reach_campaigns(): void
    {
        $this->actingAs($this->user(Roles::REGISTRATION))->get('/campaigns')->assertForbidden();
        $this->actingAs($this->user(Roles::ANALYST))->get('/campaigns')->assertOk();
        $this->actingAs($this->user(Roles::ANALYST))->post('/campaigns')->assertForbidden();
        $this->actingAs($this->user())->get('/campaigns')->assertOk();
    }

    public function test_creating_from_a_template_copies_its_content(): void
    {
        $template = MailTemplate::create(['name' => 'Boletín', 'subject' => 'Novedades', 'body' => 'Hola']);

        $this->actingAs($this->user())->post('/campaigns', ['template_id' => $template->id])->assertRedirect();

        $campaign = Campaign::latest('id')->first();
        $this->assertSame('Novedades', $campaign->subject);
        $this->assertSame(Campaign::DRAFT, $campaign->status);
        $this->actingAs($this->user())->get("/campaigns/{$campaign->id}/edit")->assertOk();
    }

    public function test_segment_filters_combine_and_respect_consent(): void
    {
        $loyal = $this->attended(Contact::factory()->mailable()->create(['is_attendee' => true]), 2024, 2025, 2026);
        $once = $this->attended(Contact::factory()->mailable()->create(['is_attendee' => true, 'country' => 'Ecuador']), 2025);
        $noConsent = $this->attended(Contact::factory()->create(['is_attendee' => true]), 2024, 2025, 2026);
        $subscriber = Contact::factory()->mailable()->create(['is_subscriber' => true]);
        $unsubscribed = Contact::factory()->mailable()->create(['is_subscriber' => true, 'unsubscribed_at' => now()]);

        $ids = fn (array $segment) => Segment::query($segment)->pluck('id')->sort()->values()->all();

        $this->assertSame([$loyal->id, $once->id, $subscriber->id], $ids([]));
        $this->assertSame([$loyal->id], $ids(['min_visitas' => 2]));
        $this->assertSame([$loyal->id, $once->id], $ids(['anios' => [2025]]));
        $this->assertSame([$once->id], $ids(['anios' => [2025], 'pais' => 'internacional']));
        $this->assertSame([$subscriber->id], $ids(['tipos' => ['suscriptores']]));
        $this->assertNotContains($noConsent->id, $ids(['min_visitas' => 1]));
        $this->assertNotContains($unsubscribed->id, $ids(['tipos' => ['suscriptores']]));
    }

    public function test_segment_by_purchase_in_an_edition(): void
    {
        $buyer = Contact::factory()->mailable()->create();
        $notYet = Contact::factory()->mailable()->create();
        $registration = Registration::create(['wc_order_id' => 1, 'number' => '1', 'status' => 'completed', 'currency' => 'COP', 'edition_year' => 2027]);
        $registration->participants()->create(['contact_id' => $buyer->id, 'evr_order_participant_id' => 1, 'evr_participant_id' => 1, 'full_name' => 'Ana']);

        $this->assertSame([$buyer->id], Segment::query(['compro' => 2027])->pluck('id')->all());
        $this->assertSame([$notYet->id], Segment::query(['no_compro' => 2027])->pluck('id')->all());
    }

    public function test_segment_count_endpoint(): void
    {
        Contact::factory()->mailable()->count(2)->create(['is_subscriber' => true]);
        Contact::factory()->mailable()->create();

        $this->actingAs($this->user())
            ->postJson('/campaigns/segment/count', ['segment' => ['tipos' => ['suscriptores']]])
            ->assertOk()
            ->assertJson(['count' => 2]);
    }

    public function test_renderer_fills_fields_buttons_and_escapes(): void
    {
        $contact = new Contact(['first_name' => 'MARÍA JOSÉ', 'last_name' => '<b>Gómez</b>']);
        $renderer = app(CampaignRenderer::class);

        $html = $renderer->body("# Hola {{nombre}}\n\n{{apellido}} <script>x</script>\n\n[[Ver más|https://colombiabirdfair.com]]", $contact);

        $this->assertStringContainsString('Hola María', $html);
        $this->assertStringContainsString('&lt;b&gt;Gómez&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('href="https://colombiabirdfair.com"', $html);
        $this->assertStringContainsString('Ver más</a>', $html);
        $this->assertSame('amigo, hola', $renderer->subject('{{ciudad|amigo}}, hola', $contact));
    }

    public function test_preview_returns_the_email_html(): void
    {
        $this->actingAs($this->user())
            ->postJson('/campaigns/preview', ['subject' => 'Hola {{nombre}}', 'body' => '**Novedades**'])
            ->assertOk()
            ->assertJson(['subject' => 'Hola María'])
            ->assertJsonPath('html', fn (string $html) => str_contains($html, '<strong>Novedades</strong>') && str_contains($html, 'Darte de baja'));
    }

    public function test_launch_requires_a_verified_sender(): void
    {
        Contact::factory()->mailable()->create();
        $campaign = $this->campaign();

        $this->actingAs($this->user())->post("/campaigns/{$campaign->id}/launch")->assertSessionHas('error');
        $this->assertSame(Campaign::DRAFT, $campaign->fresh()->status);
    }

    public function test_launch_snapshots_recipients_and_scheduler_sends_within_the_hourly_limit(): void
    {
        Queue::fake();
        $this->activeSender(['hourly_limit' => 120]);
        Contact::factory()->mailable()->count(5)->create();
        Contact::factory()->create();
        $campaign = $this->campaign();

        $this->actingAs($this->user())->post("/campaigns/{$campaign->id}/launch")->assertRedirect("/campaigns/{$campaign->id}");

        $campaign->refresh();
        $this->assertSame(Campaign::SENDING, $campaign->status);
        $this->assertSame(5, $campaign->recipients()->count());

        // 120 per hour = 2 per minute.
        $this->artisan('campaigns:send')->assertSuccessful();
        Queue::assertPushed(SendCampaignEmail::class, 2);
        $this->assertSame(2, $campaign->recipients()->where('status', CampaignRecipient::QUEUED)->count());
    }

    public function test_hourly_limit_counts_what_was_already_sent(): void
    {
        Queue::fake();
        $this->activeSender(['hourly_limit' => 60, 'daily_limit' => 100]);
        $dispatcher = app(CampaignDispatcher::class);
        $old = $this->campaign(['status' => Campaign::SENT]);
        foreach (range(1, 60) as $i) {
            $old->recipients()->create(['email' => "p{$i}@example.com", 'status' => CampaignRecipient::SENT, 'attempted_at' => now()->subMinutes(30)]);
        }

        $this->assertSame(0, $dispatcher->budget());

        $this->travel(31)->minutes();
        $this->assertSame(1, $dispatcher->budget());
    }

    public function test_scheduled_campaign_waits_for_its_date(): void
    {
        Queue::fake();
        $this->activeSender();
        Contact::factory()->mailable()->create();
        $campaign = $this->campaign();
        $when = now('America/Bogota')->addDay()->format('Y-m-d\TH:i');

        $this->actingAs($this->user())->post("/campaigns/{$campaign->id}/launch", ['scheduled_at' => $when]);

        $this->assertSame(Campaign::SCHEDULED, $campaign->fresh()->status);
        $this->artisan('campaigns:send');
        $this->assertSame(0, $campaign->recipients()->count());

        $this->travel(25)->hours();
        $this->artisan('campaigns:send');
        $this->assertSame(Campaign::SENDING, $campaign->fresh()->status);
        $this->assertSame(1, $campaign->recipients()->count());
    }

    public function test_send_job_delivers_with_tracking_and_unsubscribe(): void
    {
        $this->fakeMailer();
        $this->activeSender(['track_opens' => true, 'track_clicks' => true]);
        $contact = Contact::factory()->mailable()->create(['first_name' => 'Ana']);
        $campaign = $this->campaign(['status' => Campaign::SENDING, 'started_at' => now()]);
        $recipient = $campaign->recipients()->create(['contact_id' => $contact->id, 'email' => $contact->email, 'status' => CampaignRecipient::QUEUED]);

        (new SendCampaignEmail($recipient->id))->handle(app(BroadcastSender::class));

        $recipient->refresh();
        $this->assertSame(CampaignRecipient::SENT, $recipient->status);
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages[0]->getOriginalMessage();
        $this->assertSame('Ana, volvemos en 2027', $message->getSubject());
        $html = $message->getHtmlBody();
        $this->assertStringContainsString(route('campaigns.track.open', $recipient->token), $html);
        $this->assertStringContainsString('/e/c/'.$recipient->token, $html);
        $this->assertStringContainsString($contact->unsubscribeUrl(), $html);
        $this->assertStringContainsString($contact->unsubscribeUrl(), $message->getHeaders()->get('List-Unsubscribe')->getBodyAsString());

        app(CampaignDispatcher::class)->finishIfDone($campaign);
        $this->assertSame(Campaign::SENT, $campaign->fresh()->status);
    }

    public function test_send_job_skips_people_who_unsubscribed_after_the_snapshot(): void
    {
        $this->fakeMailer();
        $this->activeSender();
        $contact = Contact::factory()->mailable()->create();
        $campaign = $this->campaign(['status' => Campaign::SENDING]);
        $recipient = $campaign->recipients()->create(['contact_id' => $contact->id, 'email' => $contact->email, 'status' => CampaignRecipient::QUEUED]);
        $contact->unsubscribe();

        (new SendCampaignEmail($recipient->id))->handle(app(BroadcastSender::class));

        $this->assertSame(CampaignRecipient::SKIPPED, $recipient->fresh()->status);
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_paused_campaign_puts_queued_mail_back_in_line(): void
    {
        $this->fakeMailer();
        $contact = Contact::factory()->mailable()->create();
        $campaign = $this->campaign(['status' => Campaign::PAUSED]);
        $recipient = $campaign->recipients()->create(['contact_id' => $contact->id, 'email' => $contact->email, 'status' => CampaignRecipient::QUEUED]);

        (new SendCampaignEmail($recipient->id))->handle(app(BroadcastSender::class));

        $this->assertSame(CampaignRecipient::PENDING, $recipient->fresh()->status);
    }

    public function test_open_and_click_tracking(): void
    {
        $campaign = $this->campaign(['status' => Campaign::SENT]);
        $recipient = $campaign->recipients()->create(['email' => 'a@example.com', 'status' => CampaignRecipient::SENT, 'sent_at' => now()]);
        $url = 'https://colombiabirdfair.com/inscripciones?x=1';

        $this->get(route('campaigns.track.open', $recipient->token))->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get(route('campaigns.track.click', ['token' => $recipient->token, 'u' => $url, 's' => CampaignMail::linkSignature($recipient->token, $url)]))
            ->assertRedirect($url);
        $this->get(route('campaigns.track.click', ['token' => $recipient->token, 'u' => 'https://evil.example', 's' => 'nope']))->assertNotFound();

        $recipient->refresh();
        $this->assertNotNull($recipient->opened_at);
        $this->assertSame(1, $recipient->click_count);
        $this->assertSame($url, $recipient->clicks()->value('url'));

        $this->actingAs($this->user(Roles::ANALYST))->get("/campaigns/{$campaign->id}")->assertOk();
    }

    public function test_test_send_goes_only_to_the_given_address(): void
    {
        $this->fakeMailer();
        $this->activeSender();
        Contact::factory()->mailable()->count(3)->create();
        $campaign = $this->campaign();

        $this->actingAs($this->user())->post("/campaigns/{$campaign->id}/test", ['email' => 'yo@example.com'])->assertSessionHas('success');

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('yo@example.com', $messages[0]->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertStringStartsWith('[Prueba]', $messages[0]->getOriginalMessage()->getSubject());
        $this->assertSame(0, CampaignRecipient::count());
    }

    public function test_cancel_stops_pending_mail_and_retry_requeues_failures(): void
    {
        $campaign = $this->campaign(['status' => Campaign::SENDING]);
        $campaign->recipients()->create(['email' => 'a@example.com', 'status' => CampaignRecipient::PENDING]);
        $failed = $campaign->recipients()->create(['email' => 'b@example.com', 'status' => CampaignRecipient::FAILED, 'error' => 'SMTP']);

        $this->actingAs($this->user())->post("/campaigns/{$campaign->id}/retry");
        $this->assertSame(CampaignRecipient::PENDING, $failed->fresh()->status);

        $this->actingAs($this->user())->post("/campaigns/{$campaign->id}/cancel");
        $this->assertSame(Campaign::CANCELLED, $campaign->fresh()->status);
        $this->assertSame(0, $campaign->recipients()->where('status', CampaignRecipient::PENDING)->count());
    }

    public function test_templates_crud(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/campaigns/templates')->assertOk();
        $this->actingAs($user)->post('/campaigns/templates')->assertRedirect();
        $template = MailTemplate::latest('id')->first();

        $this->actingAs($user)->put("/campaigns/templates/{$template->id}", ['name' => 'Recordatorio', 'subject' => 'Faltan 10 días', 'body' => 'Hola'])
            ->assertSessionHas('success');
        $this->assertSame('Faltan 10 días', $template->fresh()->subject);

        $this->actingAs($user)->delete("/campaigns/templates/{$template->id}")->assertRedirect('/campaigns/templates');
        $this->assertNull($template->fresh());
    }
}
