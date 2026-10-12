<?php

namespace Tests\Feature\Reports;

use App\Models\CheckIn;
use App\Models\Contact;
use App\Models\ContactEdition;
use App\Models\EventActivity;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2027-08-20 15:00:00', 'UTC'));

        EventActivity::create(['evr_id' => 10, 'type' => 'birding', 'name' => 'Salida Km 18', 'event_date' => '2027-10-01', 'price' => 300000, 'capacity_total' => 20, 'confirmed_count' => 2, 'status' => 'active']);
        EventActivity::create(['evr_id' => 11, 'type' => 'birding', 'name' => 'Salida Sonso', 'event_date' => '2027-10-02', 'price' => 300000, 'capacity_total' => 15, 'confirmed_count' => 1, 'status' => 'active']);
        EventActivity::create(['evr_id' => 20, 'type' => 'workshop', 'name' => 'Taller de fotografía', 'event_date' => '2027-10-01', 'price' => 80000, 'capacity_total' => 10, 'status' => 'active']);
        EventActivity::create(['evr_id' => 21, 'type' => 'workshop', 'name' => 'Taller de dibujo', 'event_date' => '2027-10-02', 'price' => 0, 'capacity_total' => 30, 'status' => 'active']);
    }

    private function staff(string $role): User
    {
        return tap(User::factory()->create())->assignRole($role);
    }

    /**
     * @param  list<int>  $activities  evr ids
     */
    private function participant(string $name, array $activities, array $extra = [], string $status = 'processing', string $orderedAt = '2027-08-10 15:00:00', int $year = 2027, array $billing = ['country_name' => 'Colombia', 'city' => 'cali']): RegistrationParticipant
    {
        $this->seq++;
        $registration = Registration::create([
            'wc_order_id' => 1000 + $this->seq, 'number' => (string) (1000 + $this->seq), 'status' => $status, 'currency' => 'COP',
            'total' => 100000, 'billing' => $billing + ['phone' => '3001234567'], 'ordered_at' => $orderedAt, 'edition_year' => $year,
        ]);
        $participant = RegistrationParticipant::create([
            'registration_id' => $registration->id, 'evr_order_participant_id' => $this->seq, 'evr_participant_id' => $this->seq,
            'full_name' => $name, 'document_type' => 'cc', 'document_number' => (string) (5000 + $this->seq),
        ] + $extra);
        foreach ($activities as $i => $id) {
            $activity = EventActivity::where('evr_id', $id)->first();
            RegistrationActivity::create([
                'registration_participant_id' => $participant->id, 'evr_assignment_id' => $this->seq * 100 + $i, 'evr_activity_id' => $id,
                'name' => $activity->name, 'type' => $activity->type, 'event_date' => $activity->event_date, 'status' => 'confirmed',
            ]);
        }

        return $participant->fresh();
    }

    private function checkIn(RegistrationParticipant $p, int $evrId): void
    {
        $assignment = $p->activities()->where('evr_activity_id', $evrId)->firstOrFail();
        CheckIn::create(['registration_activity_id' => $assignment->id, 'registration_participant_id' => $p->id, 'evr_activity_id' => $evrId, 'method' => CheckIn::METHOD_QR, 'checked_in_at' => now()]);
    }

    public function test_only_admins_registration_admins_and_analysts_open_reports(): void
    {
        $this->get('/reports')->assertRedirect('/login');

        foreach ([Roles::ADMIN, Roles::REGISTRATION_ADMIN, Roles::ANALYST] as $role) {
            $this->actingAs($this->staff($role))->get('/reports')->assertOk();
        }
        foreach ([Roles::REGISTRATION, Roles::TRIP_REGISTRATION, Roles::MARKETING] as $role) {
            $this->actingAs($this->staff($role))->get('/reports')->assertForbidden();
            $this->actingAs($this->staff($role))->getJson('/reports/salidas?anio=2027')->assertForbidden();
        }
    }

    public function test_summary_counts_only_paid_participants_of_the_edition(): void
    {
        $ana = $this->participant('Ana', [10, 20], ['shirt_size' => 'M']);
        $this->participant('Luis', [10]);
        $this->participant('Abandonó', [11], status: 'failed');
        $this->participant('Otra feria', [11], year: 2026);
        $this->checkIn($ana, 10);

        $contact = Contact::factory()->create();
        $ana->update(['contact_id' => $contact->id]);
        ContactEdition::create(['contact_id' => $contact->id, 'year' => 2025, 'role' => ContactEdition::ROLE_ATTENDEE]);

        $this->actingAs($this->staff(Roles::ANALYST))
            ->get('/reports')
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/index')
                ->where('filters.anio', 2027)
                ->where('editions', [2027, 2026])
                ->where('summary.participants', 2)
                ->where('summary.activated', 1)
                ->where('summary.returning', 1)
                ->where('summary.trips', 2)
                ->where('timeline.0', ['date' => '2027-08-10', 'participants' => 2]));
    }

    public function test_trip_cards_show_people_seats_and_check_ins(): void
    {
        $ana = $this->participant('Ana', [10]);
        $this->participant('Luis', [10, 11]);
        $this->checkIn($ana, 10);

        $cards = $this->actingAs($this->staff(Roles::ANALYST))->getJson('/reports/salidas?anio=2027')->assertOk()->json('cards');

        $this->assertSame(['10', '11'], array_column($cards, 'key'));
        $this->assertSame(2, $cards[0]['count']);
        $this->assertSame(1, $cards[0]['checked_in']);
        $this->assertSame(18, $cards[0]['available']);
        $this->assertSame('2027-10-01', $cards[0]['date']);
    }

    public function test_paid_and_free_workshops_are_separate(): void
    {
        $this->participant('Ana', [20, 21]);
        $user = $this->staff(Roles::ADMIN);

        $this->actingAs($user)->getJson('/reports/talleres-pagos?anio=2027')->assertJsonPath('cards.0.label', 'Taller de fotografía')->assertJsonCount(1, 'cards');
        $this->actingAs($user)->getJson('/reports/talleres-gratuitos?anio=2027')->assertJsonPath('cards.0.label', 'Taller de dibujo')->assertJsonCount(1, 'cards');
    }

    public function test_group_cards_list_people_with_contact_details(): void
    {
        $this->participant('Ana', [10], ['shirt_size' => 'm', 'whatsapp' => '+57 311']);
        $this->participant('Luis', [10], ['shirt_size' => 'L']);
        $this->participant('Sin talla', [10]);
        $user = $this->staff(Roles::ANALYST);

        $this->actingAs($user)->getJson('/reports/tallas?anio=2027')
            ->assertJsonPath('cards.0.key', 'M')
            ->assertJsonPath('cards.1.key', 'L')
            ->assertJsonPath('cards.2.label', 'Sin dato');

        $this->actingAs($user)->getJson('/reports/tallas/personas?anio=2027&tarjeta=M')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('people.0.name', 'Ana')
            ->assertJsonPath('people.0.phone', '+57 311')
            ->assertJsonPath('people.0.country', 'Colombia')
            ->assertJsonPath('people.0.city', 'Cali');

        $this->actingAs($user)->getJson('/reports/ciudades/personas?anio=2027&tarjeta=Cali')->assertJsonPath('total', 3);
    }

    public function test_date_range_uses_bogota_days(): void
    {
        // 23:30 in Bogotá on Aug 14 is already Aug 15 in UTC.
        $this->participant('Tarde', [10], orderedAt: '2027-08-15 04:30:00');
        $this->participant('Después', [10], orderedAt: '2027-08-16 15:00:00');

        $this->actingAs($this->staff(Roles::ANALYST))
            ->get('/reports?anio=2027&desde=2027-08-14&hasta=2027-08-14')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.participants', 1)
                ->where('timeline', [['date' => '2027-08-14', 'participants' => 1]]));
    }

    public function test_people_export_as_csv(): void
    {
        $this->participant('Ana Pérez', [10]);

        $response = $this->actingAs($this->staff(Roles::ANALYST))->get('/reports/salidas/csv?anio=2027&tarjeta=10');

        $response->assertOk()->assertDownload('salidas-salida-km-18-2027.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Ana Pérez', $csv);
        $this->assertStringContainsString('Activó registro', $csv);
    }

    public function test_unknown_sections_and_bad_ranges_are_rejected(): void
    {
        $user = $this->staff(Roles::ADMIN);

        $this->actingAs($user)->getJson('/reports/finanzas?anio=2027')->assertNotFound();
        $this->actingAs($user)->getJson('/reports/salidas?anio=2027&desde=2027-08-10&hasta=2027-08-01')->assertUnprocessable();
        $this->actingAs($user)->getJson('/reports/salidas/personas?anio=2027')->assertUnprocessable();
    }

    public function test_overview_summarises_each_category(): void
    {
        $ana = $this->participant('Ana', [10, 20], ['shirt_size' => 'M']);
        $this->participant('Luis', [10, 11], billing: ['country_name' => 'Ecuador', 'city' => 'Quito']);
        $this->checkIn($ana, 10);

        $this->actingAs($this->staff(Roles::ANALYST))
            ->get('/reports?anio=2027')
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories.0.key', 'salidas')
                ->where('categories.0.summary.sold', 3)
                ->where('categories.0.summary.available', 18 + 14)
                ->where('categories.0.summary.days.0', ['date' => '2027-10-01', 'sold' => 2, 'available' => 18, 'outings' => 1])
                ->where('categories.1.summary.items.1', ['label' => 'Talleres pagos', 'count' => 1])
                ->where('categories.2.summary.countries', 2)
                ->where('categories.2.summary.top.0', ['label' => 'Colombia', 'count' => 1])
                ->where('categories.4.summary', ['activated' => 1, 'total' => 2]));
    }

    public function test_category_page_lists_its_sections_and_keeps_filters(): void
    {
        $user = $this->staff(Roles::ANALYST);

        $this->actingAs($user)
            ->get('/reports/categoria/demograficos?anio=2027&desde=2027-08-01')
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/category')
                ->where('category.title', 'Demográficos')
                ->where('sections.1', ['key' => 'sexo', 'title' => 'Sexo'])
                ->where('filters.desde', '2027-08-01'));

        $this->actingAs($user)->get('/reports/categoria/finanzas')->assertNotFound();
    }

    public function test_sex_comes_from_the_contact_and_is_normalised(): void
    {
        foreach (['Ana' => 'Mujer', 'Eva' => 'F', 'Luis' => 'masculino', 'Sin' => null] as $name => $gender) {
            $p = $this->participant($name, [10]);
            $p->update(['contact_id' => Contact::factory()->create(['gender' => $gender])->id]);
        }

        $this->actingAs($this->staff(Roles::ANALYST))->getJson('/reports/sexo?anio=2027')
            ->assertJsonPath('cards.0', ['key' => 'Femenino', 'label' => 'Femenino', 'count' => 2, 'total' => 4])
            ->assertJsonPath('cards.1.key', 'Masculino')
            ->assertJsonPath('cards.2.label', 'Sin dato');
    }
}
