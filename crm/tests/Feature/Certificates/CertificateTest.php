<?php

namespace Tests\Feature\Certificates;

use App\Jobs\SendCertificate;
use App\Mail\CertificateMail;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateCategory;
use App\Models\CertificateRecipient;
use App\Models\CertificateSigner;
use App\Models\CheckIn;
use App\Models\EventActivity;
use App\Models\MailSetting;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Models\User;
use App\Services\Certificates\CertificateAudience;
use App\Services\Certificates\CertificatePdf;
use App\Services\Mail\CrmMailer;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    use RefreshDatabase;

    private const WORKSHOP = 20;

    private const CONFERENCE = 30;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2027-10-05 15:00:00', 'UTC'));

        EventActivity::create(['evr_id' => self::WORKSHOP, 'type' => 'workshop', 'name' => 'Taller de fotografía', 'event_date' => '2027-10-01', 'price' => 80000, 'status' => 'active']);
        EventActivity::create(['evr_id' => self::CONFERENCE, 'type' => 'conference', 'name' => 'Conferencias', 'event_date' => '2027-09-30', 'price' => 50000, 'status' => 'active']);
        EventActivity::create(['evr_id' => 40, 'type' => 'birding', 'name' => 'Salida Km 18', 'event_date' => '2027-10-02', 'price' => 300000, 'status' => 'active']);
    }

    private function staff(string $role = Roles::ADMIN): User
    {
        return tap(User::factory()->create())->assignRole($role);
    }

    /**
     * A participant with a seat in the workshop, optionally checked in.
     */
    private function seat(string $name, bool $checkedIn, string $status = 'processing', ?string $email = 'p@example.com', ?int $evrParticipant = null): RegistrationParticipant
    {
        $this->seq++;
        $registration = Registration::create([
            'wc_order_id' => 1000 + $this->seq, 'number' => (string) (1000 + $this->seq), 'status' => $status, 'currency' => 'COP',
            'total' => 80000, 'billing' => ['email' => 'buyer@example.com'],
        ]);
        $participant = RegistrationParticipant::create([
            'registration_id' => $registration->id, 'evr_order_participant_id' => $this->seq, 'evr_participant_id' => $evrParticipant ?? $this->seq,
            'full_name' => $name, 'email' => $email, 'document_type' => 'cc', 'document_number' => (string) (5000 + $this->seq),
        ]);
        $activity = RegistrationActivity::create([
            'registration_participant_id' => $participant->id, 'evr_assignment_id' => $this->seq, 'evr_activity_id' => self::WORKSHOP,
            'name' => 'Taller de fotografía', 'type' => 'workshop', 'event_date' => '2027-10-01', 'status' => 'confirmed',
        ]);
        if ($checkedIn) {
            CheckIn::create([
                'registration_activity_id' => $activity->id, 'registration_participant_id' => $participant->id, 'evr_activity_id' => self::WORKSHOP,
                'method' => CheckIn::METHOD_QR, 'checked_in_at' => '2027-10-01 13:00:00',
            ]);
        }

        return $participant;
    }

    private function category(array $attributes = []): CertificateCategory
    {
        return CertificateCategory::create([...CertificateCategory::DEFAULTS, 'name' => 'Talleres 2027', 'activity_type' => 'workshop', ...$attributes]);
    }

    private function certificate(bool $requireCheckIn = true): Certificate
    {
        return Certificate::create([
            'certificate_category_id' => $this->category()->id, 'evr_activity_id' => self::WORKSHOP, 'activity_name' => 'Taller de fotografía',
            'activity_date' => '2027-10-01', 'hours' => 4, 'require_check_in' => $requireCheckIn,
        ]);
    }

    private function activeTransactional(): void
    {
        $setting = MailSetting::forProfile(MailSetting::PROFILE_TRANSACTIONAL);
        $setting->fill(['from_address' => 'hola@colombiabirdfair.com', 'from_name' => 'Colombia Birdfair']);
        $setting->active_config = ['transport' => 'array'];
        $setting->save();
    }

    public function test_only_admins_registration_admins_and_marketing_open_certificates(): void
    {
        $this->actingAs($this->staff(Roles::REGISTRATION))->get('/certificados')->assertForbidden();
        $this->actingAs($this->staff(Roles::ANALYST))->get('/certificados')->assertForbidden();
        $this->actingAs($this->staff(Roles::MARKETING))->get('/certificados')->assertOk();
        $this->actingAs($this->staff(Roles::REGISTRATION_ADMIN))->get('/certificados')
            ->assertInertia(fn (Assert $page) => $page->component('certificates/index')->has('activities', 2));
    }

    public function test_a_category_is_created_with_a_ready_design_and_edited(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->post('/certificados/categorias', ['name' => 'Talleres 2027', 'activity_type' => 'workshop'])
            ->assertRedirect();
        $category = CertificateCategory::sole();
        $this->assertSame('selva', $category->template);

        $ana = CertificateSigner::create(['name' => 'Ana Restrepo', 'role' => 'Directora']);
        $luis = CertificateSigner::create(['name' => 'Luis Gil', 'role' => null]);

        $this->actingAs($admin)->put("/certificados/categorias/{$category->id}", [
            'name' => 'Talleres', 'activity_type' => 'workshop', 'template' => 'clasico', 'primary_color' => '#123456', 'accent_color' => '#ABCDEF',
            'heading' => 'Certificado', 'intro' => 'Otorgado a', 'body' => 'por asistir a {{actividad}}.', 'show_qr' => false,
            'signer_ids' => [$luis->id, $ana->id],
        ])->assertSessionHasNoErrors();

        $category->refresh();
        $this->assertSame('clasico', $category->template);
        // In the order they were picked.
        $this->assertSame(['Luis Gil', 'Ana Restrepo'], array_column($category->signers(), 'name'));
        $this->assertTrue(AuditLog::where('action', 'certificate.category_updated')->exists());

        $this->actingAs($admin)->put("/certificados/categorias/{$category->id}", ['template' => 'otro', 'primary_color' => 'rojo'])
            ->assertSessionHasErrors(['template', 'primary_color', 'name']);
    }

    public function test_logo_background_and_signature_images_go_into_the_pdf(): void
    {
        $admin = $this->staff();
        $category = $this->category();

        $this->actingAs($admin)->post('/certificados/firmantes', [
            'name' => 'Ana Restrepo', 'role' => 'Directora', 'image' => UploadedFile::fake()->image('firma.png', 400, 120),
        ])->assertSessionHasNoErrors();
        $signer = CertificateSigner::sole();
        $this->assertNotNull($signer->image_path);
        $this->assertTrue(AuditLog::where('action', 'certificate.signer_created')->exists());

        foreach (['logo', 'fondo'] as $kind) {
            $this->actingAs($admin)->post("/certificados/categorias/{$category->id}/archivo/{$kind}", [
                'file' => UploadedFile::fake()->image("{$kind}.png", 600, 300),
            ])->assertSessionHasNoErrors();
        }
        $this->actingAs($admin)->post("/certificados/categorias/{$category->id}/archivo/logo", ['file' => UploadedFile::fake()->create('x.svg', 3, 'image/svg+xml')])
            ->assertSessionHasErrors('file');

        $category->refresh();
        $this->assertNotNull($category->logo_path);
        $this->assertNotNull($category->background_path);

        $this->actingAs($admin)->put("/certificados/categorias/{$category->id}", [
            ...CertificateCategory::DEFAULTS, 'name' => 'Talleres', 'activity_type' => 'workshop', 'signer_ids' => [$signer->id],
        ])->assertSessionHasNoErrors();

        $html = app(CertificatePdf::class)->html($category->fresh(), ['nombre' => 'Ana', 'documento' => '1', 'actividad' => 'X', 'edicion' => '2027'], 'CERT-AAAAAAAAAA');
        $this->assertSame(4, substr_count($html, 'data:image/png;base64')); // logo, background, signature and QR

        $response = $this->actingAs($admin)->post("/certificados/categorias/{$category->id}/vista-previa", [
            ...CertificateCategory::DEFAULTS, 'name' => 'Talleres', 'activity_type' => 'workshop', 'template' => 'geometrico',
        ]);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->actingAs($admin)->delete("/certificados/categorias/{$category->id}/archivo/logo");
        $this->assertNull($category->fresh()->logo_path);
    }

    public function test_own_design_places_each_block_where_it_was_dragged(): void
    {
        $admin = $this->staff();
        $category = $this->category(['template' => 'propio']);
        $signer = CertificateSigner::create(['name' => 'Ana Restrepo', 'role' => 'Directora']);
        $this->actingAs($admin)->post("/certificados/categorias/{$category->id}/archivo/fondo", ['file' => UploadedFile::fake()->image('diseno.png', 1200, 848)]);

        $this->actingAs($admin)->put("/certificados/categorias/{$category->id}", [
            ...CertificateCategory::DEFAULTS, 'template' => 'propio', 'name' => 'Talleres', 'activity_type' => 'workshop', 'signer_ids' => [$signer->id],
            'layout' => [
                'name' => ['x' => 100, 'y' => 90.5, 'w' => 150, 'size' => 44, 'color' => '#AA0000', 'align' => 'right', 'font' => 'script', 'visible' => true],
                'document' => ['visible' => false],
            ],
        ])->assertSessionHasNoErrors();

        $category->refresh();
        $layout = CertificateCategory::normalizeLayout($category->layout);
        $this->assertSame(100, $layout['name']['x']);
        $this->assertFalse($layout['document']['visible']);
        // Blocks that were not sent keep their defaults.
        $this->assertSame(CertificateCategory::DEFAULT_LAYOUT['qr'], $layout['qr']);

        $html = app(CertificatePdf::class)->html($category, ['nombre' => 'Ana Pérez', 'documento' => '777', 'actividad' => 'Taller', 'edicion' => '2027'], 'CERT-AAAAAAAAAA');
        $this->assertStringContainsString('left:100.0mm;top:90.5mm;width:150.0mm;font-size:44.0pt;color:#AA0000;text-align:right;font-family:\'Great Vibes\'', $html);
        $this->assertStringNotContainsString('Documento 777', $html);
        $this->assertStringContainsString('Ana Restrepo', $html);
        $this->assertStringStartsWith('%PDF', app(CertificatePdf::class)->sample($category));

        $this->actingAs($admin)->put("/certificados/categorias/{$category->id}", [
            ...CertificateCategory::DEFAULTS, 'template' => 'propio', 'name' => 'Talleres', 'activity_type' => 'workshop',
            'layout' => ['name' => ['color' => 'rojo', 'font' => 'comic']],
        ])->assertSessionHasErrors(['layout.name.color', 'layout.name.font']);
    }

    public function test_signers_are_edited_and_removed_from_categories_when_deleted(): void
    {
        $admin = $this->staff();
        $signer = CertificateSigner::create(['name' => 'Ana', 'role' => 'Directora']);
        $category = $this->category(['signer_ids' => [$signer->id]]);

        $this->actingAs($admin)->get('/certificados/firmantes')
            ->assertInertia(fn (Assert $page) => $page->component('certificates/signers')->where('signers.0.categories', ['Talleres 2027']));

        $this->actingAs($admin)->post("/certificados/firmantes/{$signer->id}", [
            '_method' => 'put', 'name' => 'Ana Restrepo', 'role' => 'Directora general', 'image' => UploadedFile::fake()->image('f.jpg', 300, 100),
        ])->assertSessionHasNoErrors();
        $this->assertSame('Ana Restrepo', $signer->fresh()->name);
        $this->assertNotNull($signer->fresh()->image_path);

        $this->actingAs($admin)->delete("/certificados/firmantes/{$signer->id}")->assertSessionHas('success');
        $this->assertSame([], $category->fresh()->signer_ids);
        $this->assertSame(0, CertificateSigner::count());
        $this->actingAs($this->staff(Roles::ANALYST))->get('/certificados/firmantes')->assertForbidden();
    }

    public function test_every_template_renders_a_pdf(): void
    {
        foreach (array_keys(CertificateCategory::TEMPLATES) as $template) {
            $pdf = app(CertificatePdf::class)->sample($this->category(['template' => $template, 'name' => $template]));
            $this->assertStringStartsWith('%PDF', $pdf, $template);
        }
    }

    public function test_the_text_drops_the_hours_clause_when_there_are_none(): void
    {
        $pdf = app(CertificatePdf::class);
        $text = CertificateCategory::DEFAULTS['body'];
        $values = ['actividad' => 'Taller <b>', 'fecha' => '1 de octubre de 2027', 'edicion' => '2027'];

        $this->assertSame(
            'asistió a <strong>Taller &lt;b&gt;</strong>, realizada el 1 de octubre de 2027 en el marco de Colombia Birdfair 2027.',
            $pdf->body($text, [...$values, 'horas' => '']),
        );
        $this->assertStringContainsString('con una intensidad de 4 horas.', $pdf->body($text, [...$values, 'horas' => '4']));
    }

    public function test_activities_are_associated_only_to_categories_of_their_type(): void
    {
        $category = $this->category();

        $this->actingAs($this->staff())->post('/certificados', [
            'category' => $category->id, 'activities' => [self::WORKSHOP, self::CONFERENCE, 40], 'hours' => 4, 'require_check_in' => true,
        ])->assertSessionHas('success');

        $this->assertSame([self::WORKSHOP], Certificate::pluck('evr_activity_id')->all());
        $this->assertSame('Taller de fotografía', Certificate::sole()->activity_name);
        $this->assertTrue(AuditLog::where('action', 'certificate.created')->exists());
    }

    public function test_only_checked_in_paid_people_receive_it_by_default(): void
    {
        $certificate = $this->certificate();
        $ana = $this->seat('Ana Pérez', checkedIn: true);
        $this->seat('Luis Gil', checkedIn: false);
        $this->seat('Pedro Ruiz', checkedIn: true, status: 'pending');
        // Same person bought twice: one certificate.
        $this->seat('Ana Pérez', checkedIn: false, evrParticipant: $ana->evr_participant_id);

        $this->actingAs($this->staff())->get("/certificados/{$certificate->id}")
            ->assertInertia(fn (Assert $page) => $page->component('certificates/show')
                ->where('stats.seats', 2)
                ->where('stats.checked_in', 1)
                ->where('stats.eligible', 1)
                ->has('people', 2));

        $this->assertSame(['Ana Pérez'], $certificate->recipients()->pluck('full_name')->all());

        $certificate->update(['require_check_in' => false]);
        $this->actingAs($this->staff())->get("/certificados/{$certificate->id}")
            ->assertInertia(fn (Assert $page) => $page->where('stats.eligible', 2));
    }

    public function test_send_queues_one_paced_email_per_pending_person_and_never_twice(): void
    {
        Queue::fake();
        config(['crm.certificate_emails_per_hour' => 60]);
        $this->activeTransactional();
        $certificate = $this->certificate();
        $this->seat('Ana Pérez', checkedIn: true);
        $this->seat('Luis Gil', checkedIn: true);
        $this->seat('Sin Correo', checkedIn: true, email: null)->registration->update(['billing' => []]);
        $this->seat('No Llegó', checkedIn: false);

        $this->actingAs($this->staff())->post("/certificados/{$certificate->id}/enviar")->assertSessionHas('success');

        Queue::assertPushed(SendCertificate::class, 2);
        Queue::assertPushed(SendCertificate::class, fn (SendCertificate $job) => $job->delay !== null);
        $this->assertNotNull($certificate->fresh()->sent_at);
        $this->assertSame(2, AuditLog::where('action', 'certificate.sent')->sole()->changes['correos']);

        $this->actingAs($this->staff())->post("/certificados/{$certificate->id}/enviar")->assertSessionHas('error');
        Queue::assertPushed(SendCertificate::class, 2);
    }

    public function test_sending_needs_a_verified_mailbox(): void
    {
        Queue::fake();
        $certificate = $this->certificate();
        $this->seat('Ana Pérez', checkedIn: true);

        $this->actingAs($this->staff())->post("/certificados/{$certificate->id}/enviar")->assertSessionHas('error');
        Queue::assertNothingPushed();
    }

    public function test_the_job_mails_the_pdf_and_marks_it_sent(): void
    {
        $this->activeTransactional();
        $this->mock(CrmMailer::class)->shouldReceive('for')->with(MailSetting::PROFILE_TRANSACTIONAL)->andReturn(Mail::mailer('array'));
        $certificate = $this->certificate();
        $this->seat('Ana Pérez', checkedIn: true);
        $recipient = app(CertificateAudience::class)->sync($certificate)->sole();

        (new SendCertificate($recipient))->handle(app(CrmMailer::class));
        $this->assertNotNull($recipient->fresh()->sent_at);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());

        $mail = new CertificateMail($recipient->fresh());
        $this->assertStringContainsString('Taller de fotografía', $mail->renderedSubject());
        $this->assertStringContainsString($recipient->code, $mail->render());
        $this->assertCount(1, $mail->attachments());
        $this->assertStringStartsWith('%PDF', $mail->pdf());
    }

    public function test_the_public_page_verifies_a_code_without_showing_private_data(): void
    {
        $certificate = $this->certificate();
        $this->seat('Ana Pérez', checkedIn: true);
        $recipient = app(CertificateAudience::class)->sync($certificate)->sole();

        $this->get('/certificados/verificar/'.strtolower($recipient->code))
            ->assertOk()->assertSee('Certificado válido')->assertSee('Ana Pérez')->assertSee('Taller de fotografía')
            ->assertDontSee($recipient->document_number)->assertDontSee('p@example.com');
        $this->get('/certificados/verificar/CERT-NOEXISTE22')->assertOk()->assertSee('No encontramos este certificado');

        $pdf = $this->get("/certificados/verificar/{$recipient->code}/pdf");
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_sent_certificate_cannot_be_removed(): void
    {
        $certificate = $this->certificate();
        $this->seat('Ana Pérez', checkedIn: true);
        app(CertificateAudience::class)->sync($certificate)->sole()->update(['sent_at' => now()]);

        $this->actingAs($this->staff())->delete("/certificados/{$certificate->id}")->assertSessionHas('error');
        $this->actingAs($this->staff())->delete("/certificados/categorias/{$certificate->certificate_category_id}")->assertSessionHas('error');
        $this->assertSame(1, Certificate::count());
        $this->assertSame(1, CertificateRecipient::count());
    }

    public function test_the_mail_editor_previews_with_sample_data(): void
    {
        $this->actingAs($this->staff(Roles::MARKETING))->get('/certificados/correo')->assertOk();
        $this->actingAs($this->staff(Roles::MARKETING))->postJson('/certificados/correo/vista-previa', [
            'subject' => 'Certificado de {{actividad}}', 'body' => "Hola {{nombre}}\n\n{{certificado}}",
        ])->assertOk()->assertJsonPath('subject', 'Certificado de Conferencias Colombia Birdfair')->assertSee('Ver mi certificado');
    }
}
