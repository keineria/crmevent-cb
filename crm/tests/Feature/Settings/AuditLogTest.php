<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['name' => 'Ana Admin']);
        $this->admin->assignRole(Roles::ADMIN);
    }

    private function staff(string $role = Roles::REGISTRATION, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    public function test_only_admins_see_the_audit_log(): void
    {
        $this->actingAs($this->staff(Roles::ANALYST))->get('/settings/audit')->assertForbidden();
        $this->actingAs($this->staff(Roles::REGISTRATION_ADMIN))->get('/settings/audit/export')->assertForbidden();
        $this->actingAs($this->admin)->get('/settings/audit')->assertOk();
    }

    public function test_admin_creates_a_user_and_it_is_recorded_with_who_did_it(): void
    {
        $this->actingAs($this->admin)->post('/settings/users', [
            'name' => 'Mesa 1',
            'email' => 'Mesa1@feria.co',
            'password' => 'clave-segura-123',
            'roles' => [Roles::REGISTRATION],
        ])->assertSessionHas('success');

        $user = User::where('email', 'mesa1@feria.co')->sole();
        $this->assertTrue($user->hasRole(Roles::REGISTRATION));

        $log = AuditLog::where('action', 'user.created')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('Ana Admin', $log->user_name);
        $this->assertSame('Mesa 1 <mesa1@feria.co>', $log->subject_label);
    }

    public function test_editing_a_user_records_what_changed(): void
    {
        $user = $this->staff(Roles::REGISTRATION, ['name' => 'Mesa 2']);

        $this->actingAs($this->admin)->put("/settings/users/{$user->id}", [
            'name' => 'Mesa 2',
            'roles' => [Roles::REGISTRATION, Roles::TRIP_REGISTRATION],
            'password' => '',
        ]);

        $changes = AuditLog::where('action', 'user.updated')->sole()->changes;
        $this->assertSame(['roles'], array_keys($changes));
        $this->assertSame([Roles::REGISTRATION], $changes['roles']['antes']);
    }

    public function test_a_deactivated_user_cannot_sign_in_and_the_attempt_is_recorded(): void
    {
        $user = $this->staff(Roles::REGISTRATION, ['email' => 'mesa3@feria.co']);

        $this->actingAs($this->admin)->post("/settings/users/{$user->id}/deactivate")->assertSessionHas('success');
        $this->assertFalse($user->fresh()->isActive());
        auth()->logout();

        $this->post('/login', ['email' => 'mesa3@feria.co', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'auth.blocked')->where('user_id', $user->id)->exists());

        $this->actingAs($this->admin)->post("/settings/users/{$user->id}/activate");
        auth()->logout();

        $this->post('/login', ['email' => 'mesa3@feria.co', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertSame(
            ['user.deactivated', 'auth.blocked', 'user.activated', 'auth.login'],
            AuditLog::where('action', '!=', 'auth.logout')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_a_signed_in_user_is_signed_out_once_deactivated(): void
    {
        $user = $this->staff();
        $user->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $this->actingAs($this->admin)->post("/settings/users/{$this->admin->id}/deactivate")->assertSessionHas('error');
        $this->assertTrue($this->admin->fresh()->isActive());
    }

    public function test_failed_logins_are_recorded_without_the_password(): void
    {
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'mala-clave']);

        $log = AuditLog::where('action', 'auth.failed')->sole();
        $this->assertNull($log->user_id);
        $this->assertSame($this->admin->id, $log->subject_id);
        $this->assertStringNotContainsString('mala-clave', json_encode($log->changes));
    }

    public function test_history_keeps_the_name_after_the_user_is_deleted(): void
    {
        $user = $this->staff(Roles::REGISTRATION, ['name' => 'Temporal']);
        AuditLog::record('checkin.qr_bulk', null, ['correos' => 3], $user);

        $this->actingAs($this->admin)->delete("/settings/users/{$user->id}");

        $this->actingAs($this->admin)->get('/settings/audit?module=checkin')
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/audit')
                ->has('entries.data', 1)
                ->where('entries.data.0.user', 'Temporal')
                ->where('entries.data.0.label', 'Envió los QR pendientes'));
    }

    public function test_filters_by_user_and_search_and_exports_csv(): void
    {
        $desk = $this->staff(Roles::REGISTRATION, ['name' => 'Mesa 4']);
        AuditLog::record('checkin.qr_bulk', null, ['correos' => 3], $desk);
        AuditLog::record('report.exported', null, ['archivo' => 'salidas.csv'], $this->admin);

        $this->actingAs($this->admin)->get("/settings/audit?user={$desk->id}")
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.action', 'checkin.qr_bulk'));

        $this->actingAs($this->admin)->get('/settings/audit?q=salidas')
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.action', 'report.exported'));

        $csv = $this->actingAs($this->admin)->get("/settings/audit/export?user={$desk->id}")->assertOk()->streamedContent();
        $this->assertStringContainsString('Mesa 4', $csv);
        $this->assertStringNotContainsString('salidas.csv', $csv);
        $this->assertTrue(AuditLog::where('action', 'audit.exported')->exists());
    }
}
