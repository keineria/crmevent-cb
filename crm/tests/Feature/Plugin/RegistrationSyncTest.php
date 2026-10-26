<?php

namespace Tests\Feature\Plugin;

use App\Jobs\ReconcilePluginOrders;
use App\Jobs\SyncPluginRegistrations;
use App\Models\Contact;
use App\Models\EventActivity;
use App\Models\PluginConnection;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\User;
use App\Services\Plugin\RegistrationSync;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://colombiabirdfair.com/wp-json/evr-crm/v1';

    private function connection(): PluginConnection
    {
        $c = PluginConnection::current();
        $c->fill(['base_url' => self::BASE, 'api_key' => str_repeat('k', 48), 'created_after' => '2026-08-01', 'enabled' => true])->save();

        return $c;
    }

    private function order(int $id, string $status = 'processing', string $updated = '2026-09-01 10:00:00'): array
    {
        return [
            'id' => $id, 'number' => (string) $id, 'status' => $status, 'currency' => 'COP', 'total' => '350000.00',
            'created_at' => '2026-08-20T09:00:00-05:00', 'paid_at' => '2026-08-20T09:05:00-05:00', 'modified_at' => null,
            'billing' => ['first_name' => 'Ana', 'last_name' => 'Pérez', 'company' => '', 'email' => 'ana@example.com', 'phone' => '3001234567',
                'city' => 'CALI', 'state' => 'VAC', 'state_name' => 'Valle del Cauca', 'country' => 'CO', 'country_name' => 'Colombia'],
            'participants' => [
                [
                    'order_participant_id' => $id * 10, 'participant_id' => 7, 'full_name' => 'ANA MARÍA PÉREZ GÓMEZ', 'email' => 'Ana@Example.com',
                    'document_type' => 'cc', 'document_number' => '1.020.304', 'whatsapp' => '+57 300 123 4567', 'shirt_size' => 'M',
                    'meal_type' => 'vegetarian', 'allergies' => '', 'entity' => 'Fundación Aves', 'registered_by' => '', 'created_at' => '2026-08-20 14:00:00',
                    'activities' => [
                        ['assignment_id' => $id * 100 + 1, 'activity_id' => 1, 'name' => 'Conferencias', 'type' => 'conference', 'event_date' => '2026-10-01',
                            'start_time' => '08:00:00', 'end_time' => null, 'destination' => null, 'status' => 'confirmed', 'price' => '105000', 'discount' => '0', 'total' => '105000',
                            'created_at' => '', 'updated_at' => $updated, 'cancelled_at' => null, 'removed_at' => null],
                        ['assignment_id' => $id * 100 + 2, 'activity_id' => 2, 'name' => 'Salida Km 18', 'type' => 'birding', 'event_date' => '2026-10-02',
                            'start_time' => '05:00:00', 'end_time' => null, 'destination' => 'Km 18', 'status' => 'confirmed', 'price' => '245000', 'discount' => '0', 'total' => '245000',
                            'created_at' => '', 'updated_at' => $updated, 'cancelled_at' => null, 'removed_at' => null],
                    ],
                ],
            ],
            'cursor' => ['updated_at' => $updated, 'order_id' => $id],
        ];
    }

    /** Orders that still exist in WooCommerce, for POST /orders/lookup. */
    private ?array $lookup = null;

    private function fakePlugin(array $pages): void
    {
        $queue = $pages;
        $lookup = &$this->lookup;
        Http::fake([
            self::BASE.'/activities' => Http::response(['data' => [
                ['id' => 1, 'type' => 'conference', 'name' => 'Conferencias', 'destination' => null, 'event_date' => '2026-10-01', 'start_time' => '08:00:00',
                    'end_time' => null, 'price' => '105000', 'capacity_total' => 500, 'reserved_count' => 3, 'confirmed_count' => 420, 'status' => 'active', 'updated_at' => ''],
                ['id' => 2, 'type' => 'birding', 'name' => 'Salida Km 18', 'destination' => 'Km 18', 'event_date' => '2026-10-02', 'start_time' => '05:00:00',
                    'end_time' => null, 'price' => '245000', 'capacity_total' => 19, 'reserved_count' => 0, 'confirmed_count' => 19, 'status' => 'active', 'updated_at' => ''],
            ]]),
            self::BASE.'/orders/lookup' => function (Request $r) use (&$lookup) {
                $ids = $r->data()['ids'];
                $known = array_values(array_filter($lookup ?? [], fn ($o) => in_array($o['id'], $ids, true)));

                return Http::response(['data' => $known, 'missing' => array_values(array_diff($ids, array_column($known, 'id')))]);
            },
            self::BASE.'/orders*' => function () use (&$queue) {
                return Http::response(array_shift($queue) ?? ['data' => [], 'next_cursor' => null, 'has_more' => false]);
            },
            self::BASE.'/ping' => Http::response(['ok' => true, 'evr_active' => true, 'woocommerce' => true, 'site' => 'https://colombiabirdfair.com']),
        ]);
    }

    public function test_sync_mirrors_activities_orders_and_contacts(): void
    {
        $existing = Contact::factory()->create(['document_number' => '1020304', 'email' => null, 'first_name' => 'Ana María', 'city' => null, 'phone' => null, 'country' => null]);
        $connection = $this->connection();
        $this->fakePlugin([
            ['data' => [$this->order(501)], 'next_cursor' => ['updated_at' => '2026-09-01 10:00:00', 'order_id' => 501], 'has_more' => true],
            ['data' => [$this->order(502, 'pending', '2026-09-02 08:00:00')], 'next_cursor' => ['updated_at' => '2026-09-02 08:00:00', 'order_id' => 502], 'has_more' => false],
        ]);

        $result = app(RegistrationSync::class)->run($connection);

        $this->assertSame(['activities' => 2, 'orders' => 2, 'participants' => 2, 'new_contacts' => 0, 'removed' => 0, 'has_more' => false], $result);
        $this->assertSame(2, EventActivity::count());
        $this->assertSame(19, EventActivity::where('evr_id', 2)->value('confirmed_count'));
        $this->assertSame(2, Registration::count());
        $this->assertSame(2026, Registration::where('wc_order_id', 501)->value('edition_year'));

        // The participant was matched to the contact imported from Excel by document; empty fields were completed.
        $existing->refresh();
        $this->assertSame(1, Contact::count());
        $this->assertSame(['ana@example.com', 'Cali', 'Valle del Cauca', 'Colombia', '+573001234567'], [$existing->email, $existing->city, $existing->state, $existing->country, $existing->phone]);
        $this->assertTrue($existing->is_attendee);
        $edition = $existing->editions()->sole();
        $this->assertSame(2026, $edition->year);
        $this->assertSame('Conferencias · Salida Km 18', $edition->details['Actividades']);

        $connection->refresh();
        $this->assertSame(['2026-09-02 08:00:00', 502, 'ok'], [$connection->cursor_updated, (int) $connection->cursor_order, $connection->last_status]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/orders') && $r->hasHeader('X-CRM-Key', str_repeat('k', 48))
            && str_contains($r->url(), 'created_after=2026-08-01'));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'after_updated=2026-09-01 10:00:00') && str_contains($r->url(), 'after_order=501'));
    }

    public function test_running_twice_does_not_duplicate(): void
    {
        $connection = $this->connection();
        $page = ['data' => [$this->order(501)], 'next_cursor' => ['updated_at' => '2026-09-01 10:00:00', 'order_id' => 501], 'has_more' => false];
        $this->fakePlugin([$page, $page]);

        app(RegistrationSync::class)->run($connection);
        app(RegistrationSync::class)->run($connection->fresh());

        $this->assertSame(1, Registration::count());
        $this->assertSame(1, Contact::count());
        $this->assertSame(2, RegistrationActivity::count());
    }

    public function test_unpaid_orders_do_not_create_contacts_and_the_list_shows_paid_by_default(): void
    {
        $this->seed(RoleSeeder::class);
        $connection = $this->connection();
        $unpaid = $this->order(601, 'pending');
        $unpaid['participants'][0]['order_participant_id'] = 6010;
        $unpaid['participants'][0]['full_name'] = 'Pedro Gil';
        $unpaid['participants'][0]['email'] = 'pedro@example.com';
        $unpaid['participants'][0]['document_number'] = '999';
        $this->fakePlugin([['data' => [$this->order(501), $unpaid], 'next_cursor' => null, 'has_more' => false]]);

        app(RegistrationSync::class)->run($connection);

        $this->assertSame(['ana@example.com'], Contact::pluck('email')->all());
        $ana = Contact::sole();
        $ana->editions()->create(['year' => 2024, 'role' => 'asistente']);

        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        $this->actingAs($admin)->get('/registrations')->assertInertia(fn (Assert $page) => $page
            ->where('filters.estado', 'pagadas')
            ->has('participants.data', 1)
            ->where('participants.data.0.name', 'ANA MARÍA PÉREZ GÓMEZ')
            ->where('participants.data.0.visits', 2)
            ->where('participants.data.0.last_purchase_at', fn ($v) => str_starts_with($v, '2026-08-20'))
            ->has('activities', 2));

        $this->actingAs($admin)->get('/registrations?estado=todos')->assertInertia(fn (Assert $page) => $page->has('participants.data', 2));

        $this->actingAs($admin)->get('/contacts')->assertInertia(fn (Assert $page) => $page
            ->where('contacts.data.0.visits', 2)
            ->where('contacts.data.0.email', 'ana@example.com'));
    }

    public function test_prune_removes_only_contacts_from_unpaid_attempts(): void
    {
        $connection = $this->connection();
        $this->fakePlugin([['data' => [$this->order(501)], 'next_cursor' => null, 'has_more' => false]]);
        app(RegistrationSync::class)->run($connection);

        // Created by an older sync from an abandoned checkout.
        $stale = Contact::factory()->create(['source' => Contact::SOURCE_PLUGIN, 'is_attendee' => false, 'is_interested' => false, 'is_subscriber' => false, 'marketing_consent' => false, 'subscription_requested_at' => null, 'unsubscribed_at' => null]);
        $imported = Contact::factory()->create(['source' => Contact::SOURCE_IMPORT, 'is_attendee' => false, 'marketing_consent' => false]);

        $this->artisan('crm:prune-unpaid-contacts')->expectsOutput('Se borraron 1 contactos de intentos sin pagar.')->assertSuccessful();

        $this->assertModelMissing($stale);
        $this->assertModelExists($imported);
        $this->assertSame(1, Contact::where('email', 'ana@example.com')->count());
    }

    public function test_an_order_deleted_in_woocommerce_leaves_the_crm_and_the_contact_history(): void
    {
        $connection = $this->connection();
        $this->fakePlugin([
            ['data' => [$this->order(501)], 'next_cursor' => ['updated_at' => '2026-09-01 10:00:00', 'order_id' => 501], 'has_more' => false],
            ['data' => [], 'deleted' => [501], 'next_cursor' => ['updated_at' => '2026-10-26 10:00:00', 'order_id' => 501], 'has_more' => false],
        ]);

        app(RegistrationSync::class)->run($connection);
        $ana = Contact::sole();
        $ana->editions()->create(['year' => 2024, 'role' => 'asistente', 'details' => ['Fuente' => 'Excel']]);
        $this->assertSame([2024, 2026], $ana->editions()->pluck('year')->sort()->values()->all());
        $this->assertTrue($ana->fresh()->is_attendee);

        $result = app(RegistrationSync::class)->run($connection->fresh());

        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, Registration::count());
        $this->assertSame(0, RegistrationActivity::count());
        // The 2026 purchase is gone; the history from Excel stays, and so does the person.
        $this->assertSame([2024], $ana->editions()->pluck('year')->all());
        $this->assertTrue($ana->fresh()->is_attendee);
        $this->assertDatabaseHas('audit_logs', ['action' => 'plugin.order-removed']);
    }

    public function test_a_cancelled_order_no_longer_counts_as_a_purchase(): void
    {
        $connection = $this->connection();
        $cancelled = $this->order(501, 'cancelled', '2026-09-03 10:00:00');
        $this->fakePlugin([
            ['data' => [$this->order(501)], 'next_cursor' => ['updated_at' => '2026-09-01 10:00:00', 'order_id' => 501], 'has_more' => false],
            ['data' => [$cancelled], 'next_cursor' => ['updated_at' => '2026-09-03 10:00:00', 'order_id' => 501], 'has_more' => false],
        ]);

        app(RegistrationSync::class)->run($connection);
        $this->assertSame(1, Contact::sole()->editions()->count());

        app(RegistrationSync::class)->run($connection->fresh());

        $ana = Contact::sole();
        $this->assertSame(0, $ana->editions()->count());
        $this->assertFalse($ana->is_attendee);
        $this->assertSame('cancelled', Registration::sole()->status);
    }

    public function test_reconcile_removes_deleted_orders_and_trashed_ones_stop_counting(): void
    {
        $connection = $this->connection();
        $second = $this->order(502);
        $second['participants'][0]['order_participant_id'] = 5020;
        $second['participants'][0]['email'] = 'luis@example.com';
        $second['participants'][0]['full_name'] = 'Luis Mora';
        $second['participants'][0]['document_number'] = '777';
        $this->fakePlugin([['data' => [$this->order(501), $second], 'next_cursor' => null, 'has_more' => false]]);
        app(RegistrationSync::class)->run($connection);
        $this->assertSame(2, Contact::whereHas('editions')->count());

        // 501 was emptied from the trash before the bridge reported deletions; 502 sits in the trash.
        $trashed = $second;
        $trashed['status'] = 'trash';
        $this->lookup = [$trashed];

        $result = app(RegistrationSync::class)->reconcile($connection->fresh());

        $this->assertSame(['checked' => 2, 'updated' => 1, 'removed' => 1, 'contacts' => 0], $result);
        $this->assertSame(['trash'], Registration::pluck('status')->all());
        $this->assertSame(0, Contact::whereHas('editions')->count());
        $this->assertSame($result, $connection->fresh()->last_reconcile_result);
    }

    public function test_reconcile_clears_history_left_by_older_cancellations(): void
    {
        $connection = $this->connection();
        $this->fakePlugin([['data' => [$this->order(501, 'cancelled')], 'next_cursor' => null, 'has_more' => false]]);
        $ana = Contact::factory()->create(['document_number' => '1020304', 'is_attendee' => true]);
        // Written by the sync before cancellations were followed.
        $ana->editions()->create(['year' => 2027, 'role' => 'asistente', 'details' => ['Pedido' => '#499', 'Fuente' => RegistrationSync::EDITION_SOURCE]]);
        $ana->editions()->create(['year' => 2025, 'role' => 'asistente', 'details' => ['Plan' => 'Conferencias']]);
        $this->lookup = [];

        $result = app(RegistrationSync::class)->reconcile($connection);

        $this->assertSame(1, $result['contacts']);
        $this->assertSame([2025], $ana->editions()->pluck('year')->all());
        $this->assertTrue($ana->fresh()->is_attendee);
    }

    public function test_activity_dates_reach_the_pages_as_plain_dates(): void
    {
        EventActivity::create(['evr_id' => 9, 'type' => 'conference', 'name' => 'X', 'event_date' => '2026-10-01', 'capacity_total' => 10, 'status' => 'active']);

        $this->assertSame('2026-10-01', EventActivity::first()->toArray()['event_date']);
    }

    public function test_errors_are_saved_on_the_connection(): void
    {
        $connection = $this->connection();
        Http::fake([self::BASE.'/activities' => Http::response(['code' => 'evr_crm_unauthorized'], 401)]);

        try {
            app(RegistrationSync::class)->run($connection);
            $this->fail('Expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('clave no es válida', $e->getMessage());
        }

        $this->assertSame('error', $connection->fresh()->last_status);
    }

    public function test_admin_configures_tests_and_syncs_the_connection(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $this->fakePlugin([]);
        Queue::fake();

        $this->actingAs($admin)->put('/settings/plugin', [
            'base_url' => 'https://colombiabirdfair.com/',
            'api_key' => str_repeat('k', 48),
            'created_after' => '2026-08-01',
            'enabled' => true,
        ])->assertSessionHas('success');

        $c = PluginConnection::sole();
        $this->assertSame(self::BASE, $c->base_url);
        $this->assertNotSame(str_repeat('k', 48), DB::table('plugin_connections')->value('api_key'));

        $this->actingAs($admin)->post('/settings/plugin/test')->assertSessionHas('success');
        $this->actingAs($admin)->post('/settings/plugin/sync')->assertSessionHas('success');
        Queue::assertPushed(SyncPluginRegistrations::class);
        $this->actingAs($admin)->post('/settings/plugin/reconcile')->assertSessionHas('success');
        Queue::assertPushed(ReconcilePluginOrders::class);

        $this->actingAs($admin)->get('/settings/plugin')->assertInertia(fn (Assert $p) => $p
            ->component('settings/plugin')
            ->where('connection.has_key', true)
            ->missing('connection.api_key'));

        $registro = User::factory()->create();
        $registro->assignRole(Roles::REGISTRATION);
        $this->actingAs($registro)->get('/settings/plugin')->assertForbidden();
        $this->actingAs($registro)->get('/registrations')->assertOk();
        $this->actingAs($registro)->get('/activities')->assertOk();
    }
}
