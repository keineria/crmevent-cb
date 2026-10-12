<?php

namespace App\Http\Controllers\Certificates;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CertificateCategory;
use App\Models\CertificateSigner;
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
    /** Images a category can carry: logo and a full-page design used as background (the whole certificate in "propio"). */
    private const FILE_KINDS = ['logo', 'fondo'];

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
                'signer_ids' => array_values(array_map('intval', $category->signer_ids ?? [])),
                'layout' => CertificateCategory::normalizeLayout($category->layout),
                'files' => collect(self::FILE_KINDS)->mapWithKeys(fn (string $kind) => [
                    $kind => $this->path($category, $kind) ? route('certificates.categories.file', [$category, $kind, 'v' => substr(md5((string) $this->path($category, $kind)), 0, 8)]) : null,
                ]),
                'certificates' => $category->certificates()->count(),
                'updated_at' => $category->updated_at?->toIso8601String(),
            ],
            'templates' => CertificateCategory::TEMPLATES,
            'types' => CertificateCategory::ACTIVITY_TYPES,
            'fields' => CertificateCategory::FIELDS,
            'blocks' => CertificateCategory::BLOCKS,
            'fonts' => CertificateCategory::FONTS,
            'maxSigners' => CertificateCategory::MAX_SIGNERS,
            'signers' => CertificateSigner::orderBy('name')->get()->map(fn (CertificateSigner $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'role' => $s->role,
                'image' => $s->image_path ? route('certificates.signers.image', [$s, 'v' => substr(md5($s->image_path), 0, 8)]) : null,
            ]),
        ]);
    }

    public function update(Request $request, CertificateCategory $category): RedirectResponse
    {
        $data = self::clean($request->validate(self::rules(), [], self::attributes()));
        $before = $category->only(array_keys($data));

        $category->update([...$data, 'updated_by' => $request->user()->id]);

        $changed = collect($data)->filter(fn ($value, $key) => $before[$key] != $value)->keys()->all();
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
        $data = self::clean($request->validate(self::rules(), [], self::attributes()));

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
        return $kind === 'logo' ? $category->logo_path : $category->background_path;
    }

    private function setPath(CertificateCategory $category, string $kind, ?string $path): void
    {
        if ($kind === 'logo') {
            $category->logo_path = $path;
        } else {
            $category->background_path = $path;
        }
        $category->updated_by = auth()->id();
        $category->save();
    }

    /**
     * Signer ids as integers without repeats, and the layout with every block.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function clean(array $data): array
    {
        $data['signer_ids'] = array_values(array_unique(array_map('intval', $data['signer_ids'] ?? [])));
        $data['layout'] = CertificateCategory::normalizeLayout($data['layout'] ?? null);

        return $data;
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
            'signer_ids' => ['array', 'max:'.CertificateCategory::MAX_SIGNERS],
            'signer_ids.*' => ['integer', 'exists:certificate_signers,id'],
            'layout' => ['nullable', 'array'],
            'layout.*.x' => ['numeric', 'min:-20', 'max:297'],
            'layout.*.y' => ['numeric', 'min:-20', 'max:210'],
            'layout.*.w' => ['numeric', 'min:10', 'max:297'],
            'layout.*.size' => ['numeric', 'min:5', 'max:120'],
            'layout.*.color' => ['regex:/^#[0-9A-Fa-f]{6}$/'],
            'layout.*.align' => [Rule::in(['left', 'center', 'right'])],
            'layout.*.font' => [Rule::in(array_keys(CertificateCategory::FONTS))],
            'layout.*.visible' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(): array
    {
        return [
            'name' => 'nombre', 'template' => 'diseño', 'primary_color' => 'color principal', 'accent_color' => 'color de acento',
            'heading' => 'título', 'intro' => 'frase de entrada', 'body' => 'texto', 'signer_ids' => 'firmas',
            'signer_ids.*' => 'firma', 'layout.*.color' => 'color', 'layout.*.size' => 'tamaño',
        ];
    }
}
