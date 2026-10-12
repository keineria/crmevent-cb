<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Jobs\ImportContacts;
use App\Models\AuditLog;
use App\Models\ContactImport;
use App\Services\Contacts\ImportFields;
use App\Services\Contacts\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ContactImportController extends Controller
{
    private const SAMPLE_ROWS = 5;

    public function create(): Response
    {
        return Inertia::render('contacts/import/create', [
            'recent' => ContactImport::with('user:id,name')->latest()->limit(10)->get()->map(fn (ContactImport $i) => [
                'id' => $i->id,
                'name' => $i->original_name,
                'kind' => $i->kind,
                'edition_year' => $i->edition_year,
                'status' => $i->status,
                'created' => $i->created_count,
                'updated' => $i->updated_count,
                'skipped' => $i->skipped_count,
                'user' => $i->user?->name,
                'created_at' => $i->created_at->toIso8601String(),
            ]),
            'defaultYear' => (int) now()->year,
        ]);
    }

    public function store(Request $request, SpreadsheetReader $reader): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'extensions:'.implode(',', SpreadsheetReader::EXTENSIONS)],
            'kind' => ['required', Rule::in(ContactImport::KINDS)],
            'edition_year' => ['nullable', 'integer', 'between:2000,2100'],
            'has_consent' => ['boolean'],
        ], [], ['file' => 'archivo', 'kind' => 'tipo', 'edition_year' => 'año de la feria']);

        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('imports', Str::uuid().'.'.$extension, 'local');

        try {
            $headers = null;
            $sample = [];
            $total = 0;
            foreach ($reader->rows(Storage::disk('local')->path($path), $extension) as $row) {
                if ($headers === null) {
                    $headers = $row;

                    continue;
                }
                if (implode('', $row) === '' || ImportFields::isHeaderRow($row, $headers)) {
                    continue;
                }
                $total++;
                if (count($sample) < self::SAMPLE_ROWS) {
                    $sample[] = $row;
                }
            }
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo: '.$e->getMessage()]);
        }

        if (! $headers || $total === 0) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['file' => 'El archivo está vacío. La primera fila debe tener los títulos de las columnas.']);
        }

        $import = ContactImport::create([
            'user_id' => $request->user()->id,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'path' => $path,
            'kind' => $data['kind'],
            'edition_year' => $data['edition_year'] ?? null,
            'has_consent' => $data['has_consent'] ?? false,
            'headers' => $headers,
            'sample' => $sample,
            'mapping' => ImportFields::guess($headers),
            'total_rows' => $total,
        ]);
        AuditLog::record('contacts.uploaded', $import, ['filas' => $total, 'tipo' => $import->kind]);

        return to_route('contacts.import.show', $import);
    }

    public function show(ContactImport $import): Response
    {
        return Inertia::render('contacts/import/show', [
            'import' => [
                'id' => $import->id,
                'name' => $import->original_name,
                'kind' => $import->kind,
                'edition_year' => $import->edition_year,
                'has_consent' => $import->has_consent,
                'headers' => $import->headers,
                'sample' => $import->sample,
                'mapping' => $import->mapping,
                'status' => $import->status,
                'total_rows' => $import->total_rows,
                'created' => $import->created_count,
                'updated' => $import->updated_count,
                'skipped' => $import->skipped_count,
                'errors' => $import->errors ?? [],
                'finished_at' => $import->finished_at?->toIso8601String(),
            ],
            'fields' => ImportFields::LABELS,
        ]);
    }

    public function run(Request $request, ContactImport $import): RedirectResponse
    {
        abort_unless($import->status === ContactImport::STATUS_MAPPING, 409, 'Esta importación ya se ejecutó.');

        $columns = count($import->headers ?? []);
        $data = $request->validate([
            'mapping' => ['required', 'array', "max:{$columns}"],
            'mapping.*' => ['nullable', Rule::in(array_keys(ImportFields::LABELS))],
        ]);

        $mapping = array_slice(array_replace(array_fill(0, $columns, null), $data['mapping']), 0, $columns, true);
        $chosen = array_filter($mapping, fn ($field) => $field && $field !== ImportFields::DETAIL);

        if (count($chosen) !== count(array_unique($chosen))) {
            throw ValidationException::withMessages(['mapping' => 'Cada campo del CRM solo puede asignarse a una columna.']);
        }
        if (! array_intersect($chosen, ImportFields::IDENTIFIERS)) {
            throw ValidationException::withMessages(['mapping' => 'Asigna al menos una columna de correo, documento o teléfono para identificar a cada persona.']);
        }

        $import->update(['mapping' => $mapping, 'status' => ContactImport::STATUS_QUEUED]);
        AuditLog::record('contacts.imported', $import, ['file' => $import->original_name, 'rows' => $import->total_rows]);

        ImportContacts::dispatch($import);

        return to_route('contacts.import.show', $import);
    }
}
