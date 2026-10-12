<?php

namespace Tests\Feature\CheckIn;

use App\Jobs\SendCheckinQr;
use App\Mail\CheckinQrMail;
use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\EventActivity;
use App\Models\MailSetting;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Models\User;
use App\Services\Mail\CrmMailer;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2027-09-30';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        // 7:30 in Bogotá on the first day of the 2027 fair.
        Carbon::setTestNow(Carbon::parse(self::DAY.' 12:30:00', 'UTC'));
    }

    private function staff(string $role): User
    {
        return tap(User::factory()->create())->assignRole($role);
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: string, 3?: string}>  $activities  [name, type, date, status]
     */
    private function participant(string $name, string $document, array $activities, string $orderStatus = 'processing', ?string $email = 'p@example.com'): RegistrationParticipant
    {
        $this->seq++;
        $registration = Registration::create([
            'wc_order_id' => 1000 + $this->seq, 'number' => (string) (1000 + $this->seq), 'status' => $orderStatus, 'currency' => 'COP',
            'total' => 100000, 'billing' => ['email' => 'buyer@example.com'],
        ]);
        $participant = RegistrationParticipant::create([
            'registration_id' => $registration->id, 'evr_order_participant_id' => $this->seq, 'evr_participant_id' => $this->seq,
            'full_name' => $name, 'email' => $email, 'document_type' => 'cc', 'document_number' => $document,
        ]);
        foreach ($activities as $i => $a) {
            RegistrationActivity::create([
                'registration_participant_id' => $participant->id, 'evr_assignment_id' => $this->seq * 100 + $i,
                'evr_activity_id' => crc32($a[0]) % 1000, 'name' => $a[0], 'type' => $a[1], 'event_date' => $a[2] ?? self::DAY,
                'status' => $a[3] ?? 'confirmed',
            ]);
        }

        return $participant->fresh();
    }

    public function test_participants_get_a_readable_unique_code(): void
    {
        $a = $this->participant('Ana Pérez', '1020304', []);
        $b = $this->participant('Luis Gil', '555666', []);

        $this->assertMatchesRegularExpression('/^CBF-[A-HJ-NP-Z2-9]{8}$/', $a->checkin_code);
        $this->assertNotSame($a->checkin_code, $b->checkin_code);
        $this->assertSame($a->checkin_code, RegistrationParticipant::codeFrom('https://event.colombiabirdfair.com/x/'.strtolower($a->checkin_code)));
        $this->assertNull(RegistrationParticipant::codeFrom('1020304'));
    }

    public function test_entrance_desk_finds_by_document_with_dots_and_only_sees_entrance_activities(): void
    {
        $this->participant('Ana Pérez', '1.020.304', [['Conferencias', 'conference'], ['Salida Km 18', 'birding', '2027-10-01']]);
        $this->participant('Intento sin pagar', '777888', [['Conferencias', 'conference']], 'failed');

        $this->actingAs($this->staff(Roles::REGISTRATION))
            ->get('/checkin?q=1020304')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('checkin/index')
                ->where('mode', 'entrada')
                ->where('modes', ['entrada'])
                ->where('date', self::DAY)
                ->where('results.method', 'documento')
                ->has('results.participants', 1)
                ->where('results.participants.0.name', 'Ana Pérez')
                ->has('results.participants.0.activities', 1)
                ->where('results.participants.0.activities.0.name', 'Conferencias')
                ->where('results.participants.0.activities.0.on_date', true));

        $this->get('/checkin?q=777888')->assertInertia(fn (Assert $page) => $page->has('results.participants', 0));
    }

    public function test_qr_code_and_name_search(): void
    {
        $p = $this->participant('Ana Pérez', '1020304', [['Conferencias', 'conference']]);
        $this->actingAs($this->staff(Roles::REGISTRATION));

        $this->get('/checkin?q='.urlencode($p->checkin_code))
            ->assertInertia(fn (Assert $page) => $page->where('results.method', 'qr')->where('results.participants.0.id', $p->id));

        $this->get('/checkin?q=p%C3%A9rez')
            ->assertInertia(fn (Assert $page) => $page->where('results.method', 'busqueda')->where('results.participants.0.id', $p->id));
    }

    public function test_check_in_records_arrival_once(): void
    {
        EventActivity::create(['evr_id' => crc32('Conferencias') % 1000, 'type' => 'conference', 'name' => 'Conferencias', 'event_date' => self::DAY,
            'price' => 0, 'status' => 'active']);
        $p = $this->participant('Ana Pérez', '1020304', [['Conferencias', 'conference'], ['Taller de fotografía', 'workshop']]);
        $ids = $p->activities->pluck('id')->all();
        $desk = $this->staff(Roles::REGISTRATION);

        $this->actingAs($desk)->from('/checkin')
            ->post('/checkin', ['modo' => 'entrada', 'participant' => $p->id, 'activities' => $ids, 'method' => 'documento'])
            ->assertRedirect('/checkin')
            ->assertSessionHas('success', 'Ana Pérez: ingreso registrado.');

        $this->assertSame(2, CheckIn::count());
        $this->assertSame([$desk->id, 'documento'], [CheckIn::first()->user_id, CheckIn::first()->method]);

        $this->from('/checkin')
            ->post('/checkin', ['modo' => 'entrada', 'participant' => $p->id, 'activities' => $ids, 'method' => 'qr'])
            ->assertSessionHas('error', 'Ana Pérez ya había ingresado.');
        $this->assertSame(2, CheckIn::count());

        $this->get('/checkin')->assertInertia(fn (Assert $page) => $page
            ->where('activities.0.checked_in', 1)
            ->where('activities.0.expected', 1));
    }

    public function test_check_in_is_refused_for_unpaid_orders_cancelled_activities_and_other_desks(): void
    {
        $unpaid = $this->participant('Pedro Gil', '111222', [['Conferencias', 'conference']], 'pending');
        $mixed = $this->participant('Ana Pérez', '1020304', [['Conferencias', 'conference', self::DAY, 'cancelled'], ['Salida Km 18', 'birding']]);
        $other = $this->participant('Luis Gil', '333444', [['Conferencias', 'conference']]);
        $this->actingAs($this->staff(Roles::REGISTRATION))->from('/checkin');

        $this->post('/checkin', ['modo' => 'entrada', 'participant' => $unpaid->id, 'activities' => [$unpaid->activities[0]->id], 'method' => 'busqueda'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no está pagado'));
        $this->post('/checkin', ['modo' => 'entrada', 'participant' => $mixed->id, 'activities' => [$mixed->activities[0]->id], 'method' => 'busqueda'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'ya no está activa'));
        $this->post('/checkin', ['modo' => 'entrada', 'participant' => $mixed->id, 'activities' => [$mixed->activities[1]->id], 'method' => 'busqueda'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no se registra en este punto'));
        $this->post('/checkin', ['modo' => 'entrada', 'participant' => $mixed->id, 'activities' => [$other->activities[0]->id], 'method' => 'busqueda'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no pertenece'));
        $this->post('/checkin', ['modo' => 'salidas', 'participant' => $mixed->id, 'activities' => [$mixed->activities[1]->id], 'method' => 'busqueda'])
            ->assertSessionHasErrors('modo');

        $this->assertSame(0, CheckIn::count());
    }

    public function test_trip_desk_sees_only_outings_with_a_roster(): void
    {
        EventActivity::create(['evr_id' => crc32('Salida Km 18') % 1000, 'type' => 'birding', 'name' => 'Salida Km 18', 'event_date' => self::DAY,
            'price' => 0, 'status' => 'active']);
        $ana = $this->participant('Ana Pérez', '1020304', [['Salida Km 18', 'birding'], ['Conferencias', 'conference']]);
        $this->participant('Luis Gil', '333444', [['Salida Km 18', 'birding']]);
        $this->participant('Sin pagar', '999', [['Salida Km 18', 'birding']], 'pending');
        $trips = $this->staff(Roles::TRIP_REGISTRATION);
        $tripId = crc32('Salida Km 18') % 1000;

        $this->actingAs($trips)->get('/dashboard')->assertRedirect('/checkin');
        $this->get('/registrations')->assertForbidden();
        $this->get('/checkin?modo=entrada')->assertSessionHasErrors('modo');

        $this->get("/checkin?salida={$tripId}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('mode', 'salidas')
                ->where('trip', $tripId)
                ->has('roster', 2)
                ->where('roster.0.name', 'Ana Pérez')
                ->where('activities.0.expected', 2));

        $tripAssignment = $ana->activities->firstWhere('type', 'birding');
        $this->from('/checkin')->post('/checkin', ['modo' => 'salidas', 'participant' => $ana->id, 'activities' => [$tripAssignment->id], 'method' => 'lista'])
            ->assertSessionHas('success');
        $this->assertSame($tripAssignment->id, CheckIn::sole()->registration_activity_id);
    }

    public function test_undo_is_limited_to_its_author_or_a_manager(): void
    {
        $p = $this->participant('Ana Pérez', '1020304', [['Conferencias', 'conference']]);
        $author = $this->staff(Roles::REGISTRATION);
        $this->actingAs($author)->post('/checkin', ['modo' => 'entrada', 'participant' => $p->id, 'activities' => [$p->activities[0]->id], 'method' => 'qr']);
        $checkIn = CheckIn::sole();

        $this->actingAs($this->staff(Roles::REGISTRATION))->delete("/checkin/{$checkIn->id}")->assertForbidden();
        $this->actingAs($this->staff(Roles::TRIP_REGISTRATION))->delete("/checkin/{$checkIn->id}")->assertForbidden();
        $this->actingAs($this->staff(Roles::REGISTRATION_ADMIN))->from('/checkin')->delete("/checkin/{$checkIn->id}")->assertSessionHas('success');

        $this->assertSame(0, CheckIn::count());
        $this->assertSame('checkin.undone', AuditLog::sole()->action);
    }

    public function test_qr_image_and_bulk_send_are_paced_and_not_repeated(): void
    {
        Queue::fake();
        $ana = $this->participant('Ana Pérez', '1020304', [['Conferencias', 'conference']]);
        $this->participant('Luis Gil', '333444', [['Conferencias', 'conference']], 'processing', null); // goes to the buyer
        $this->participant('Antigua', '555', [['Conferencias', 'conference', '2026-10-01']]); // past edition
        $this->participant('Sin pagar', '999', [['Conferencias', 'conference']], 'pending');
        config(['crm.qr_emails_per_hour' => 60]);

        $this->actingAs($this->staff(Roles::REGISTRATION))->get("/checkin/qr/{$ana->id}")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->post('/checkin/qr-pending')->assertForbidden();

        $this->actingAs($this->staff(Roles::REGISTRATION_ADMIN))->from('/checkin')->post('/checkin/qr-pending')
            ->assertSessionHas('success', 'Enviando 2 códigos QR, 60 por hora.');
        Queue::assertPushed(SendCheckinQr::class, 2);
        Queue::assertPushed(SendCheckinQr::class, fn (SendCheckinQr $job) => $job->participant->full_name === 'Luis Gil' && now()->diffInSeconds($job->delay) >= 59);

        $this->from('/checkin')->post('/checkin/qr-pending')->assertSessionHas('error', 'No hay códigos QR pendientes por enviar.');
        Queue::assertPushed(SendCheckinQr::class, 2);
    }

    public function test_qr_job_mails_the_participant_or_the_buyer_once(): void
    {
        $p = $this->participant('Luis Gil', '333444', [['Conferencias', 'conference']], 'processing', null);
        $mailer = Mockery::mock(Mailer::class);
        $pending = Mockery::mock(PendingMail::class);
        $mailer->shouldReceive('to')->once()->with('buyer@example.com', 'Luis Gil')->andReturn($pending);
        $pending->shouldReceive('send')->once()->with(Mockery::type(CheckinQrMail::class));
        $crm = Mockery::mock(CrmMailer::class);
        $crm->shouldReceive('for')->with(MailSetting::PROFILE_TRANSACTIONAL)->andReturn($mailer);

        (new SendCheckinQr($p))->handle($crm);
        $this->assertNotNull($p->fresh()->qr_sent_at);

        // A second bulk job for the same person does nothing.
        (new SendCheckinQr($p->fresh()))->handle($crm);
    }

    public function test_qr_mail_renders_the_code(): void
    {
        $p = $this->participant('Ana Pérez', '1020304', [['Conferencias', 'conference']]);

        $html = (new CheckinQrMail($p))->render();

        $this->assertStringContainsString($p->checkin_code, $html);
        $this->assertStringContainsString('Conferencias', $html);
    }

    public function test_admin_manages_staff_accounts(): void
    {
        $admin = $this->staff(Roles::ADMIN);
        $this->actingAs($admin)->get('/settings/users')->assertOk();

        $this->from('/settings/users')->post('/settings/users', [
            'name' => 'Mesa 1', 'email' => 'Mesa1@Example.com', 'password' => 'entrada2027', 'roles' => [Roles::REGISTRATION],
        ])->assertSessionHas('success');
        $desk = User::where('email', 'mesa1@example.com')->sole();
        $this->assertTrue($desk->hasRole(Roles::REGISTRATION));

        $this->put("/settings/users/{$desk->id}", ['name' => 'Mesa 1', 'roles' => [Roles::TRIP_REGISTRATION]]);
        $this->assertSame([Roles::TRIP_REGISTRATION], $desk->fresh()->getRoleNames()->all());

        $this->from('/settings/users')->put("/settings/users/{$admin->id}", ['name' => 'Yo', 'roles' => [Roles::ANALYST]])
            ->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->hasRole(Roles::ADMIN));

        $this->actingAs($desk)->get('/settings/users')->assertForbidden();
    }
}
