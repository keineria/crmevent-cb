<?php

namespace Tests\Feature\Stands;

use App\Models\AuditLog;
use App\Models\StandWorkbook;
use App\Models\User;
use App\Services\Stands\StandSpreadsheet;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StandWorkbookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    private function user(string $role = Roles::ADMIN): User
    {
        return tap(User::factory()->create())->assignRole($role);
    }

    /** A small stands book: a merged title, a table with formulas, a status list and a totals row. */
    private function excel(): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Stands');
        $sheet->setCellValue('A1', 'Stands Colombia Birdfair 2027')->mergeCells('A1:F1');
        $sheet->fromArray(['Stand', 'Empresa', 'Valor', 'IVA', 'Total', 'Estado'], null, 'A2');
        $sheet->fromArray(['S-01', 'Óptica Andina', 1200000, '=C3*0.19', '=C3+D3', 'Pagado'], null, 'A3');
        $sheet->fromArray(['S-02', null, 800000, '=C4*0.19', '=C4+D4', 'Libre'], null, 'A4');
        $sheet->setCellValue('A5', 'Total')->setCellValue('E5', '=SUM(E3:E4)');
        $sheet->getStyle('C3:E5')->getNumberFormat()->setFormatCode('#,##0');
        $validation = $sheet->getCell('F3')->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)->setFormula1('"Libre,Reservado,Pagado"');
        $sheet->setDataValidation('F3:F4', $validation);
        $book->createSheet()->setTitle('Resumen')->setCellValue('A1', 'Recaudo')->setCellValue('B1', '=Stands!E5');

        $path = tempnam(sys_get_temp_dir(), 'stands').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'Stands 2027.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function upload(?User $user = null): StandWorkbook
    {
        $this->actingAs($user ?? $this->user())->post('/stands', ['file' => $this->excel()])->assertRedirect();

        return StandWorkbook::latest('id')->firstOrFail();
    }

    public function test_upload_reads_sheets_formulas_formats_lists_and_merges(): void
    {
        $workbook = $this->upload();

        $this->assertSame('Stands 2027', $workbook->name);
        $this->assertSame(['Stands', 'Resumen'], array_column($workbook->sheets, 'name'));

        $stands = $workbook->sheets[0];
        $this->assertSame([[0, 0, 1, 6]], $stands['merges']);
        $this->assertSame('=C3*0.19', $stands['rows'][2][3]['f']);
        $this->assertSame('228.000', $stands['rows'][2][3]['v']);
        $this->assertSame('1.200.000', $stands['rows'][2][2]['v']);
        $this->assertSame('1200000', $stands['rows'][2][2]['e']);
        $this->assertSame('2.380.000', $stands['rows'][4][4]['v']);
        $this->assertSame(['Libre', 'Reservado', 'Pagado'], $stands['lists'][$stands['rows'][2][5]['l']]);
        // An empty cell under a list stays editable through the list.
        $this->assertNull($stands['rows'][3][1]);
        $this->assertSame('2380000', $workbook->sheets[1]['rows'][0][1]['v']);

        $this->assertTrue(AuditLog::where('action', 'stands.uploaded')->exists());
        Storage::disk('local')->assertExists($workbook->path);
    }

    public function test_editing_a_value_recomputes_formulas_across_sheets_and_is_audited(): void
    {
        $workbook = $this->upload();

        $this->patch("/stands/{$workbook->id}/celda", ['sheet' => 0, 'cell' => 'C4', 'value' => '1.000.000'])->assertSessionHasNoErrors();

        $workbook->refresh();
        $rows = $workbook->sheets[0]['rows'];
        $this->assertSame('1.000.000', $rows[3][2]['v']);
        $this->assertSame('190.000', $rows[3][3]['v']);
        $this->assertSame('2.618.000', $rows[4][4]['v']);
        $this->assertSame('2618000', $workbook->sheets[1]['rows'][0][1]['v']);
        $this->assertSame(1, $workbook->edits);

        // The file keeps the formula and the new cached result.
        $saved = IOFactory::load($workbook->absolutePath())->getSheet(0);
        $this->assertSame('=C4*0.19', $saved->getCell('D4')->getValue());
        $this->assertEquals(190000, $saved->getCell('D4')->getOldCalculatedValue());

        $log = AuditLog::where('action', 'stands.cell_updated')->firstOrFail();
        $this->assertSame(['hoja' => 'Stands', 'celda' => 'C4', 'antes' => '800.000', 'despues' => '1.000.000'], $log->changes);

        $this->patch("/stands/{$workbook->id}/celda", ['sheet' => 0, 'cell' => 'B4', 'value' => 'Café del Valle'])->assertSessionHasNoErrors();
        $this->assertSame('Café del Valle', $workbook->refresh()->sheets[0]['rows'][3][1]['v']);
    }

    public function test_formula_cells_cannot_be_edited_and_formulas_cannot_be_typed(): void
    {
        $workbook = $this->upload();

        $this->patch("/stands/{$workbook->id}/celda", ['sheet' => 0, 'cell' => 'E3', 'value' => '5'])->assertSessionHasErrors('value');
        $this->patch("/stands/{$workbook->id}/celda", ['sheet' => 0, 'cell' => 'C3', 'value' => '=1+1'])->assertSessionHasErrors('value');

        $this->assertSame(0, $workbook->refresh()->edits);
        $this->assertSame('=C3+D3', IOFactory::load($workbook->absolutePath())->getSheet(0)->getCell('E3')->getValue());
    }

    public function test_viewers_see_but_cannot_edit(): void
    {
        $workbook = $this->upload();
        $analyst = $this->user(Roles::ANALYST);

        $this->actingAs($analyst)->get("/stands/{$workbook->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('stands/show')->where('canEdit', false)->has('sheets', 2));
        $this->actingAs($analyst)->patch("/stands/{$workbook->id}/celda", ['sheet' => 0, 'cell' => 'C3', 'value' => '1'])->assertForbidden();
        $this->actingAs($analyst)->post('/stands', ['file' => $this->excel()])->assertForbidden();

        $this->actingAs($this->user(Roles::REGISTRATION))->get('/stands')->assertForbidden();
    }

    public function test_replace_download_and_delete(): void
    {
        $workbook = $this->upload();
        $old = $workbook->path;

        $this->post("/stands/{$workbook->id}/reemplazar", ['file' => $this->excel()])->assertSessionHasNoErrors();
        $workbook->refresh();
        $this->assertNotSame($old, $workbook->path);
        Storage::disk('local')->assertMissing($old);

        $this->get("/stands/{$workbook->id}/descargar")->assertOk()->assertDownload('stands-2027.xlsx');

        $this->delete("/stands/{$workbook->id}")->assertRedirect('/stands');
        $this->assertModelMissing($workbook);
        Storage::disk('local')->assertMissing($workbook->path);
    }

    public function test_numbers_are_read_as_typed_in_colombia(): void
    {
        $this->assertSame(1200000.0, StandSpreadsheet::parseNumber('1.200.000'));
        $this->assertSame(1500.0, StandSpreadsheet::parseNumber('1.500'));
        $this->assertSame(1250.5, StandSpreadsheet::parseNumber('1.250,50'));
        $this->assertSame(1250.5, StandSpreadsheet::parseNumber('1,250.50'));
        $this->assertSame(0.75, StandSpreadsheet::parseNumber('0,75'));
        $this->assertSame(300000.0, StandSpreadsheet::parseNumber('$ 300.000'));
        $this->assertSame(12.5, StandSpreadsheet::parseNumber('12.5'));
        $this->assertNull(StandSpreadsheet::parseNumber('S-01'));
        $this->assertNull(StandSpreadsheet::parseNumber('3.'));
    }
}
