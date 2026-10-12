<?php

namespace Tests\Feature\Settings;

use App\Mail\CampaignMail;
use App\Models\User;
use App\Services\Campaigns\CampaignRenderer;
use App\Support\Branding;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
    }

    public function test_only_admins_can_manage_logos(): void
    {
        $analyst = User::factory()->create();
        $analyst->assignRole(Roles::ANALYST);

        $this->actingAs($analyst)->get('/settings/branding')->assertForbidden();
        $this->actingAs($analyst)->post('/settings/branding/platform', ['logo' => UploadedFile::fake()->image('logo.png')])->assertForbidden();
        $this->actingAs($this->admin)->get('/settings/branding')->assertOk();
    }

    public function test_platform_logo_is_shared_and_used_as_fallback(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/branding/platform', ['logo' => UploadedFile::fake()->image('logo.png', 400, 100)])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Branding::path(Branding::PLATFORM));
        $this->assertNull(Branding::path(Branding::LOGIN));
        $this->assertNotNull(Branding::url(Branding::LOGIN));

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('branding.platform', Branding::url(Branding::PLATFORM))
            ->where('branding.email', Branding::url(Branding::EMAIL)));

        auth()->logout();
        $this->get(route('branding.logo', ['kind' => 'login']))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_replacing_and_removing_a_logo_deletes_the_old_file(): void
    {
        $this->actingAs($this->admin)->post('/settings/branding/login', ['logo' => UploadedFile::fake()->image('a.png')]);
        $first = Branding::path(Branding::LOGIN);
        $this->travel(2)->seconds();
        $this->actingAs($this->admin)->post('/settings/branding/login', ['logo' => UploadedFile::fake()->image('b.jpg')]);
        $second = Branding::path(Branding::LOGIN);

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);

        $this->actingAs($this->admin)->delete('/settings/branding/login');
        $this->assertNull(Branding::path(Branding::LOGIN));
        Storage::disk('local')->assertMissing($second);
        $this->get(route('branding.logo', ['kind' => 'login']))->assertNotFound();
    }

    public function test_rejects_svg_and_webp_in_emails(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($this->admin)->post('/settings/branding/platform', ['logo' => $svg])->assertSessionHasErrors('logo');

        $webp = UploadedFile::fake()->image('logo.webp');
        $this->actingAs($this->admin)->post('/settings/branding/email', ['logo' => $webp])->assertSessionHasErrors('logo');

        $this->actingAs($this->admin)->post('/settings/branding/nope', ['logo' => UploadedFile::fake()->image('a.png')])->assertNotFound();
    }

    public function test_campaign_emails_show_the_email_logo(): void
    {
        $mail = new CampaignMail(CampaignRenderer::sampleContact(), 'Hola', null, 'Texto');
        $this->assertStringContainsString('letter-spacing:0.2px;">Colombia Birdfair</td>', $mail->renderHtml());

        $this->actingAs($this->admin)->post('/settings/branding/email', ['logo' => UploadedFile::fake()->image('mail.png')]);

        $this->assertStringContainsString('src="'.e(Branding::url(Branding::EMAIL)).'"', $mail->renderHtml());
    }
}
