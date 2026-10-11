<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Jobs\SendSubscriptionConfirmation;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Newsletter sign-ups from colombiabirdfair.com. Subscriptions use double opt-in (Ley 1581 de 2012):
 * the contact only becomes a subscriber after clicking the link in the confirmation email.
 */
class SubscriptionController extends Controller
{
    /** Avoid re-sending the confirmation email when someone submits the form twice in a row. */
    private const RESEND_AFTER_MINUTES = 10;

    public function store(Request $request): JsonResponse|RedirectResponse|View
    {
        // Honeypot: real visitors never see this field. Answer as if it worked so bots learn nothing.
        if (filled($request->input('website'))) {
            return $this->accepted($request);
        }

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:120'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Debes aceptar la política de tratamiento de datos.',
        ], ['email' => 'correo', 'first_name' => 'nombre']);

        $contact = Contact::firstOrNew(['email' => Str::lower(trim($data['email']))], ['source' => Contact::SOURCE_WEB]);

        foreach (['first_name', 'last_name', 'country', 'city'] as $field) {
            if (filled($data[$field] ?? null) && blank($contact->{$field})) {
                $contact->{$field} = Str::limit(trim($data[$field]), 120, '');
            }
        }
        $contact->is_interested = true;

        $alreadyConfirmed = $contact->is_subscriber && $contact->unsubscribed_at === null;
        $recentlySent = $contact->subscription_requested_at?->gt(now()->subMinutes(self::RESEND_AFTER_MINUTES));

        if (! $alreadyConfirmed && ! $recentlySent) {
            $contact->subscription_requested_at = now();
        }
        $contact->save();

        if (! $alreadyConfirmed && ! $recentlySent) {
            SendSubscriptionConfirmation::dispatch($contact);
        }

        return $this->accepted($request);
    }

    public function confirm(Request $request, Contact $contact): View
    {
        if (! $contact->is_subscriber || $contact->unsubscribed_at !== null) {
            $contact->grantConsent('Formulario web (doble confirmación)', $request->ip());
            $contact->forceFill(['is_subscriber' => true, 'subscribed_at' => now()])->save();
        }

        return view('public.message', [
            'title' => '¡Suscripción confirmada!',
            'message' => 'Gracias. Te enviaremos las novedades de Colombia Birdfair. Puedes darte de baja en cualquier momento desde el enlace al final de cada correo.',
        ]);
    }

    private function accepted(Request $request): JsonResponse|RedirectResponse|View
    {
        $message = 'Revisa tu correo y confirma la suscripción con el enlace que te enviamos.';

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }

        $redirect = (string) $request->input('redirect');
        if ($redirect !== '' && in_array(parse_url($redirect, PHP_URL_HOST), config('crm.form_hosts'), true)) {
            return redirect()->away($redirect);
        }

        return view('public.message', ['title' => 'Ya casi', 'message' => $message]);
    }
}
