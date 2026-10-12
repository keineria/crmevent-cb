<?php

namespace App\Http\Controllers\Certificates;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CertificateCategory;
use App\Models\CertificateSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Certificados › Firmantes: the people who sign certificates, with an image of their signature.
 */
class CertificateSignerController extends Controller
{
    public function index(): Response
    {
        $categories = CertificateCategory::orderBy('name')->get(['id', 'name', 'signer_ids']);

        return Inertia::render('certificates/signers', [
            'signers' => CertificateSigner::orderBy('name')->get()->map(fn (CertificateSigner $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'role' => $s->role,
                'image' => $s->image_path ? route('certificates.signers.image', [$s, 'v' => substr(md5($s->image_path), 0, 8)]) : null,
                'categories' => $categories->filter(fn (CertificateCategory $c) => in_array($s->id, array_map('intval', $c->signer_ids ?? []), true))
                    ->pluck('name')->values(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(self::rules(), self::messages(), self::attributes());

        $signer = CertificateSigner::create([
            'name' => $data['name'],
            'role' => $data['role'] ?? null,
            'image_path' => $request->hasFile('image') ? $this->storeImage($request) : null,
            'created_by' => $request->user()->id,
        ]);
        AuditLog::record('certificate.signer_created', $signer);

        return back()->with('success', "{$signer->name} quedó como firmante. Elígelo en el diseño de cada categoría.");
    }

    public function update(Request $request, CertificateSigner $signer): RedirectResponse
    {
        $data = $request->validate(self::rules(), self::messages(), self::attributes());
        $previous = $signer->image_path;

        $signer->fill(['name' => $data['name'], 'role' => $data['role'] ?? null]);
        if ($request->hasFile('image')) {
            $signer->image_path = $this->storeImage($request);
        } elseif ($request->boolean('remove_image')) {
            $signer->image_path = null;
        }
        $signer->save();

        if ($previous && $previous !== $signer->image_path) {
            Storage::disk('local')->delete($previous);
        }
        AuditLog::record('certificate.signer_updated', $signer, ['campos' => array_keys(array_diff_key($signer->getChanges(), ['updated_at' => 1]))]);

        return back()->with('success', 'Firmante actualizado. Los certificados que se envíen o descarguen desde ahora lo usan.');
    }

    public function destroy(CertificateSigner $signer): RedirectResponse
    {
        foreach ($signer->categories() as $category) {
            $category->update(['signer_ids' => array_values(array_diff(array_map('intval', $category->signer_ids ?? []), [$signer->id]))]);
        }

        AuditLog::record('certificate.signer_deleted', $signer);
        $signer->delete();

        return back()->with('success', "Se borró a {$signer->name} como firmante.");
    }

    public function image(CertificateSigner $signer): StreamedResponse
    {
        abort_unless($signer->image_path && Storage::disk('local')->exists($signer->image_path), 404);

        return Storage::disk('local')->response($signer->image_path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function storeImage(Request $request): string
    {
        $file = $request->file('image');

        return $file->storeAs('certificates', 'firma-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(4)).'.'.$file->extension(), 'local');
    }

    /**
     * @return array<string, list<string>>
     */
    private static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'role' => ['nullable', 'string', 'max:80'],
            // No SVG: it can carry scripts.
            'image' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return ['image.mimes' => 'Usa una imagen PNG o JPG.', 'image.max' => 'La firma debe pesar como máximo 2 MB.'];
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(): array
    {
        return ['name' => 'nombre', 'role' => 'cargo', 'image' => 'firma'];
    }
}
