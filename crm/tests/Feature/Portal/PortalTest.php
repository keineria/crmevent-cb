<?php

namespace Tests\Feature\Portal;

use App\Models\CheckIn;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private RegistrationParticipant $ana;

    protected function setUp(): void
    {
        parent::setUp();

        // Ana bought twice: conferences in one order, an outing in another.
        $first = Registration::create(['wc_order_id' => 501, 'number' => '501', 'status' => 'completed', 'currency' => 'COP', 'total' => 180000,
            'billing' => ['email' => 'ana@example.com'], 'ordered_at' => '2026-09-01 10:00:00']);
        $this->ana = RegistrationParticipant::create(['registration_id' => $first->id, 'evr_order_participant_id' => 10, 'evr_participant_id' => 1,
            'full_name' => 'Ana Pérez', 'email' => 'Ana@Example.com', 'document_type' => 'cc', 'document_number' => '1.020.304.050',
            'whatsapp' => '+573001112233', 'allergies' => 'Maní', 'shirt_size' => 'M']);
        RegistrationActivity::create(['registration_participant_id' => $this->ana->id, 'evr_assignment_id' => 101, 'evr_activity_id' => 1,
            'name' => 'Conferencias', 'type' => 'conference', 'event_date' => '2027-09-30', 'start_time' => '08:00:00', 'status' => 'confirmed', 'price' => 180000, 'total' => 180000]);
        RegistrationActivity::create(['registration_participant_id' => $this->ana->id, 'evr_assignment_id' => 104, 'evr_activity_id' => 9,
            'name' => 'Retirada', 'type' => 'workshop', 'event_date' => '2027-10-01', 'status' => 'cancelled', 'price' => 50000, 'total' => 50000]);

        $second = Registration::create(['wc_order_id' => 502, 'number' => '502', 'status' => 'processing', 'currency' => 'COP', 'total' => 180000,
            'billing' => ['email' => 'ana@example.com'], 'ordered_at' => '2026-09-10 10:00:00']);
        $again = RegistrationParticipant::create(['registration_id' => $second->id, 'evr_order_participant_id' => 11, 'evr_participant_id' => 1,
            'full_name' => 'Ana Pérez', 'email' => 'ana@example.com', 'document_type' => 'cc', 'document_number' => '1020304050']);
        $trip = RegistrationActivity::create(['registration_participant_id' => $again->id, 'evr_assignment_id' => 100, 'evr_activity_id' => 3,
            'name' => 'Chicoral', 'type' => 'birding', 'event_date' => '2027-10-02', 'start_time' => '05:00:00', 'status' => 'confirmed', 'price' => 180000, 'total' => 180000]);
        CheckIn::create(['registration_activity_id' => $trip->id, 'registration_participant_id' => $again->id, 'evr_activity_id' => 3, 'method' => 'qr', 'checked_in_at' => now()]);

        // An unpaid attempt with another document.
        $unpaid = Registration::create(['wc_order_id' => 503, 'number' => '503', 'status' => 'pending', 'currency' => 'COP', 'total' => 90000]);
        RegistrationParticipant::create(['registration_id' => $unpaid->id, 'evr_order_participant_id' => 12, 'evr_participant_id' => 2,
            'full_name' => 'Luis Gil', 'document_type' => 'cc', 'document_number' => '79765313']);
    }

    /**
     * Answers the sum the page asked.
     */
    private function lookup(string $document, ?string $answer = null): TestResponse
    {
        $this->get('/inscritos');
        [$a, $b] = session('portal_challenge');

        return $this->from('/inscritos')->post('/inscritos/consultar', ['document' => $document, 'human' => $answer ?? (string) ($a + $b)]);
    }

    public function test_the_page_asks_for_the_document_and_a_human_check(): void
    {
        $this->get('/inscritos')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/index')
            ->where('people', [])
            ->where('challenge.type', 'sum')
            ->has('challenge.question'));
    }

    public function test_a_participant_sees_their_paid_registration_read_only(): void
    {
        $this->lookup('1020304050')->assertRedirect('/inscritos');

        $this->get('/inscritos')->assertInertia(fn (Assert $page) => $page
            ->has('people', 1)
            ->where('challenge', null)
            ->where('people.0.name', 'Ana Pérez')
            ->where('people.0.document', 'Cédula de ciudadanía ••••4050')
            ->where('people.0.email', 'a•••@example.com')
            ->where('people.0.orders', ['502', '501'])
            ->has('people.0.activities', 2)
            ->where('people.0.activities.0.name', 'Conferencias')
            ->where('people.0.activities.1.checked_in', true)
            ->where('people.0.days', ['2027-09-30', '2027-10-01', '2027-10-02'])
            ->missing('people.0.code')
            ->missing('people.0.whatsapp')
            ->missing('people.0.allergies'));
    }

    public function test_dots_and_spaces_in_the_document_are_ignored(): void
    {
        $this->lookup(' 1.020.304 050 ')->assertRedirect('/inscritos');
    }

    public function test_a_wrong_answer_is_refused_and_a_new_question_is_asked(): void
    {
        $this->lookup('1020304050', '999')->assertSessionHasErrors('human');

        $this->get('/inscritos')->assertInertia(fn (Assert $page) => $page->where('people', [])->where('challenge.type', 'sum'));
    }

    public function test_unpaid_or_unknown_documents_show_nothing(): void
    {
        $this->lookup('79765313')->assertSessionHas('error');
        $this->lookup('55555555')->assertSessionHas('error');
        $this->get('/inscritos')->assertInertia(fn (Assert $page) => $page->where('people', []));
    }

    public function test_repeated_lookups_of_one_document_are_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->lookup('55555555')->assertSessionHas('error', fn ($m) => str_starts_with($m, 'No encontramos'));
        }

        $this->lookup('55555555')->assertSessionHas('error', 'Demasiadas consultas para este documento. Espera unos minutos e intenta de nuevo.');
    }

    public function test_the_honeypot_stops_bots(): void
    {
        $this->get('/inscritos');
        $this->from('/inscritos')->post('/inscritos/consultar', ['document' => '1020304050', 'human' => '1', 'website' => 'http://spam'])->assertSessionHas('error');
        $this->get('/inscritos')->assertInertia(fn (Assert $page) => $page->where('people', []));
    }

    public function test_the_result_expires_and_can_be_cleared(): void
    {
        $this->lookup('1020304050');
        $this->post('/inscritos/salir')->assertRedirect('/inscritos');
        $this->get('/inscritos')->assertInertia(fn (Assert $page) => $page->where('people', []));

        $this->lookup('1020304050');
        $this->travel(16)->minutes();
        $this->get('/inscritos')->assertInertia(fn (Assert $page) => $page->where('people', []));
    }

    public function test_turnstile_is_used_when_its_keys_are_set(): void
    {
        config(['crm.turnstile.site_key' => 'site', 'crm.turnstile.secret' => 'secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        $this->get('/inscritos')->assertInertia(fn (Assert $page) => $page->where('challenge', ['type' => 'turnstile', 'site_key' => 'site']));

        $this->from('/inscritos')->post('/inscritos/consultar', ['document' => '1020304050', 'human' => 'bad'])->assertSessionHasErrors('human');
        $this->from('/inscritos')->post('/inscritos/consultar', ['document' => '1020304050', 'human' => 'token'])->assertRedirect('/inscritos');

        Http::assertSent(fn (Request $r) => $r['secret'] === 'secret' && $r['response'] === 'token');
    }
}
