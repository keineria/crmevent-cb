<?php

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\ContactEdition;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContactListTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_and_filters_contacts(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Roles::MARKETING);

        $ana = Contact::factory()->mailable()->create(['first_name' => 'Ana', 'is_attendee' => true]);
        ContactEdition::create(['contact_id' => $ana->id, 'year' => 2024, 'role' => 'asistente']);
        Contact::factory()->create(['first_name' => 'Luis', 'is_interested' => true]);
        Contact::factory()->create(['first_name' => 'Eva', 'unsubscribed_at' => now()]);

        $this->actingAs($user)->get('/contacts')->assertInertia(fn (Assert $page) => $page
            ->component('contacts/index')
            ->has('contacts.data', 3)
            ->where('stats.total', 3)
            ->where('stats.active', 2)
            ->where('stats.unsubscribed', 1)
            ->where('years', [2024])
            ->where('canImport', true));

        $this->actingAs($user)->get('/contacts?tipo=asistentes&anio=2024')->assertInertia(fn (Assert $page) => $page
            ->has('contacts.data', 1)
            ->where('contacts.data.0.name', $ana->fullName())
            ->where('contacts.data.0.status', 'activo')
            ->where('contacts.data.0.years', [2024]));

        $this->actingAs($user)->get('/contacts?estado=desinscrito')->assertInertia(fn (Assert $page) => $page
            ->has('contacts.data', 1)
            ->where('contacts.data.0.status', 'desinscrito'));

        $ana->forceFill(['preferred_products' => ['conferencias', 'salidas'], 'engagement_stars' => 4])->save();
        $this->actingAs($user)->get('/contacts?producto=salidas&estrellas=4')->assertInertia(fn (Assert $page) => $page
            ->has('contacts.data', 1)
            ->where('contacts.data.0.products', ['Conferencias', 'Salidas'])
            ->where('contacts.data.0.stars', 4));

        $this->actingAs($user)->get('/contacts?q=Luis')->assertInertia(fn (Assert $page) => $page->has('contacts.data', 1));
    }
}
