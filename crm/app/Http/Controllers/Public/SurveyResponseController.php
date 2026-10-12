<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\SurveyInvitation;
use App\Services\Certificates\CertificatePdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Public page the survey email links to: the person answers once, without logging in.
 */
class SurveyResponseController extends Controller
{
    public function show(string $token): View
    {
        $invitation = $this->find($token);
        if (! $invitation) {
            return view('public.message', [
                'title' => 'No encontramos esta encuesta',
                'message' => 'Revisa que el enlace esté completo. Si lo copiaste del correo, ábrelo de nuevo desde el botón.',
            ]);
        }

        if (! $invitation->opened_at) {
            $invitation->forceFill(['opened_at' => now()])->save();
        }

        return $this->page($invitation);
    }

    public function store(Request $request, string $token): RedirectResponse|View
    {
        $invitation = $this->find($token);
        abort_unless($invitation !== null, 404);

        if ($invitation->responded_at) {
            return $this->page($invitation);
        }

        $rules = [];
        $names = [];
        foreach ($invitation->survey->questions as $q) {
            $field = "q.{$q['key']}";
            $names[$field] = mb_strtolower($q['label']);
            $rules[$field] = [
                $q['required'] ? 'required' : 'nullable',
                ...match ($q['type']) {
                    'rating' => ['integer', 'between:1,5'],
                    'nps' => ['integer', 'between:0,10'],
                    'choice' => ['string', Rule::in($q['options'] ?? [])],
                    default => ['string', 'max:2000'],
                },
            ];
        }
        $data = $request->validate($rules, ['required' => 'Responde esta pregunta.'], $names);

        $answers = collect($invitation->survey->questions)->mapWithKeys(function (array $q) use ($data) {
            $value = $data['q'][$q['key']] ?? null;
            if ($value === null || $value === '') {
                return [];
            }

            return [$q['key'] => in_array($q['type'], ['rating', 'nps'], true) ? (int) $value : trim((string) $value)];
        })->all();

        $invitation->update(['answers' => $answers, 'responded_at' => now()]);

        return to_route('surveys.public.show', $token);
    }

    private function page(SurveyInvitation $invitation): View
    {
        $survey = $invitation->survey;

        return view('public.survey', [
            'title' => $survey->title,
            'invitation' => $invitation,
            'survey' => $survey,
            'date' => $survey->activity_date ? app(CertificatePdf::class)->date($survey->activity_date) : null,
        ]);
    }

    private function find(string $token): ?SurveyInvitation
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        return SurveyInvitation::with('survey')->where('token', $token)->first();
    }
}
