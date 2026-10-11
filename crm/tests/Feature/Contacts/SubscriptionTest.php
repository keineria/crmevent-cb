<?php

namespace Tests\Feature\Contacts;

use App\Jobs\SendSubscriptionConfirmation;
use App\Mail\SubscriptionConfirmationMail;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['email' => 'Ave@Example.com', 'first_name' => 'Ave', 'consent' => '1'], $overrides);
    }

    public function test_sign_up_creates_a_pending_contact_and_sends_the_confirmation(): void
    {
        $this->postJson('/api/v1/subscribe', $this->payload())->assertOk()->assertJson(['ok' => true]);

        $contact = Contact::sole();
        $this->assertSame('ave@example.com', $contact->email);
        $this->assertSame(Contact::SOURCE_WEB, $contact->source);
        $this->assertFalse($contact->is_subscriber);
        $this->assertFalse($contact->marketing_consent);
        $this->assertNotNull($contact->subscription_requested_at);
        Queue::assertPushed(SendSubscriptionConfirmation::class, fn ($job) => $job->contact->is($contact));
    }

    public function test_consent_is_required(): void
    {
        $this->postJson('/api/v1/subscribe', $this->payload(['consent' => null]))->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->assertSame(0, Contact::count());
    }

    public function test_honeypot_submissions_are_silently_dropped(): void
    {
        $this->postJson('/api/v1/subscribe', $this->payload(['website' => 'http://spam.example']))->assertOk();

        $this->assertSame(0, Contact::count());
        Queue::assertNothingPushed();
    }

    public function test_double_submit_does_not_send_two_emails(): void
    {
        $this->postJson('/api/v1/subscribe', $this->payload())->assertOk();
        $this->postJson('/api/v1/subscribe', $this->payload())->assertOk();

        Queue::assertPushed(SendSubscriptionConfirmation::class, 1);
    }

    public function test_existing_contact_keeps_its_data(): void
    {
        $contact = Contact::factory()->create(['email' => 'ave@example.com', 'first_name' => 'Ana', 'city' => null, 'is_attendee' => true]);

        $this->postJson('/api/v1/subscribe', $this->payload(['first_name' => 'Otro', 'city' => 'Cali']))->assertOk();

        $contact->refresh();
        $this->assertSame(['Ana', 'Cali'], [$contact->first_name, $contact->city]);
        $this->assertTrue($contact->is_attendee);
        $this->assertSame(1, Contact::count());
    }

    public function test_confirmation_link_makes_the_contact_a_subscriber_with_consent(): void
    {
        $contact = Contact::factory()->create(['subscription_requested_at' => now()]);
        $url = URL::temporarySignedRoute('subscription.confirm', now()->addDay(), ['contact' => $contact->id]);

        $this->get($url)->assertOk()->assertSee('Suscripción confirmada');

        $contact->refresh();
        $this->assertTrue($contact->is_subscriber);
        $this->assertTrue($contact->marketing_consent);
        $this->assertSame('Formulario web (doble confirmación)', $contact->consent_source);
        $this->assertNotNull($contact->consent_ip);
        $this->assertSame(1, Contact::mailable()->count());
    }

    public function test_confirmation_link_must_be_signed(): void
    {
        $contact = Contact::factory()->create();

        $this->get("/suscripcion/confirmar/{$contact->id}")->assertForbidden();
        $this->assertFalse($contact->fresh()->is_subscriber);
    }

    public function test_confirmation_email_contains_a_signed_link(): void
    {
        $contact = Contact::factory()->create(['first_name' => 'Ave']);

        $html = (new SubscriptionConfirmationMail($contact))->render();

        $this->assertStringContainsString('Hola Ave', $html);
        $this->assertMatchesRegularExpression('#/suscripcion/confirmar/'.$contact->id.'\?expires=\d+&amp;signature=#', $html);
    }

    public function test_plain_form_posts_redirect_only_to_allowed_sites(): void
    {
        $this->post('/api/v1/subscribe', $this->payload(['redirect' => 'https://colombiabirdfair.com/gracias']))
            ->assertRedirect('https://colombiabirdfair.com/gracias');

        $this->post('/api/v1/subscribe', $this->payload(['email' => 'otro@example.com', 'redirect' => 'https://evil.example/x']))
            ->assertOk()
            ->assertSee('Revisa tu correo');
    }

    public function test_cors_allows_the_public_site_only(): void
    {
        $this->call('OPTIONS', '/api/v1/subscribe', [], [], [], [
            'HTTP_ORIGIN' => 'https://colombiabirdfair.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeader('Access-Control-Allow-Origin', 'https://colombiabirdfair.com');

        $response = $this->call('OPTIONS', '/api/v1/subscribe', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
