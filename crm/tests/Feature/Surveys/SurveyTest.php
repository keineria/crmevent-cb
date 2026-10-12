<?php

namespace Tests\Feature\Surveys;

use App\Jobs\SendSurvey;
use App\Mail\SurveyMail;
use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\EventActivity;
use App\Models\MailSetting;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Models\Survey;
use App\Models\SurveyForm;
use App\Models\User;
use App\Services\Mail\CrmMailer;
use App\Services\Surveys\SurveyDispatcher;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SurveyTest extends TestCase
{
    use RefreshDatabase;

    private const OUTING = 40;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        // The migration turned every form on at "now" (2026); the fair below is in 2027.
        Carbon::setTestNow(Carbon::parse('2027-10-02 20:00:00', 'UTC')); // 15:00 in Bogotá
        SurveyForm::query()->update(['enabled_at' => '2027-09-01 00:00:00']);

        // Outing ends at 12:00 Bogotá (17:00 UTC), so with the default hour of delay its survey is due at 18:00 UTC.
        EventActivity::create(['evr_id' => self::OUTING, 'type' => 'birding', 'name' => 'Salida Río Blanco', 'event_date' => '2027-10-02', 'start_time' => '05:00:00', 'end_time' => '12:00:00', 'price' => 300000, 'status' => 'active']);
        EventActivity::create(['evr_id' => 41, 'type' => 'birding', 'name' => 'Salida de la tarde', 'event_date' => '2027-10-02', 'start_time' => '14:00:00', 'end_time' => '18:00:00', 'price' => 300000, 'status' => 'active']);
    }

    private function staff(string $role = Roles::ADMIN): User
    {
        return tap(User::factory()->create())->assignRole($role);
    }

    private function seat(string $name, bool $checkedIn, ?string $email = 'p@example.com', int $activity = self::OUTING): RegistrationParticipant
    {
        $this->seq++;
        $registration = Registration::create([
            'wc_order_id' => 1000 + $this->seq, 'number' => (string) (1000 + $this->seq), 'status' => 'processing', 'currency' => 'COP',
            'total' => 300000, 'billing' => ['email' => $email ? 'buyer@example.com' : null],
        ]);
        $participant = RegistrationParticipant::create([
            'registration_id' => $registration->id, 'evr_order_participant_id' => $this->seq, 'evr_participant_id' => $this->seq,
            'full_name' => $name, 'email' => $email, 'document_type' => 'cc', 'document_number' => (string) (5000 + $this->seq),
        ]);
        $seat = RegistrationActivity::create([
            'registration_participant_id' => $participant->id, 'evr_assignment_id' => $this->seq, 'evr_activity_id' => $activity,
            'name' => 'Salida', 'type' => 'birding', 'event_date' => '2027-10-02', 'status' => 'confirmed',
        ]);
        if ($checkedIn) {
            CheckIn::create([
                'registration_activity_id' => $seat->id, 'registration_participant_id' => $participant->id, 'evr_activity_id' => $activity,
                'method' => CheckIn::METHOD_QR, 'checked_in_at' => '2027-10-02 10:00:00',
            ]);
        }

        return $participant;
    }

    private function activeTransactional(): void
    {
        $setting = MailSetting::forProfile(MailSetting::PROFILE_TRANSACTIONAL);
        $setting->fill(['from_address' => 'hola@colombiabirdfair.com', 'from_name' => 'Colombia Birdfair']);
        $setting->active_config = ['transport' => 'array'];
        $setting->save();
    }

    public function test_the_survey_goes_out_on_its_own_to_people_checked_in_once_the_activity_ends(): void
    {
        Queue::fake();
        $this->activeTransactional();
        $this->seat('Ana Ruiz', checkedIn: true);
        $this->seat('Beto Gil', checkedIn: false);
        $this->seat('Caro Paz', checkedIn: true, email: null);
        $this->seat('Dani Mora', checkedIn: true, activity: 41);

        $this->artisan('surveys:send')->assertSuccessful();

        // Only the outing that already ended; the afternoon one is still running.
        $survey = Survey::sole();
        $this->assertSame(self::OUTING, $survey->evr_activity_id);
        $this->assertSame(Survey::SOURCE_AUTO, $survey->source);
        $this->assertSame(SurveyForm::DEFAULTS['birding']['questions'], $survey->questions);
        $this->assertSame(['Ana Ruiz', 'Caro Paz'], $survey->invitations()->orderBy('full_name')->pluck('full_name')->all());
        Queue::assertPushed(SendSurvey::class, 1);
        Queue::assertPushed(SendSurvey::class, fn (SendSurvey $job) => $job->invitation->full_name === 'Ana Ruiz');

        // Running again mails nobody twice, but reaches whoever is checked in late.
        $this->seat('Eva Sol', checkedIn: true);
        $this->artisan('surveys:send');
        Queue::assertPushed(SendSurvey::class, 2);
    }

    public function test_nothing_goes_out_when_off_too_late_before_it_was_turned_on_or_without_mail(): void
    {
        Queue::fake();
        $this->seat('Ana Ruiz', checkedIn: true);

        $this->artisan('surveys:send');
        $this->assertSame(0, Survey::count(), 'no verified mailbox');

        $this->activeTransactional();
        SurveyForm::where('activity_type', 'birding')->update(['enabled' => false]);
        $this->artisan('surveys:send');
        $this->assertSame(0, Survey::count(), 'form off');

        SurveyForm::where('activity_type', 'birding')->update(['enabled' => true, 'enabled_at' => '2027-10-02 19:00:00']);
        $this->artisan('surveys:send');
        $this->assertSame(0, Survey::count(), 'ended before it was turned on');

        SurveyForm::where('activity_type', 'birding')->update(['enabled_at' => '2027-09-01 00:00:00']);
        Carbon::setTestNow(Carbon::parse('2027-10-06 20:00:00', 'UTC'));
        $this->artisan('surveys:send');
        $this->assertSame(0, Survey::count(), 'more than three days late');
    }

    public function test_a_past_activity_is_sent_by_hand_and_the_toggle_never_reaches_past_ones(): void
    {
        Queue::fake();
        $this->activeTransactional();
        $this->seat('Ana Ruiz', checkedIn: true);
        $admin = $this->staff(Roles::MARKETING);

        $this->actingAs($admin)->post('/encuestas/actividad/41/enviar')->assertSessionHas('error');
        $this->actingAs($admin)->post('/encuestas/actividad/'.self::OUTING.'/enviar')->assertSessionHas('success');
        $this->assertSame(Survey::SOURCE_MANUAL, Survey::sole()->source);
        Queue::assertPushed(SendSurvey::class, 1);
        $this->assertTrue(AuditLog::where('action', 'survey.sent')->exists());

        $this->actingAs($admin)->put('/encuestas/preguntas/workshop/activa', ['enabled' => false])->assertSessionHas('success');
        $this->actingAs($admin)->put('/encuestas/preguntas/workshop/activa', ['enabled' => true]);
        $this->assertTrue(SurveyForm::where('activity_type', 'workshop')->sole()->enabled_at->eq(now()));
    }

    public function test_questions_are_edited_and_keep_their_keys(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->get('/encuestas')->assertInertia(fn (Assert $page) => $page->component('surveys/index')->has('forms', 4)->has('activities', 2));
        $this->actingAs($admin)->get('/encuestas/preguntas/birding')->assertInertia(fn (Assert $page) => $page->component('surveys/form'));

        $this->actingAs($admin)->put('/encuestas/preguntas/birding', [
            'title' => 'Tu salida', 'intro' => null, 'enabled' => true, 'delay_minutes' => 120, 'require_check_in' => true,
            'questions' => [
                ['key' => 'guia', 'type' => 'rating', 'label' => 'El guía', 'required' => true],
                ['type' => 'choice', 'label' => '¿Volverías?', 'required' => false, 'options' => ['Sí', 'No', '']],
                ['type' => 'choice', 'label' => '¿Volverías?', 'required' => false, 'options' => ['Tal vez', 'Sí']],
            ],
        ])->assertSessionHasNoErrors();

        $form = SurveyForm::where('activity_type', 'birding')->sole();
        $this->assertSame(['guia', 'volverias', 'volverias_2'], array_column($form->questions, 'key'));
        $this->assertSame(['Sí', 'No'], $form->questions[1]['options']);
        $this->assertSame(120, $form->delay_minutes);

        $this->actingAs($admin)->put('/encuestas/preguntas/birding', [
            'title' => 'X', 'enabled' => true, 'delay_minutes' => 60, 'require_check_in' => true,
            'questions' => [['type' => 'choice', 'label' => 'Una sola', 'required' => true, 'options' => ['A']]],
        ])->assertSessionHasErrors('questions.0.options');

        $this->actingAs($this->staff(Roles::ANALYST))->get('/encuestas')->assertForbidden();
        $this->actingAs($this->staff(Roles::REGISTRATION))->get('/reports/encuestas')->assertForbidden();
    }

    public function test_a_person_answers_once_and_results_show_in_reports(): void
    {
        $this->activeTransactional();
        $ana = $this->seat('Ana Ruiz', checkedIn: true);
        $beto = $this->seat('Beto Gil', checkedIn: true);
        $survey = app(SurveyDispatcher::class)->open(EventActivity::where('evr_id', self::OUTING)->sole(), SurveyForm::where('activity_type', 'birding')->sole(), Survey::SOURCE_AUTO);
        [$a, $b] = app(SurveyDispatcher::class)->sync($survey)->sortBy('full_name')->values()->all();
        $a->update(['sent_at' => now()]);
        $b->update(['sent_at' => now()]);

        $this->get("/encuesta/{$a->token}")->assertOk()->assertSee('Salida Río Blanco')->assertSee('Enviar respuestas');
        $this->assertNotNull($a->fresh()->opened_at);

        $this->post("/encuesta/{$a->token}", ['q' => ['satisfaccion' => 9]])->assertSessionHasErrors(['q.satisfaccion', 'q.guia', 'q.recomendacion']);

        $answers = ['satisfaccion' => 5, 'guia' => 4, 'avistamiento' => 5, 'recomendacion' => 10, 'comentarios' => '¡Vimos el tucán!'];
        $this->post("/encuesta/{$a->token}", ['q' => $answers])->assertRedirect("/encuesta/{$a->token}");
        $this->get("/encuesta/{$a->token}")->assertSee('Gracias, Ana');
        $this->post("/encuesta/{$b->token}", ['q' => ['satisfaccion' => 3, 'guia' => 3, 'avistamiento' => 2, 'recomendacion' => 5]]);
        // A second answer is ignored.
        $this->post("/encuesta/{$a->token}", ['q' => ['satisfaccion' => 1, 'guia' => 1, 'avistamiento' => 1, 'recomendacion' => 0]]);
        $this->assertSame(5, $a->fresh()->answers['satisfaccion']);

        $this->get('/encuesta/'.str_repeat('x', 40))->assertOk()->assertSee('No encontramos esta encuesta');

        $analyst = $this->staff(Roles::ANALYST);
        $this->actingAs($analyst)->get('/reports/encuestas')->assertInertia(fn (Assert $page) => $page
            ->component('reports/surveys')
            ->where('year', 2027)
            ->where('totals.responded', 2)
            ->where('totals.rate', 100)
            ->where('totals.satisfaction', 3.7) // (5+4+5+3+3+2) / 6
            ->where('totals.nps', 0) // one promoter, one detractor
            ->where('canManage', false));

        $this->actingAs($analyst)->get("/reports/encuestas/{$survey->id}")->assertInertia(fn (Assert $page) => $page
            ->component('reports/survey')
            ->where('questions.1.average', 3.5)
            ->where('questions.6.comments.0.text', '¡Vimos el tucán!')
            ->missing('questions.6.comments.0.name')
            ->where('people.0.responded', true));

        $csv = $this->actingAs($analyst)->get("/reports/encuestas/{$survey->id}/csv")->streamedContent();
        $this->assertStringContainsString('¡Vimos el tucán!', $csv);
        $this->assertStringNotContainsString('Ana Ruiz', $csv);

        $this->actingAs($analyst)->get('/reports')->assertInertia(fn (Assert $page) => $page->where('surveys.responded', 2));
    }

    public function test_the_email_carries_the_button_to_the_persons_survey(): void
    {
        $this->activeTransactional();
        $this->seat('Ana Ruiz', checkedIn: true);
        $survey = app(SurveyDispatcher::class)->open(EventActivity::where('evr_id', self::OUTING)->sole(), SurveyForm::where('activity_type', 'birding')->sole(), Survey::SOURCE_AUTO);
        $invitation = app(SurveyDispatcher::class)->sync($survey)->sole();
        $this->mock(CrmMailer::class)->shouldReceive('for')->with(MailSetting::PROFILE_TRANSACTIONAL)->andReturn(Mail::mailer('array'));

        (new SendSurvey($invitation))->handle(app(CrmMailer::class));
        $this->assertNotNull($invitation->fresh()->sent_at);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());

        $html = (new SurveyMail($invitation->fresh()))->render();
        $this->assertStringContainsString($invitation->url(), $html);
        $this->assertStringContainsString('Responder la encuesta', $html);
        $this->assertSame('¿Cómo te fue en Salida Río Blanco?', (new SurveyMail($invitation))->renderedSubject());

        $admin = $this->staff();
        $this->actingAs($admin)->put('/encuestas/correo', ['subject' => 'Hola', 'body' => 'Sin botón'])->assertSessionHasErrors('body');
        $this->actingAs($admin)->put('/encuestas/correo', ['subject' => 'Hola', 'body' => "Responde\n\n{{encuesta}}"])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/encuestas/correo/vista-previa', ['subject' => 'x {{actividad}}', 'body' => '{{encuesta}}'])
            ->assertOk()->assertJsonPath('subject', 'x Salida Reserva Río Blanco');
    }
}
