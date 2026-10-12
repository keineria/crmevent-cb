<?php

namespace App\Http\Controllers\Certificates;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CertificateCategory;
use App\Services\Certificates\CertificatePdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Certificados › Categoría: the design shared by every certificate of the category.
 */
class CertificateCategoryController extends Controller
{
    /** Images a category can carry: logo, a full-page design used as background, and one signature per signer. */
    private const FILE_KINDS = ['logo', 'fondo', 'firma-0', 'firma-1'];

    private const DIRECTORY = 'certificates';

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'activity_type' => ['required', Rule::in(array_keys(CertificateCategory::ACTIVITY_TYPES))],
        ], [], ['name' => 'nombre', 'activity_type' => 'tipo de actividad']);

        $category = CertificateCategory::create([
            ...CertificateCategory::DEFAULTS,
            ...$data,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        AuditLog::record('certificate.category_created', $category, ['tipo' => $data['activity_type']]);

        return to_route('certificates.categories.edit', $category)->with('success', 'Categoría creada. Ajusta su diseño y guárdalo.');
    }

    public function edit(CertificateCategory $category): Response
    {
        return Inertia::render('certificates/category', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'activity_type' => $category->activity_type,
                'template' => $category->template,
                'primary_color' => $category->primary_color,
                'accent_color' => $category->accent_color,
                'heading' => $category->heading,
                'intro' => $category->intro ?? '',
                'body' => $category->body ?? '',
                'show_qr' => $category->show_qr,
                'signatures' => collect(range(0, CertificateCategory::MAX_SIGNATURES - 1))->map(fn (int $i) => [
                    'name' => $category->signatures[$i]['name'] ?? '',
                    'role' => $category->signatures[$i]['role'] ?? '',
                ]),
                'files' => collect(self::FILE_KINDS)->mapWithKeys(fn (string $kind) => [
                    $kind => $this->path($category, $kind) ? route('certificates.categories.file', [$category, $kind, 'v' => substr(md5((string) $this->path($category, $kind)), 0, 8)]) : null,
                ]),
                'certificates' => $category->certificates()->count(),
                'updated_at' => $category->updated_at?->toIso8601String(),
            ],
            'templates' => CertificateCategory::TEMPLATES,
            'types' => CertificateCategory::ACTIVITY_TYPES,
            'fields' => CertificateCategory::FIELDS,
        ]);
    }

    public function update(Request $request, CertificateCategory $category): RedirectResponse
    {
        $data = $request->validate(self::rules(), [], self::attributes());
        $before = $category->only(array_keys($data));

        $category->update([
            ...collect($data)->except('signatures')->all(),
            'signatures' => $this->signatures($category, $data['signatures'] ?? []),
            'updated_by' => $request->user()->id,
        ]);

        $changed = collect($data)->except('signatures')->filter(fn ($value, $key) => $before[$key] != $value)->keys()->all();
        AuditLog::record('certificate.category_updated', $category, $changed ? ['campos' => $changed] : []);

        return back()->with('success', 'Diseño guardado. Los próximos certificados que se envíen o descarguen lo usan.');
    }

    public function destroy(CertificateCategory $category): RedirectResponse
    {
        if ($category->certificates()->whereHas('recipients', fn ($r) => $r->whereNotNull('sent_at'))->exists()) {
            return back()->with('error', 'Esta categoría ya tiene certificados enviados. Se conserva para que sigan siendo verificables.');
        }

        AuditLog::record('certificate.category_deleted', $category);
        $category->delete();

        return to_route('certificates.index')->with('success', "Se borró la categoría {$category->name}.");
    }

    /**
     * The sample certificate with the design as it is on screen, saved or not.
     */
    public function preview(Request $request, CertificateCategory $category, CertificatePdf $pdf): HttpResponse
    {
        $data = $request->validate(self::rules(), [], self::attributes());
        $data['signatures'] = $this->signatures($category, $data['signatures'] ?? []);

        return $this->inlinePdf($pdf->sample($category, null, $data), 'vista-previa.pdf');
    }

    public function upload(Request $request, CertificateCategory $category, string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, self::FILE_KINDS, true), 404);

        // No SVG: it can carry scripts. The background is a whole page, so it may be bigger.
        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg', $kind === 'fondo' ? 'max:6144' : 'max:2048', 'dimensions:max_width=5000,max_height=5000'],
        ], [
            'file.mimes' => 'Usa una imagen PNG o JPG.',
            'file.max' => $kind === 'fondo' ? 'El diseño de fondo debe pesar como máximo 6 MB.' : 'La imagen debe pesar como máximo 2 MB.',
        ]);

        $previous = $this->path($category, $kind);
        $path = $request->file('file')->storeAs(
            self::DIRECTORY,
            "categoria-{$category->id}-{$kind}-".now()->format('YmdHis').'.'.$request->file('file')->extension(),
            'local',
        );
        $this->setPath($category, $kind, $path);
        if ($previous && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }

        AuditLog::record('certificate.category_file', $category, ['imagen' => $kind, 'accion' => 'subió']);

        return back()->with('success', 'Imagen guardada.');
    }

    public function removeFile(CertificateCategory $category, string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, self::FILE_KINDS, true), 404);

        $previous = $this->path($category, $kind);
        $this->setPath($category, $kind, null);
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        AuditLog::record('certificate.category_file', $category, ['imagen' => $kind, 'accion' => 'quitó']);

        return back()->with('success', 'Imagen quitada.');
    }

    public function file(CertificateCategory $category, string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, self::FILE_KINDS, true), 404);
        $path = $this->path($category, $kind);
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function path(CertificateCategory $category, string $kind): ?string
    {
        return match ($kind) {
            'logo' => $category->logo_path,
            'fondo' => $category->background_path,
            default => $category->signatures[(int) substr($kind, 6)]['image_path'] ?? null,
        };
    }

    private function setPath(CertificateCategory $category, string $kind, ?string $path): void
    {
        match ($kind) {
            'logo' => $category->logo_path = $path,
            'fondo' => $category->background_path = $path,
            default => $category->signatures = $this->withSignatureImage($category, (int) substr($kind, 6), $path),
        };
        $category->updated_by = auth()->id();
        $category->save();
    }

    /**
     * @return list<array{name: string, role: string, image_path: string|null}>
     */
    private function withSignatureImage(CertificateCategory $category, int $index, ?string $path): array
    {
        $signatures = $this->signatures($category, $category->signatures ?? []);
        $signatures[$index]['image_path'] = $path;

        return $signatures;
    }

    /**
     * Names and roles as typed, keeping each signer's uploaded signature image.
     *
     * @param  array<int, array<string, mixed>>  $typed
     * @return list<array{name: string, role: string, image_path: string|null}>
     */
    private function signatures(CertificateCategory $category, array $typed): array
    {
        return array_map(fn (int $i) => [
            'name' => trim((string) ($typed[$i]['name'] ?? '')),
            'role' => trim((string) ($typed[$i]['role'] ?? '')),
            'image_path' => $category->signatures[$i]['image_path'] ?? null,
        ], range(0, CertificateCategory::MAX_SIGNATURES - 1));
    }

    private function inlinePdf(string $pdf, string $name): HttpResponse
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'activity_type' => ['required', Rule::in(array_keys(CertificateCategory::ACTIVITY_TYPES))],
            'template' => ['required', Rule::in(array_keys(CertificateCategory::TEMPLATES))],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'heading' => ['required', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:600'],
            'show_qr' => ['required', 'boolean'],
            'signatures' => ['array', 'max:'.CertificateCategory::MAX_SIGNATURES],
            'signatures.*.name' => ['nullable', 'string', 'max:80'],
            'signatures.*.role' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(): array
    {
        return [
            'name' => 'nombre', 'template' => 'diseño', 'primary_color' => 'color principal', 'accent_color' => 'color de acento',
            'heading' => 'título', 'intro' => 'frase de entrada', 'body' => 'texto', 'signatures.*.name' => 'nombre de quien firma',
            'signatures.*.role' => 'cargo',
        ];
    }
}
