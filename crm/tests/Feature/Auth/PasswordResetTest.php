<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\MailSetting;
use App\Models\User;
use App\Services\Mail\CrmMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function activeProfile(string $profile): void
    {
        $setting = MailSetting::forProfile($profile);
        $setting->fill(['from_address' => 'crm@colombiabirdfair.com', 'from_name' => 'Colombia Birdfair']);
        $setting->active_config = ['transport' => 'array'];
        $setting->save();
    }

    private function fakeMailer(string $profile = MailSetting::PROFILE_TRANSACTIONAL): void
    {
        $this->mock(CrmMailer::class, function ($mock) use ($profile) {
            $mock->makePartial();
            $mock->shouldReceive('for')->with($profile)->andReturn(Mail::mailer('array'));
        });
    }

    /** @return list<SentMessage> */
    private function sentMessages(): array
    {
        return array_values(iterator_to_array(Mail::mailer('array')->getSymfonyTransport()->messages()));
    }

    private function resetUrlFromMail(): string
    {
        $body = $this->sentMessages()[0]->getOriginalMessage()->getHtmlBody();
        preg_match('#href="([^"]*/reset-password/[^"]+)"#', $body, $match);

        return html_entity_decode($match[1]);
    }

    public function test_reset_password_link_screen_can_be_rendered()
    {
        $this->get('/forgot-password')->assertStatus(200);
    }

    public function test_the_link_goes_out_through_the_transactional_account()
    {
        $this->activeProfile(MailSetting::PROFILE_TRANSACTIONAL);
        $this->fakeMailer();
        $user = User::factory()->create(['email' => 'keiner@colombiabirdfair.com']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('keiner@colombiabirdfair.com', $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertSame('Restablece tu contraseña del CRM', $messages[0]->getOriginalMessage()->getSubject());
        $this->assertStringContainsString('/reset-password/', $this->resetUrlFromMail());
        $this->assertTrue(AuditLog::where('action', 'auth.password_reset_requested')->exists());
    }

    public function test_the_broadcast_account_is_used_when_transactional_is_not_active()
    {
        $this->activeProfile(MailSetting::PROFILE_BROADCAST);
        $this->fakeMailer(MailSetting::PROFILE_BROADCAST);
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->sentMessages());
    }

    public function test_without_an_active_account_the_user_is_told_and_it_is_audited()
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasErrors('email');

        $this->assertTrue(AuditLog::where('action', 'auth.password_reset_failed')->exists());
    }

    public function test_unknown_emails_get_the_same_answer_and_no_mail()
    {
        $this->activeProfile(MailSetting::PROFILE_TRANSACTIONAL);
        $this->fakeMailer();

        $this->post('/forgot-password', ['email' => 'nadie@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertCount(0, $this->sentMessages());
    }

    public function test_deactivated_users_get_no_link()
    {
        $this->activeProfile(MailSetting::PROFILE_TRANSACTIONAL);
        $this->fakeMailer();
        $user = User::factory()->create(['deactivated_at' => now()]);

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

        $this->assertCount(0, $this->sentMessages());
    }

    public function test_password_can_be_reset_from_the_emailed_link()
    {
        $this->activeProfile(MailSetting::PROFILE_TRANSACTIONAL);
        $this->fakeMailer();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);
        $url = $this->resetUrlFromMail();
        $this->get($url)->assertStatus(200);

        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NuevaClave2027',
            'password_confirmation' => 'NuevaClave2027',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->post('/login', ['email' => $user->email, 'password' => 'NuevaClave2027']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_invalid_token_is_rejected()
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'no-vale',
            'email' => $user->email,
            'password' => 'NuevaClave2027',
            'password_confirmation' => 'NuevaClave2027',
        ])->assertSessionHasErrors('email');
    }

    public function test_the_console_command_prints_a_working_link()
    {
        $user = User::factory()->create(['email' => 'keiner@colombiabirdfair.com']);

        $this->artisan('crm:reset-link', ['email' => 'keiner@colombiabirdfair.com'])
            ->expectsOutputToContain('/reset-password/')
            ->assertSuccessful();

        $this->artisan('crm:reset-link', ['email' => 'nadie@example.com'])->assertFailed();
        $this->assertTrue(Password::broker()->getRepository()->recentlyCreatedToken($user));
    }
}
