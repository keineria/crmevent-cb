<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\MailSetting;
use App\Models\User;
use App\Services\Mail\DomainDnsChecker;
use App\Services\Mail\MailSettingTester;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'brevo',
            'host' => 'smtp-relay.brevo.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'apikey-user',
            'password' => 'super-secret-1234',
            'from_name' => 'Colombia Birdfair',
            'from_address' => 'inscripciones@colombiabirdfair.com',
            'reply_to' => 'info@colombiabirdfair.com',
            'track_opens' => true,
            'track_clicks' => true,
        ], $overrides);
    }

    public function test_only_admins_can_open_the_mail_settings(): void
    {
        $analyst = User::factory()->create();
        $analyst->assignRole(Roles::ANALYST);

        $this->actingAs($analyst)->get('/settings/mail')->assertForbidden();
        $this->actingAs($analyst)->put('/settings/mail/transactional', $this->validPayload())->assertForbidden();
        $this->actingAs($this->admin)->get('/settings/mail')->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/settings/mail')->assertRedirect('/login');
    }

    public function test_password_is_stored_encrypted_and_never_sent_to_the_browser(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/mail/transactional', $this->validPayload())
            ->assertSessionHasNoErrors();

        $raw = DB::table('mail_settings')->where('profile', 'transactional')->value('password');
        $this->assertNotSame('super-secret-1234', $raw);
        $this->assertSame('super-secret-1234', MailSetting::forProfile('transactional')->password);

        $this->actingAs($this->admin)->get('/settings/mail')
            ->assertDontSee('super-secret-1234')
            ->assertSee('1234');
    }

    public function test_blank_password_keeps_the_saved_one(): void
    {
        $this->actingAs($this->admin)->put('/settings/mail/transactional', $this->validPayload());
        $this->actingAs($this->admin)->put('/settings/mail/transactional', $this->validPayload(['password' => '', 'from_name' => 'CBF']));

        $setting = MailSetting::forProfile('transactional');
        $this->assertSame('super-secret-1234', $setting->password);
        $this->assertSame('CBF', $setting->from_name);
    }

    public function test_broadcast_profile_requires_the_legal_footer(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/mail/broadcast', $this->validPayload())
            ->assertSessionHasErrors('footer_text');
    }

    public function test_unknown_profile_is_rejected(): void
    {
        $this->actingAs($this->admin)->put('/settings/mail/otro', $this->validPayload())->assertForbidden();
        $this->actingAs($this->admin)->post('/settings/mail/otro/test', ['to' => 'a@b.co'])->assertNotFound();
    }

    public function test_a_successful_test_send_activates_the_configuration(): void
    {
        $this->actingAs($this->admin)->put('/settings/mail/transactional', $this->validPayload());

        $tester = Mockery::mock(MailSettingTester::class);
        $tester->shouldReceive('send')->once()->withArgs(fn ($setting, $to) => $to === 'keiner@example.com');
        $this->app->instance(MailSettingTester::class, $tester);

        $this->actingAs($this->admin)
            ->post('/settings/mail/transactional/test', ['to' => 'keiner@example.com'])
            ->assertSessionHas('success');

        $setting = MailSetting::forProfile('transactional');
        $this->assertTrue($setting->isActive());
        $this->assertNotNull($setting->verified_at);
        $this->assertSame('smtp-relay.brevo.com', $setting->active_config['host']);
        $this->assertTrue(AuditLog::where('action', 'mail_setting.activated')->exists());
    }

    public function test_a_failed_test_keeps_the_previous_active_configuration(): void
    {
        $this->actingAs($this->admin)->put('/settings/mail/transactional', $this->validPayload());
        $ok = Mockery::mock(MailSettingTester::class);
        $ok->shouldReceive('send')->once();
        $this->app->instance(MailSettingTester::class, $ok);
        $this->actingAs($this->admin)->post('/settings/mail/transactional/test', ['to' => 'keiner@example.com']);

        $this->actingAs($this->admin)->put('/settings/mail/transactional', $this->validPayload(['host' => 'smtp.wrong.example']));
        $this->assertNull(MailSetting::forProfile('transactional')->verified_at);

        $failing = Mockery::mock(MailSettingTester::class);
        $failing->shouldReceive('send')->once()->andThrow(new RuntimeException('Connection refused'));
        $this->app->instance(MailSettingTester::class, $failing);

        $this->actingAs($this->admin)
            ->post('/settings/mail/transactional/test', ['to' => 'keiner@example.com'])
            ->assertSessionHas('error');

        $setting = MailSetting::forProfile('transactional');
        $this->assertSame('smtp-relay.brevo.com', $setting->active_config['host']);
        $this->assertSame('Connection refused', $setting->last_test_error);
    }

    public function test_changing_only_tracking_does_not_require_a_new_test(): void
    {
        $this->actingAs($this->admin)->put('/settings/mail/transactional', $this->validPayload());
        $ok = Mockery::mock(MailSettingTester::class);
        $ok->shouldReceive('send')->once();
        $this->app->instance(MailSettingTester::class, $ok);
        $this->actingAs($this->admin)->post('/settings/mail/transactional/test', ['to' => 'keiner@example.com']);

        $this->actingAs($this->admin)->put('/settings/mail/transactional', $this->validPayload(['password' => '', 'track_opens' => false]));

        $this->assertNotNull(MailSetting::forProfile('transactional')->verified_at);
    }

    public function test_dns_check_reports_each_record(): void
    {
        $this->actingAs($this->admin)->put('/settings/mail/transactional', $this->validPayload(['dkim_selector' => 'mail']));

        $records = [
            'colombiabirdfair.com' => [['type' => 'TXT', 'txt' => 'v=spf1 include:spf.brevo.com ~all']],
            'mail._domainkey.colombiabirdfair.com' => [['type' => 'TXT', 'txt' => 'k=rsa; p=MIGf']],
            '_dmarc.colombiabirdfair.com' => [],
        ];
        $this->app->instance(DomainDnsChecker::class, new DomainDnsChecker(fn (string $host) => $records[$host] ?? []));

        $this->actingAs($this->admin)
            ->post('/settings/mail/transactional/dns')
            ->assertSessionHas('dns.transactional', [
                'domain' => 'colombiabirdfair.com',
                'spf' => true,
                'dkim' => true,
                'dmarc' => false,
            ]);
    }
}
