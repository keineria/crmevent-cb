<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/branding', [
            'logos' => collect(Branding::KINDS)->mapWithKeys(fn (string $kind) => [$kind => [
                'url' => Branding::url($kind),
                // False when the login or email logo is borrowed from the platform logo.
                'own' => Branding::path($kind) !== null,
            ]]),
        ]);
    }

    public function update(Request $request, string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, Branding::KINDS, true), 404);

        // Email clients like Outlook don't show WebP, so the email logo is PNG or JPG only. No SVG anywhere: it can carry scripts.
        $types = $kind === Branding::EMAIL ? 'png,jpg,jpeg' : 'png,jpg,jpeg,webp';
        $request->validate([
            'logo' => ['required', 'file', 'image', "mimes:{$types}", 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ], [
            'logo.mimes' => $kind === Branding::EMAIL ? 'Usa una imagen PNG o JPG.' : 'Usa una imagen PNG, JPG o WebP.',
            'logo.max' => 'El logo debe pesar como máximo 2 MB.',
        ]);

        Branding::store($kind, $request->file('logo'));
        AuditLog::record('branding.logo_uploaded', null, ['kind' => $kind]);

        return back()->with('success', 'Logo actualizado.');
    }

    public function destroy(string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, Branding::KINDS, true), 404);

        Branding::remove($kind);
        AuditLog::record('branding.logo_removed', null, ['kind' => $kind]);

        return back()->with('success', 'Logo quitado.');
    }

    /**
     * Public: the login page and emails show it to people who aren't signed in.
     */
    public function show(string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, Branding::KINDS, true), 404);
        $path = Branding::resolvedPath($kind);
        abort_unless($path !== null, 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
