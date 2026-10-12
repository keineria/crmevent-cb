<?php

namespace Tests\Feature\Contacts;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Services\Contacts\ContactInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stars_follow_opens_and_clicks(): void
    {
        $this->assertNull(ContactInsights::stars(0, 0, 0));
        $this->assertSame(1, ContactInsights::stars(4, 0, 0));
        $this->assertSame(2, ContactInsights::stars(4, 2, 0));
        $this->assertSame(4, ContactInsights::stars(4, 4, 0));
        $this->assertSame(5, ContactInsights::stars(4, 4, 4));
    }

    public function test_parses_products_written_by_hand(): void
    {
        $this->assertSame(['conferencias', 'salidas'], ContactInsights::parseProducts('Salidas y Conferencias'));
        $this->assertSame(['talleres', 'otras', 'stand'], ContactInsights::parseProducts('stand; taller; otras actividades'));
        $this->assertSame([], ContactInsights::parseProducts('Nada'));
    }

    public function test_refresh_all_scores_campaigns_and_reads_plugin_purchases(): void
    {
        $contact = Contact::factory()->mailable()->create();
        foreach ([[now(), now()], [now(), null], [null, null], [null, null]] as $i => [$opened, $clicked]) {
            $campaign = Campaign::create(['name' => "Prueba {$i}", 'subject' => 'Hola', 'body' => 'Hola', 'status' => 'sent']);
            CampaignRecipient::create([
                'campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'email' => "c{$i}@example.com",
                'status' => CampaignRecipient::SENT, 'opened_at' => $opened, 'clicked_at' => $clicked,
            ]);
        }

        $registration = Registration::create(['wc_order_id' => 1, 'number' => '1', 'status' => Registration::PAID_STATUSES[0], 'currency' => 'COP', 'total' => 0, 'edition_year' => 2026]);
        $participant = RegistrationParticipant::create([
            'registration_id' => $registration->id, 'contact_id' => $contact->id, 'evr_order_participant_id' => 1, 'evr_participant_id' => 1, 'full_name' => 'Ana',
        ]);
        RegistrationActivity::create(['registration_participant_id' => $participant->id, 'evr_assignment_id' => 1, 'evr_activity_id' => 1, 'type' => 'birding', 'status' => 'confirmed']);
        RegistrationActivity::create(['registration_participant_id' => $participant->id, 'evr_assignment_id' => 2, 'evr_activity_id' => 2, 'type' => 'workshop', 'status' => 'cancelled']);

        $this->artisan('contacts:refresh-insights')->assertSuccessful();

        $contact->refresh();
        $this->assertSame([4, 2, 1], [$contact->emails_sent, $contact->emails_opened, $contact->emails_clicked]);
        $this->assertSame(3, $contact->engagement_stars);
        $this->assertSame(['salidas'], $contact->preferred_products);
    }

    public function test_products_chosen_by_hand_are_kept(): void
    {
        $contact = Contact::factory()->create(['preferred_products' => ['stand'], 'preferred_products_manual' => true]);

        app(ContactInsights::class)->refreshAll();

        $this->assertSame(['stand'], $contact->fresh()->preferred_products);
    }
}
