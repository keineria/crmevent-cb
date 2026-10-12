<?php

namespace Tests\Feature\Contacts;

use App\Mail\BroadcastMailable;
use App\Models\Contact;
use App\Models\MailSetting;
use App\Services\Mail\BroadcastSender;
use App\Services\Mail\CrmMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    private function newsletter(Contact $contact): BroadcastMailable
    {
        return new class($contact) extends BroadcastMailable
        {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Boletín');
            }

            public function content(): Content
            {
                return new Content(htmlString: '<p>Hola</p>'.view('mail.partials.broadcast-footer', [
                    'unsubscribeUrl' => $this->unsubscribeUrl,
                    'footerText' => $this->footerText,
                ])->render());
            }
        };
    }

    public function test_unsubscribe_page_and_button(): void
    {
        $contact = Contact::factory()->mailable()->create(['is_subscriber' => true]);

        $this->get($contact->unsubscribeUrl())->assertOk()->assertSee('Darme de baja');
        $this->post($contact->unsubscribeUrl())->assertOk()->assertSee('te diste de baja');

        $contact->refresh();
        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertFalse($contact->marketing_consent);
        $this->assertFalse($contact->is_subscriber);
        $this->get($contact->unsubscribeUrl())->assertOk()->assertSee('Ya estás dado de baja');
    }

    public function test_one_click_unsubscribe_from_mail_providers(): void
    {
        $contact = Contact::factory()->mailable()->create();

        $this->post($contact->unsubscribeUrl(), ['List-Unsubscribe' => 'One-Click'])->assertNoContent();

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }

    public function test_unknown_token_is_not_found(): void
    {
        $this->get('/baja/no-existe')->assertNotFound();
        $this->post('/baja/no-existe')->assertNotFound();
    }

    public function test_broadcasts_carry_unsubscribe_headers_and_footer(): void
    {
        MailSetting::create(['profile' => 'broadcast', 'footer_text' => "Colombia Birdfair\nCali, Colombia"]);
        $contact = Contact::factory()->mailable()->create();

        $mail = $this->newsletter($contact);

        $this->assertSame([
            'List-Unsubscribe' => "<{$contact->unsubscribeUrl()}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ], $mail->headers()->text);
        $html = $mail->render();
        $this->assertStringContainsString($contact->unsubscribeUrl(), $html);
        $this->assertStringContainsString('Colombia Birdfair<br />', $html);
    }

    public function test_broadcast_sender_skips_contacts_without_consent(): void
    {
        $this->mock(CrmMailer::class)->shouldReceive('for')->with('broadcast')->andReturn(Mail::mailer('array'));
        $allowed = Contact::factory()->mailable()->create();
        $noConsent = Contact::factory()->create();
        $unsubscribed = Contact::factory()->mailable()->create(['unsubscribed_at' => now()]);

        $sender = app(BroadcastSender::class);

        $this->assertTrue($sender->send($this->newsletter($allowed)));
        $this->assertFalse($sender->send($this->newsletter($noConsent)));
        $this->assertFalse($sender->send($this->newsletter($unsubscribed)));

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame($allowed->email, $messages[0]->getEnvelope()->getRecipients()[0]->getAddress());
    }
}
