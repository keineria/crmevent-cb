<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Unsubscribe link included in every broadcast. The POST also serves one-click unsubscribe
 * (RFC 8058) from Gmail and Outlook, so it is exempt from CSRF and needs no session.
 */
class UnsubscribeController extends Controller
{
    public function show(string $token): View
    {
        $contact = Contact::where('unsubscribe_token', $token)->firstOrFail();

        return view('public.unsubscribe', ['contact' => $contact, 'token' => $token]);
    }

    public function store(string $token): View|Response
    {
        $contact = Contact::where('unsubscribe_token', $token)->firstOrFail();
        $contact->unsubscribe();

        if (request()->input('List-Unsubscribe') === 'One-Click') {
            return response()->noContent();
        }

        return view('public.message', [
            'title' => 'Listo, te diste de baja',
            'message' => 'No recibirás más boletines ni difusiones de Colombia Birdfair. Seguirás recibiendo los correos de tus compras e inscripciones.',
        ]);
    }
}
