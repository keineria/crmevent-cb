<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\HumanCheck;
use App\Services\Portal\ParticipantPortal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Inscritos": participants check their own registration with their document number. Read only.
 */
class PortalController extends Controller
{
    /** How long a result stays on screen before the document has to be typed again. */
    private const RESULT_MINUTES = 15;

    /** Lookups of one document in ten minutes, so nobody walks through someone else's number by retrying. */
    private const LOOKUPS_PER_DOCUMENT = 5;

    public function __construct(private ParticipantPortal $portal, private HumanCheck $human) {}

    public function show(Request $request): Response
    {
        $lookup = $request->session()->get(ParticipantPortal::SESSION_KEY);
        $fresh = is_array($lookup) && ($lookup['at'] ?? 0) > now()->subMinutes(self::RESULT_MINUTES)->getTimestamp();
        $people = $fresh ? $this->portal->lookup((string) $lookup['document']) : [];

        if (! $people) {
            $request->session()->forget(ParticipantPortal::SESSION_KEY);
        }

        return Inertia::render('portal/index', [
            'people' => $people,
            'challenge' => $people ? null : $this->human->challenge($request),
            'helpEmail' => config('crm.portal_help_email'),
        ]);
    }

    public function lookup(Request $request): RedirectResponse
    {
        // Honeypot: people never see this field.
        if (filled($request->input('website'))) {
            return back()->with('error', 'No encontramos una inscripción pagada con ese documento.');
        }

        $data = $request->validate([
            'document' => ['required', 'string', 'min:4', 'max:32', 'regex:/^[A-Za-z0-9.\-\s]+$/'],
            'human' => ['required', 'string', 'max:4096'],
        ], [
            'document.regex' => 'Escribe solo el número del documento, sin otros signos.',
            'human.required' => 'Confirma que no eres un robot.',
        ], ['document' => 'número de documento']);

        if (! $this->human->passes($request, $data['human'])) {
            return back()->withErrors(['human' => 'La verificación no es correcta. Inténtalo de nuevo.'])->withInput($request->only('document'));
        }

        $document = ParticipantPortal::normalize($data['document']);
        $key = 'portal-lookup:'.sha1($document);
        if (RateLimiter::tooManyAttempts($key, self::LOOKUPS_PER_DOCUMENT)) {
            return back()->with('error', 'Demasiadas consultas para este documento. Espera unos minutos e intenta de nuevo.');
        }
        RateLimiter::hit($key, 600);

        if (! $this->portal->lookup($document)) {
            return back()->with('error', 'No encontramos una inscripción pagada con ese documento. Revisa el número; si pagaste hace poco, puede tardar unos minutos en aparecer.')
                ->withInput($request->only('document'));
        }

        $request->session()->put(ParticipantPortal::SESSION_KEY, ['document' => $document, 'at' => now()->getTimestamp()]);

        return redirect()->route('portal.show');
    }

    public function clear(Request $request): RedirectResponse
    {
        $request->session()->forget(ParticipantPortal::SESSION_KEY);

        return redirect()->route('portal.show');
    }
}
