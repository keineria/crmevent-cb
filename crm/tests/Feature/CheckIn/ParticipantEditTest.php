<?php

namespace Tests\Feature\CheckIn;

use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\PluginConnection;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParticipantEditTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://colombiabirdfair.com/wp-json/evr-crm/v1';

    private RegistrationParticipant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        PluginConnection::current()->fill(['base_url' => self::BASE, 'api_key' => str_repeat('k', 48), 'enabled' => true])->save();

        $registration = Registration::create(['wc_order_id' => 29766, 'number' => '29766', 'status' => 'completed', 'currency' => 'COP', 'total' => 600000]);
        $this->participant = RegistrationParticipant::create([
            'registration_id' => $registration->id, 'evr_order_participant_id' => 50, 'evr_participant_id' => 7,
            'full_name' => 'Carlos Martínez', 'email' => 'carlos@example.com', 'document_type' => 'cc', 'document_number' => '79765313',
            'whatsapp' => '+573163398876', 'shirt_size' => 'M', 'meal_type' => 'traditional', 'entity' => 'FUNDACIÓN AVES',
        ]);
        RegistrationActivity::create([
            'registration_participant_id' => $this->participant->id, 'evr_assignment_id' => 900, 'evr_activity_id' => 3,
            'name' => 'Chicoral', 'type' => 'birding', 'event_date' => '2027-10-03', 'status' => 'confirmed', 'price' => 180000, 'total' => 180000,
        ]);
    }

    private function staff(string $role): User
    {
        return tap(User::factory()->create(['name' => 'Mesa 1']))->assignRole($role);
    }

    /**
     * The order as the plugin returns it after a change.
     *
     * @param  list<array<string, mixed>>  $activities
     */
    private function order(array $participant = [], array $activities = []): array
    {
        return [
            'id' => 29766, 'number' => '29766', 'status' => 'completed', 'currency' => 'COP', 'total' => '600000',
            'created_at' => '2026-10-01T09:00:00-05:00', 'paid_at' => '2026-10-01T09:05:00-05:00', 'billing' => ['email' => 'carlos@example.com'],
            'participants' => [array_merge([
                'order_participant_id' => 50, 'participant_id' => 7, 'full_name' => 'Carlos Martínez', 'email' => 'carlos@example.com',
                'document_type' => 'cc', 'document_number' => '79765313', 'whatsapp' => '+573163398876', 'shirt_size' => 'M',
                'meal_type' => 'traditional', 'allergies' => '', 'entity' => 'FUNDACIÓN AVES', 'registered_by' => '',
                'activities' => $activities ?: [[
                    'assignment_id' => 900, 'activity_id' => 3, 'name' => 'Chicoral', 'type' => 'birding', 'event_date' => '2027-10-03',
                    'start_time' => null, 'status' => 'confirmed', 'price' => '180000', 'discount' => '0', 'total' => '180000', 'discount_label' => '',
                ]],
            ], $participant)],
        ];
    }

    public function test_desk_staff_edit_basic_details_through_the_plugin(): void
    {
        Http::fake([self::BASE.'/participants/7' => Http::response(['orders' => [$this->order(['shirt_size' => 'L', 'allergies' => 'Maní'])]])]);

        $this->actingAs($this->staff(Roles::TRIP_REGISTRATION))->from('/checkin')
            ->put("/checkin/persona/{$this->participant->id}/datos", [
                'full_name' => 'Carlos Martínez', 'email' => '', 'whatsapp' => '+57 316 339 8876', 'shirt_size' => 'L', 'meal_type' => 'traditional', 'allergies' => 'Maní', 'entity' => '',
            ])
            ->assertSessionHas('success', 'Datos actualizados.');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['shirt_size'] === 'L' && $r['order_participant_id'] === 50
            && $r['crm_user'] === 'Mesa 1' && ! isset($r['email']) && ! isset($r['entity']));
        $this->assertSame(['L', 'Maní'], [$this->participant->fresh()->shirt_size, $this->participant->fresh()->allergies]);
        $this->assertSame('participant.updated', AuditLog::sole()->action);
    }

    public function test_plugin_refusal_is_shown_and_nothing_changes(): void
    {
        Http::fake([self::BASE.'/participants/7' => Http::response(['code' => 'evr_crm_no_actor', 'message' => 'Falta elegir el usuario que firma los cambios.'], 409)]);

        $this->actingAs($this->staff(Roles::REGISTRATION))->from('/checkin')
            ->put("/checkin/persona/{$this->participant->id}/datos", ['full_name' => 'Otro', 'shirt_size' => 'S'])
            ->assertSessionHas('error', 'Falta elegir el usuario que firma los cambios.');

        $this->assertSame('M', $this->participant->fresh()->shirt_size);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_only_activity_editors_change_or_remove_activities(): void
    {
        $assignment = $this->participant->activities()->first();
        Http::fake([
            self::BASE.'/assignments/900/replace' => Http::response(['orders' => [$this->order([], [
                ['assignment_id' => 900, 'activity_id' => 3, 'name' => 'Chicoral', 'type' => 'birding', 'event_date' => '2027-10-03', 'start_time' => null,
                    'status' => 'cancelled', 'price' => '180000', 'discount' => '0', 'total' => '180000'],
                ['assignment_id' => 901, 'activity_id' => 4, 'name' => 'Peñas Blancas', 'type' => 'birding', 'event_date' => '2027-10-02', 'start_time' => null,
                    'status' => 'confirmed', 'price' => '180000', 'discount' => '0', 'total' => '180000'],
            ])]]),
        ]);

        $this->actingAs($this->staff(Roles::REGISTRATION))->post("/checkin/actividad/{$assignment->id}/cambiar", ['activity_id' => 4])->assertForbidden();

        $this->actingAs($this->staff(Roles::REGISTRATION_ADMIN))->from('/checkin')
            ->post("/checkin/actividad/{$assignment->id}/cambiar", ['activity_id' => 4])
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/assignments/900/replace') && $r['activity_id'] === 4);
        $this->assertSame([900 => 'cancelled', 901 => 'confirmed'], RegistrationActivity::pluck('status', 'evr_assignment_id')->all());
    }

    public function test_a_checked_in_activity_cannot_be_changed(): void
    {
        $assignment = $this->participant->activities()->first();
        CheckIn::create(['registration_activity_id' => $assignment->id, 'registration_participant_id' => $this->participant->id,
            'evr_activity_id' => 3, 'method' => 'qr', 'checked_in_at' => now()]);
        Http::fake();

        $this->actingAs($this->staff(Roles::ADMIN))->from('/checkin')
            ->post("/checkin/actividad/{$assignment->id}/retirar")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'ya ingresó'));
        Http::assertNothingSent();
    }

    public function test_adding_activities_reviews_then_registers_with_the_reviewed_total(): void
    {
        Http::fake([
            self::BASE.'/desk/preview' => Http::response(['participant' => 'Carlos Martínez', 'activities' => [['id' => 1, 'name' => 'Conferencias', 'price' => '350000', 'discount' => '0', 'total' => '350000']],
                'subtotal' => '350000', 'discount' => '0', 'total' => '350000', 'free' => false]),
            self::BASE.'/desk/register' => Http::response(['order_id' => 30001, 'orders' => [array_merge($this->order(), ['id' => 30001, 'number' => '30001', 'participants' => [array_merge($this->order()['participants'][0], [
                'order_participant_id' => 51,
                'activities' => [['assignment_id' => 950, 'activity_id' => 1, 'name' => 'Conferencias', 'type' => 'conference', 'event_date' => '2027-09-30',
                    'start_time' => null, 'status' => 'confirmed', 'price' => '350000', 'discount' => '0', 'total' => '350000']],
            ])]])]]),
        ]);
        $admin = $this->staff(Roles::REGISTRATION_ADMIN);

        $this->actingAs($admin)->postJson("/checkin/persona/{$this->participant->id}/agregar/revisar", ['activity_ids' => [1], 'entity' => 'FUNDACIÓN AVES'])
            ->assertOk()->assertJsonPath('total', '350000');

        $this->from('/checkin')->post("/checkin/persona/{$this->participant->id}/agregar", ['activity_ids' => [1], 'entity' => 'FUNDACIÓN AVES', 'expected_total' => 350000])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Recuerda cobrar'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/desk/register') && $r['document_type'] === 'cc'
            && $r['document_number'] === '79765313' && $r['expected_total'] === 350000.0 && $r['activity_ids'] === [1]);
        $this->assertSame(2, Registration::count());
        $this->assertSame(2, RegistrationParticipant::where('evr_participant_id', 7)->count());
    }
}
