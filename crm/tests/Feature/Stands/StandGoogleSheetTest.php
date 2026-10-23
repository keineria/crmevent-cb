<?php

namespace Tests\Feature\Stands;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\StandPurchase;
use App\Models\StandWorkbook;
use App\Models\User;
use App\Services\Stands\GoogleSheetsClient;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StandGoogleSheetTest extends TestCase
{
    use RefreshDatabase;

    private const ID = '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789';

    private User $admin;

    private bool $faked = false;

    private ?string $rawCell = 'Ensifera Nature';

    private string $saldo2 = '3000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = tap(User::factory()->create())->assignRole(Roles::ADMIN);
    }

    private function keyJson(): string
    {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);

        return json_encode(['type' => 'service_account', 'client_email' => 'crm@birdfair.iam.gserviceaccount.com', 'private_key' => $pem]);
    }

    private function storeKey(): void
    {
        AppSetting::put(GoogleSheetsClient::SETTING, Crypt::encryptString($this->keyJson()));
    }

    /** @param array<string, mixed>|null $extra */
    private static function text(string $value, array $extra = []): array
    {
        return ['formattedValue' => $value, 'userEnteredValue' => ['stringValue' => $value], 'effectiveValue' => ['stringValue' => $value]] + $extra;
    }

    private static function money(float $value, ?string $formula = null): array
    {
        return [
            'formattedValue' => '$'.number_format($value),
            'userEnteredValue' => $formula ? ['formulaValue' => $formula] : ['numberValue' => $value],
            'effectiveValue' => ['numberValue' => $value],
            'effectiveFormat' => ['numberFormat' => ['type' => 'CURRENCY']],
        ];
    }

    /** The "Stands" tab as the Sheets API returns it: header, two zones, sold and free stands. */
    private function grid(string $saldo2 = '3000000'): array
    {
        $bold = ['effectiveFormat' => ['textFormat' => ['bold' => true], 'backgroundColor' => ['red' => 1, 'green' => 0.85, 'blue' => 0.4]]];
        $row = fn (string $n, string $name, float $price, float $paid) => ['values' => [
            self::text($n), $name === '' ? [] : self::text($name), self::money($price), self::money($paid), self::money($price - $paid, '=C:C-D:D'),
        ]];

        return ['sheets' => [[
            'properties' => ['title' => 'Stands'],
            'merges' => [['startRowIndex' => 4, 'endRowIndex' => 5, 'startColumnIndex' => 0, 'endColumnIndex' => 5]],
            'data' => [[
                'columnMetadata' => [['pixelSize' => 60], ['pixelSize' => 200]],
                'rowData' => [
                    ['values' => [self::text('Stand #', $bold), self::text("Nombre del Stand\nLetrero de 80x20 cms", $bold), self::text('Precio stand CBF', $bold), self::text('Abono / Pago', $bold), self::text('Saldo', $bold)]],
                    $row('1', 'Asociación Río Cali', 0, 0),
                    $row('2', 'Ensifera Nature', 3000000, 3000000 - (float) $saldo2),
                    $row('3', '', 0, 0),
                    ['values' => [self::text('ZONA EXTERIOR')]],
                    $row('1', 'Faunaris', 3000000, 1500000),
                    ['values' => []],
                ],
            ]],
        ]]];
    }

    /** What Google answers; later calls only change the answers, since the first matching fake wins. */
    private function fakeGoogle(?string $rawCell = 'Ensifera Nature', string $saldo2 = '3000000'): void
    {
        $this->rawCell = $rawCell;
        $this->saldo2 = $saldo2;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'sheets.googleapis.com/v4/spreadsheets/'.self::ID.'/values/*' => fn (Request $r) => $r->method() === 'GET'
                ? Http::response(['values' => $this->rawCell === null ? [] : [[$this->rawCell]]])
                : Http::response(['updatedCells' => 1]),
            'sheets.googleapis.com/v4/spreadsheets/'.self::ID.'*' => fn (Request $r) => str_contains($r->url(), 'includeGridData')
                ? Http::response($this->grid($this->saldo2))
                : Http::response(['properties' => ['title' => 'MASTER Colombia Birdfair 2027'], 'sheets' => [['properties' => ['title' => 'Salidas']], ['properties' => ['title' => 'Stands']]]]),
        ]);
    }

    private function connect(): StandWorkbook
    {
        $this->storeKey();
        $this->fakeGoogle();
        $this->actingAs($this->admin)->post('/stands/google', ['url' => 'https://docs.google.com/spreadsheets/d/'.self::ID.'/edit#gid=0', 'sheet' => 'stands'])
            ->assertSessionHasNoErrors()->assertRedirect();

        return StandWorkbook::latest('id')->firstOrFail();
    }

    public function test_admin_uploads_the_service_account_key_encrypted(): void
    {
        $file = UploadedFile::fake()->createWithContent('clave.json', $this->keyJson());
        $this->actingAs($this->admin)->post('/stands/google/clave', ['key' => $file])->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('PRIVATE KEY', (string) AppSetting::get(GoogleSheetsClient::SETTING));
        $this->assertSame('crm@birdfair.iam.gserviceaccount.com', GoogleSheetsClient::account()['client_email']);

        $bad = UploadedFile::fake()->createWithContent('clave.json', '{"client_email":"x"}');
        $this->post('/stands/google/clave', ['key' => $bad])->assertSessionHasErrors('key');

        $marketing = tap(User::factory()->create())->assignRole(Roles::MARKETING);
        $this->actingAs($marketing)->post('/stands/google/clave', ['key' => $file])->assertForbidden();
    }

    public function test_connecting_a_tab_reads_it_with_formulas_formats_and_merges(): void
    {
        $workbook = $this->connect();

        $this->assertTrue($workbook->isGoogle());
        $this->assertSame('Stands', $workbook->google_sheet);
        $this->assertSame('MASTER Colombia Birdfair 2027', $workbook->original_name);
        $this->assertNotNull($workbook->synced_at);

        $rows = $workbook->sheets[0]['rows'];
        $this->assertCount(6, $rows);
        $this->assertSame('=C:C-D:D', $rows[2][4]['f']);
        $this->assertSame('$3,000,000', $rows[2][4]['v']);
        $this->assertSame('3000000', $rows[2][2]['e']);
        $this->assertTrue($rows[0][0]['b']);
        $this->assertSame('#FFD966', $rows[0][0]['bg']);
        $this->assertSame([[4, 0, 1, 5]], $workbook->sheets[0]['merges']);
        $this->assertTrue(AuditLog::where('action', 'stands.google_connected')->exists());
    }

    public function test_connecting_explains_a_missing_tab(): void
    {
        $this->storeKey();
        $this->fakeGoogle();

        $this->actingAs($this->admin)->post('/stands/google', ['url' => self::ID, 'sheet' => 'Patrocinios'])
            ->assertSessionHasErrors(['sheet' => 'Esa hoja no tiene la pestaña «Patrocinios». Tiene: Salidas, Stands.']);
        $this->assertSame(0, StandWorkbook::count());
    }

    public function test_edits_are_written_to_google_and_read_back(): void
    {
        $workbook = $this->connect();
        $this->fakeGoogle('Ensifera Nature', '1000000');

        $this->patch("/stands/{$workbook->id}/celda", ['sheet' => 0, 'cell' => 'D3', 'value' => '2.000.000'])->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_contains(urldecode($r->url()), "'Stands'!D3")
            && str_contains($r->url(), 'valueInputOption=USER_ENTERED')
            && $r['values'] === [[2000000.0]]);
        $workbook->refresh();
        $this->assertSame('$1,000,000', $workbook->sheets[0]['rows'][2][4]['v']);
        $this->assertSame(1, $workbook->edits);
        $this->assertTrue(AuditLog::where('action', 'stands.cell_updated')->exists());

        // Text goes as written, so a phone number or a name is never reinterpreted.
        $this->patch("/stands/{$workbook->id}/celda", ['sheet' => 0, 'cell' => 'B3', 'value' => 'Ensifera Nature SAS'])->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), 'valueInputOption=RAW') && $r['values'] === [['Ensifera Nature SAS']]);
    }

    public function test_formula_cells_are_protected_even_when_added_in_drive(): void
    {
        $workbook = $this->connect();

        $this->patch("/stands/{$workbook->id}/celda", ['sheet' => 0, 'cell' => 'E3', 'value' => '5'])->assertSessionHasErrors('value');

        $this->fakeGoogle('=A1*2');
        $this->patch("/stands/{$workbook->id}/celda", ['sheet' => 0, 'cell' => 'C3', 'value' => '5'])->assertSessionHasErrors('value');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    }

    public function test_purchased_stands_list_only_named_stands_by_zone_and_keep_their_marks(): void
    {
        $workbook = $this->connect();

        $this->get("/stands/{$workbook->id}")->assertInertia(fn (Assert $page) => $page
            ->component('stands/show')
            ->where('purchases.columns', ['Precio stand CBF', 'Abono / Pago', 'Saldo'])
            ->has('purchases.stands', 3)
            ->where('purchases.stands.0.name', 'Asociación Río Cali')
            ->where('purchases.stands.1.details.2', '$3,000,000')
            ->where('purchases.stands.2.zone', 'ZONA EXTERIOR')
            ->where('purchases.stands.2.number', '1')
            ->where('purchases.stands.2.name', 'Faunaris'));

        $key = 'stands|zona exterior|1';
        $this->patch("/stands/{$workbook->id}/comprados", ['key' => $key, 'flag' => 'paid', 'value' => true])->assertSessionHasNoErrors();
        $this->patch("/stands/{$workbook->id}/comprados", ['key' => $key, 'flag' => 'invoiced', 'value' => true])->assertSessionHasNoErrors();

        $purchase = StandPurchase::firstOrFail();
        $this->assertTrue($purchase->paid);
        $this->assertFalse($purchase->documents);
        $this->assertTrue($purchase->invoiced);
        $this->assertSame('Faunaris', $purchase->name);
        $this->assertSame(['stand' => 'ZONA EXTERIOR · 1 · Faunaris', 'campo' => 'Facturado', 'valor' => 'Sí'], AuditLog::where('action', 'stands.purchase_updated')->latest('id')->first()->changes);

        // Stand 1 inside is not the same as stand 1 outside.
        $this->get("/stands/{$workbook->id}")->assertInertia(fn (Assert $page) => $page
            ->where('purchases.stands.0.paid', false)
            ->where('purchases.stands.2.paid', true));

        // A free stand cannot be marked.
        $this->patch("/stands/{$workbook->id}/comprados", ['key' => 'stands||3', 'flag' => 'paid', 'value' => true])->assertSessionHas('error');

        $analyst = tap(User::factory()->create())->assignRole(Roles::ANALYST);
        $this->actingAs($analyst)->patch("/stands/{$workbook->id}/comprados", ['key' => $key, 'flag' => 'paid', 'value' => false])->assertForbidden();
    }

    public function test_sync_command_reads_changes_made_in_drive_and_keeps_errors(): void
    {
        $workbook = $this->connect();
        $this->fakeGoogle(saldo2: '500000');

        $this->artisan('stands:sync')->assertSuccessful();
        $this->assertSame('$500,000', $workbook->refresh()->sheets[0]['rows'][2][4]['v']);

        Http::swap(new Factory);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']), 'sheets.googleapis.com/*' => Http::response(['error' => ['message' => 'denied']], 403)]);
        $this->artisan('stands:sync')->assertSuccessful();
        $workbook->refresh();
        $this->assertStringContainsString('crm@birdfair.iam.gserviceaccount.com', $workbook->sync_error);
        $this->assertSame('$500,000', $workbook->sheets[0]['rows'][2][4]['v']);
    }

    public function test_the_excel_xlsx_saved_in_drive_gets_a_clear_message(): void
    {
        $this->storeKey();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            'sheets.googleapis.com/*' => Http::response(['error' => ['message' => 'This operation is not supported for this document']], 400),
        ]);

        $this->actingAs($this->admin)->post('/stands/google', ['url' => self::ID, 'sheet' => 'Stands'])
            ->assertSessionHasErrors(['url' => 'Ese archivo es un Excel guardado en Drive. Ábrelo y usa Archivo › Guardar como Hojas de cálculo de Google, y conecta la copia.']);
    }
}
