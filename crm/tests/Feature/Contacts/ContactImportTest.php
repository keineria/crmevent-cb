<?php

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ContactImportTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $this->marketing = User::factory()->create();
        $this->marketing->assignRole(Roles::MARKETING);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows, string $delimiter = ';', bool $windows1252 = true): UploadedFile
    {
        $content = implode("\r\n", array_map(fn ($r) => implode($delimiter, $r), $rows));

        return UploadedFile::fake()->createWithContent('asistentes.csv', $windows1252 ? mb_convert_encoding($content, 'Windows-1252', 'UTF-8') : $content);
    }

    private function upload(UploadedFile $file, array $overrides = []): ContactImport
    {
        $this->actingAs($this->marketing)
            ->post('/contacts/import', array_merge(['file' => $file, 'kind' => 'asistente', 'edition_year' => 2025, 'has_consent' => true], $overrides))
            ->assertRedirect();

        return ContactImport::latest('id')->firstOrFail();
    }

    private function runImport(ContactImport $import, ?array $mapping = null): void
    {
        $this->actingAs($this->marketing)
            ->post("/contacts/import/{$import->id}/run", ['mapping' => $mapping ?? $import->mapping])
            ->assertRedirect("/contacts/import/{$import->id}");
    }

    public function test_upload_reads_a_windows_csv_and_suggests_the_mapping(): void
    {
        $import = $this->upload($this->csv([
            ['Nombre completo', 'Correo electrónico', 'Teléfono', 'Ciudad', 'País', 'Columna rara'],
            ['ANA MARÍA PÉREZ GÓMEZ', 'Ana@Example.com', '3001234567', 'MEDELLÍN', 'Colombia', 'x'],
        ]));

        $this->assertSame(['Nombre completo', 'Correo electrónico', 'Teléfono', 'Ciudad', 'País', 'Columna rara'], $import->headers);
        $this->assertSame(['full_name', 'email', 'phone', 'city', 'country', null], $import->mapping);
        $this->assertSame(1, $import->total_rows);
        $this->assertSame(ContactImport::STATUS_MAPPING, $import->status);
    }

    public function test_import_creates_contacts_with_edition_and_consent(): void
    {
        $import = $this->upload($this->csv([
            ['Nombre completo', 'Correo', 'Teléfono', 'Ciudad'],
            ['ANA MARÍA PÉREZ GÓMEZ', 'Ana@Example.com', '3001234567', 'MEDELLÍN'],
            ['Luis Rojas', 'no-es-correo', '', ''],
            ['Sin Datos', '', '', 'Cali'],
        ]));

        $this->runImport($import);

        $import->refresh();
        $this->assertSame(ContactImport::STATUS_DONE, $import->status);
        $this->assertSame([1, 0, 2], [$import->created_count, $import->updated_count, $import->skipped_count]);
        $this->assertCount(2, $import->errors);
        Storage::disk('local')->assertMissing($import->path);

        $ana = Contact::sole();
        $this->assertSame(['Ana María', 'Pérez Gómez', 'ana@example.com', 'Medellín'], [$ana->first_name, $ana->last_name, $ana->email, $ana->city]);
        $this->assertTrue($ana->is_attendee);
        $this->assertTrue($ana->marketing_consent);
        $this->assertSame('Importación «asistentes.csv»', $ana->consent_source);
        $this->assertSame([2025], $ana->editions()->pluck('year')->all());
    }

    public function test_reimport_completes_missing_data_without_overwriting_or_duplicating(): void
    {
        $existing = Contact::factory()->create(['email' => 'ana@example.com', 'first_name' => 'Ana', 'city' => null, 'phone' => '111']);

        $import = $this->upload($this->csv([
            ['Nombres', 'Correo', 'Teléfono', 'Ciudad', 'Año'],
            ['Otra', 'ANA@example.com', '999', 'Bogotá', '2024'],
            ['Otra', 'ana@example.com', '999', 'Bogotá', '2023'],
        ]), ['edition_year' => '']);

        $this->runImport($import);

        $existing->refresh();
        $this->assertSame(1, Contact::count());
        $this->assertSame(['Ana', '111', 'Bogotá'], [$existing->first_name, $existing->phone, $existing->city]);
        $this->assertSame([2024, 2023], $existing->editions()->pluck('year')->all());
        $this->assertSame([0, 2], [$import->fresh()->created_count, $import->fresh()->updated_count]);
    }

    public function test_unsubscribed_contacts_stay_unsubscribed(): void
    {
        Contact::factory()->create(['email' => 'baja@example.com', 'unsubscribed_at' => now()]);

        $import = $this->upload($this->csv([['Correo'], ['baja@example.com']]));
        $this->runImport($import);

        $contact = Contact::sole();
        $this->assertFalse($contact->marketing_consent);
        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertSame(0, Contact::mailable()->count());
    }

    public function test_import_without_consent_does_not_allow_broadcasts(): void
    {
        $import = $this->upload($this->csv([['Correo'], ['a@example.com']]), ['has_consent' => false, 'kind' => 'interesado']);
        $this->runImport($import);

        $contact = Contact::sole();
        $this->assertTrue($contact->is_interested);
        $this->assertFalse($contact->is_attendee);
        $this->assertFalse($contact->marketing_consent);
    }

    public function test_reads_xlsx_with_numeric_cells(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Nombre', 'Apellido', 'Email', 'Celular', 'Cédula']));
        $writer->addRow(Row::fromValues(['Carlos', 'Díaz', 'carlos@example.com', 3109876543, 1020304050]));
        $writer->close();

        $import = $this->upload(new UploadedFile($path, 'feria-2024.xlsx', null, null, true));
        $this->runImport($import);

        $contact = Contact::sole();
        $this->assertSame(['Carlos', 'Díaz', '3109876543', '1020304050'], [$contact->first_name, $contact->last_name, $contact->phone, $contact->document_number]);
    }

    public function test_mapping_needs_an_identifier_and_unique_fields(): void
    {
        $import = $this->upload($this->csv([['Nombre', 'Ciudad', 'Otra'], ['Ana', 'Cali', 'x']]));

        $this->actingAs($this->marketing)->post("/contacts/import/{$import->id}/run", ['mapping' => ['first_name', 'city', null]])
            ->assertSessionHasErrors('mapping');
        $this->actingAs($this->marketing)->post("/contacts/import/{$import->id}/run", ['mapping' => ['email', 'email', null]])
            ->assertSessionHasErrors('mapping');
        $this->actingAs($this->marketing)->post("/contacts/import/{$import->id}/run", ['mapping' => ['first_name', 'city', 'unknown']])
            ->assertSessionHasErrors('mapping.2');

        $this->assertSame(ContactImport::STATUS_MAPPING, $import->fresh()->status);
    }

    public function test_an_import_runs_only_once(): void
    {
        $import = $this->upload($this->csv([['Correo'], ['a@example.com']]));
        $this->runImport($import);

        $this->actingAs($this->marketing)->post("/contacts/import/{$import->id}/run", ['mapping' => ['email']])->assertStatus(409);
    }

    public function test_rejects_unsupported_or_empty_files(): void
    {
        $this->actingAs($this->marketing)
            ->post('/contacts/import', ['file' => UploadedFile::fake()->create('viejo.xls', 10), 'kind' => 'asistente'])
            ->assertSessionHasErrors('file');
        $this->actingAs($this->marketing)
            ->post('/contacts/import', ['file' => $this->csv([['Correo']]), 'kind' => 'asistente'])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, ContactImport::count());
    }

    public function test_only_admin_and_marketing_can_import(): void
    {
        $registro = User::factory()->create();
        $registro->assignRole(Roles::REGISTRATION);
        $analyst = User::factory()->create();
        $analyst->assignRole(Roles::ANALYST);

        $this->actingAs($registro)->get('/contacts/import')->assertForbidden();
        $this->actingAs($analyst)->get('/contacts/import')->assertForbidden();
        $this->actingAs($analyst)->get('/contacts')->assertOk();
        $this->actingAs($registro)->get('/contacts')->assertForbidden();
        $this->actingAs($this->marketing)->get('/contacts/import')->assertOk();
    }
}
