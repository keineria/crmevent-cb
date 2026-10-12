<?php

namespace Tests\Feature\Contacts;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->marketing = User::factory()->create();
        $this->marketing->assignRole(Roles::MARKETING);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'email' => ' Ana@Example.com ',
            'document_type' => 'CC',
            'document_number' => '1.020.304',
            'phone' => '300 123 4567',
            'profile' => 'Fotógrafo',
            'is_interested' => true,
            'products_auto' => false,
            'preferred_products' => ['salidas', 'conferencias'],
            'status' => 'activo',
            'edition_year' => 2026,
        ], $overrides);
    }

    public function test_creates_an_active_contact_by_hand(): void
    {
        $this->actingAs($this->marketing)->get('/contacts/create')->assertOk();

        $this->actingAs($this->marketing)->post('/contacts', $this->payload())->assertRedirect();

        $ana = Contact::sole();
        $this->assertSame(['ana@example.com', '1020304', '3001234567', 'Fotógrafo'], [$ana->email, $ana->document_number, $ana->phone, $ana->profile]);
        $this->assertSame(Contact::SOURCE_MANUAL, $ana->source);
        $this->assertSame(['conferencias', 'salidas'], $ana->preferred_products);
        $this->assertTrue($ana->preferred_products_manual);
        $this->assertSame(1, Contact::mailable()->count());
        $this->assertSame([2026], $ana->editions()->pluck('year')->all());
        $this->assertSame('contacts.created', AuditLog::latest('id')->value('action'));
    }

    public function test_refuses_a_duplicate_email_or_document(): void
    {
        $existing = Contact::factory()->create(['email' => 'ana@example.com', 'document_number' => '999']);

        $this->actingAs($this->marketing)->post('/contacts', $this->payload())->assertSessionHasErrors('email');
        $this->actingAs($this->marketing)->post('/contacts', $this->payload(['email' => 'otra@example.com', 'document_number' => '999']))
            ->assertSessionHasErrors('document_number');
        $this->actingAs($this->marketing)->post('/contacts', $this->payload(['email' => '', 'document_number' => '', 'phone' => '']))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, Contact::count());
        $this->assertSame($existing->id, Contact::sole()->id);
    }

    public function test_edits_and_unsubscribes_a_contact(): void
    {
        $contact = Contact::factory()->mailable()->create(['email' => 'ana@example.com']);

        $this->actingAs($this->marketing)->get("/contacts/{$contact->id}/edit")->assertOk();
        $this->actingAs($this->marketing)
            ->put("/contacts/{$contact->id}", $this->payload(['email' => 'ana@example.com', 'status' => 'desinscrito']))
            ->assertRedirect("/contacts/{$contact->id}");

        $contact->refresh();
        $this->assertSame(Contact::STATUS_UNSUBSCRIBED, $contact->status());
        $this->assertSame(0, Contact::mailable()->count());
        $this->assertSame('contacts.updated', AuditLog::latest('id')->value('action'));
    }

    public function test_analysts_cannot_add_contacts(): void
    {
        $analyst = User::factory()->create();
        $analyst->assignRole(Roles::ANALYST);

        $this->actingAs($analyst)->get('/contacts/create')->assertForbidden();
        $this->actingAs($analyst)->post('/contacts', $this->payload())->assertForbidden();
    }
}
