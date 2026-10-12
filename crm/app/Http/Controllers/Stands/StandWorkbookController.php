<?php

namespace App\Http\Controllers\Stands;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StandWorkbook;
use App\Services\Stands\StandSpreadsheet;
use App\Support\Roles;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class StandWorkbookController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('stands/index', [
            'workbooks' => StandWorkbook::query()
                ->with(['creator:id,name', 'editor:id,name'])
                ->latest('updated_at')
                ->get()
                ->map(fn (StandWorkbook $w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'original_name' => $w->original_name,
                    'sheets' => collect($w->sheets)->pluck('name'),
                    'edits' => $w->edits,
                    'creator' => $w->creator?->name,
                    'editor' => $w->editor?->name,
                    'created_at' => $w->created_at->toIso8601String(),
                    'updated_at' => $w->updated_at->toIso8601String(),
                ]),
            'canEdit' => $request->user()->hasAnyRole(Roles::STAND_EDITORS),
        ]);
    }

    public function show(Request $request, StandWorkbook $workbook): Response
    {
        return Inertia::render('stands/show', [
            'workbook' => [
                'id' => $workbook->id,
                'name' => $workbook->name,
                'original_name' => $workbook->original_name,
                'edits' => $workbook->edits,
                'editor' => $workbook->editor?->name,
                'updated_at' => $workbook->updated_at->toIso8601String(),
            ],
            'sheets' => $workbook->sheets,
            'canEdit' => $request->user()->hasAnyRole(Roles::STAND_EDITORS),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls,ods'],
            'name' => ['nullable', 'string', 'max:120'],
        ], [], ['file' => 'archivo']);

        $file = $data['file'];
        $path = self::storeFile($file);
        try {
            $sheets = StandSpreadsheet::snapshot(StandSpreadsheet::load(Storage::disk('local')->path($path)));
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            report($e);

            return back()->with('error', 'No pude leer ese archivo. Revisa que sea un Excel (.xlsx) que abra sin errores.');
        }

        $workbook = StandWorkbook::create([
            'name' => trim($data['name'] ?? '') ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'sheets' => $sheets,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        AuditLog::record('stands.uploaded', $workbook, ['archivo' => $workbook->original_name, 'hojas' => count($sheets)]);

        return to_route('stands.show', $workbook)->with('success', "Excel «{$workbook->name}» cargado con ".count($sheets).' '.Str::plural('hoja', count($sheets)).'.');
    }

    /** Swaps the book for a newer version of the Excel, keeping its place and history. */
    public function replace(Request $request, StandWorkbook $workbook): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls,ods'],
        ], [], ['file' => 'archivo']);

        $file = $data['file'];
        $path = self::storeFile($file);
        try {
            $sheets = StandSpreadsheet::snapshot(StandSpreadsheet::load(Storage::disk('local')->path($path)));
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            report($e);

            return back()->with('error', 'No pude leer ese archivo. Revisa que sea un Excel (.xlsx) que abra sin errores.');
        }

        $previous = $workbook->path;
        $workbook->update([
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'sheets' => $sheets,
            'updated_by' => $request->user()->id,
        ]);
        Storage::disk('local')->delete($previous);
        AuditLog::record('stands.replaced', $workbook, ['archivo' => $workbook->original_name, 'hojas' => count($sheets)]);

        return back()->with('success', "Excel «{$workbook->name}» reemplazado.");
    }

    public function updateCell(Request $request, StandWorkbook $workbook): RedirectResponse
    {
        $data = $request->validate([
            'sheet' => ['required', 'integer', 'min:0'],
            'cell' => ['required', 'string', 'regex:/^[A-Z]{1,3}[1-9]\d{0,6}$/'],
            'value' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $change = Cache::lock("stand-workbook-{$workbook->id}", 30)->block(15, function () use ($workbook, $data, $request) {
                $workbook->refresh();
                $spreadsheet = StandSpreadsheet::load($workbook->absolutePath());
                $change = StandSpreadsheet::setValue($spreadsheet, (int) $data['sheet'], $data['cell'], $data['value'] ?? null);
                StandSpreadsheet::save($spreadsheet, $workbook->absolutePath());

                $workbook->forceFill([
                    'sheets' => StandSpreadsheet::snapshot($spreadsheet),
                    'edits' => $workbook->edits + 1,
                    'updated_by' => $request->user()->id,
                ])->save();

                return $change + ['hoja' => $spreadsheet->getSheet((int) $data['sheet'])->getTitle()];
            });
        } catch (DomainException $e) {
            return back()->withErrors(['value' => $e->getMessage()]);
        }

        if ($change['antes'] !== $change['despues']) {
            AuditLog::record('stands.cell_updated', $workbook, ['hoja' => $change['hoja'], 'celda' => $data['cell'], 'antes' => Str::limit($change['antes'], 200), 'despues' => Str::limit($change['despues'], 200)]);
        }

        return back();
    }

    public function download(StandWorkbook $workbook): BinaryFileResponse
    {
        AuditLog::record('stands.downloaded', $workbook);

        return response()->download($workbook->absolutePath(), Str::slug($workbook->name).'.xlsx');
    }

    public function destroy(StandWorkbook $workbook): RedirectResponse
    {
        AuditLog::record('stands.deleted', $workbook, ['archivo' => $workbook->original_name, 'ediciones' => $workbook->edits]);
        $workbook->delete();

        return to_route('stands.index')->with('success', "Excel «{$workbook->name}» eliminado.");
    }

    /** Edits are saved as .xlsx, so the stored copy is always one, whatever was uploaded. */
    private static function storeFile(UploadedFile $file): string
    {
        $path = StandWorkbook::DIRECTORY.'/'.Str::uuid().'.xlsx';
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'xlsx') {
            $file->storeAs(StandWorkbook::DIRECTORY, basename($path), 'local');

            return $path;
        }

        // .xls and .ods are converted once on upload.
        Storage::disk('local')->makeDirectory(StandWorkbook::DIRECTORY);
        StandSpreadsheet::save(StandSpreadsheet::load($file->getRealPath()), Storage::disk('local')->path($path));

        return $path;
    }
}
