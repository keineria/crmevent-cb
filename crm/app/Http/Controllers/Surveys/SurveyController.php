<?php

namespace App\Http\Controllers\Surveys;

use App\Http\Controllers\Controller;
use App\Jobs\SendSurvey;
use App\Models\AuditLog;
use App\Models\EventActivity;
use App\Models\Survey;
use App\Models\SurveyForm;
use App\Models\SurveyInvitation;
use App\Services\Surveys\SurveyDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Encuestas: the questions of each activity type, when they go out, and each activity's survey.
 */
class SurveyController extends Controller
{
    private const NO_MAIL = 'Primero configura y verifica el correo transaccional en Configuración › Correo de envíos.';

    public function __construct(private SurveyDispatcher $dispatcher) {}

    public function index(): Response
    {
        $forms = SurveyForm::all()->keyBy('activity_type');
        $surveys = Survey::withCount([
            'invitations',
            'invitations as sent_count' => fn ($q) => $q->whereNotNull('sent_at'),
            'invitations as responded_count' => fn ($q) => $q->whereNotNull('responded_at'),
        ])->get()->keyBy('evr_activity_id');

        $activities = EventActivity::whereIn('type', array_keys(EventActivity::TYPE_LABELS))
            ->where('status', '!=', 'archived')
            ->orderByDesc('event_date')->orderBy('start_time')->orderBy('name')
            ->get(['evr_id', 'type', 'name', 'event_date', 'start_time', 'end_time'])
            ->map(function (EventActivity $a) use ($forms, $surveys) {
                $form = $forms->get($a->type);
                $survey = $surveys->get($a->evr_id);
                $end = SurveyForm::activityEnd($a);

                return [
                    'id' => $a->evr_id,
                    'type' => $a->type,
                    'name' => $a->name,
                    'date' => $a->event_date?->toDateString(),
                    'start_time' => $a->start_time ? substr($a->start_time, 0, 5) : null,
                    'end_time' => $a->end_time ? substr($a->end_time, 0, 5) : null,
                    'ended' => $end !== null && $end->isPast(),
                    // When it goes out on its own, if it will.
                    'sends_at' => ! $survey && $form?->enabled && $end && (! $form->enabled_at || $end->gte($form->enabled_at))
                        && $form->sendsAt($a)->gte(now()->subHours(SurveyDispatcher::MAX_LATE_HOURS))
                        ? $form->sendsAt($a)->toIso8601String() : null,
                    'survey' => $survey ? [
                        'id' => $survey->id,
                        'source' => $survey->source,
                        'created_at' => $survey->created_at->toIso8601String(),
                        'invited' => $survey->invitations_count,
                        'sent' => $survey->sent_count,
                        'responded' => $survey->responded_count,
                    ] : null,
                ];
            });

        return Inertia::render('surveys/index', [
            'forms' => collect(EventActivity::TYPE_LABELS)->map(fn ($label, $type) => [
                'type' => $type,
                'label' => $label,
                'enabled' => (bool) $forms->get($type)?->enabled,
                'title' => $forms->get($type)?->title,
                'questions' => count($forms->get($type)?->questions ?? []),
                'delay_minutes' => $forms->get($type)?->delay_minutes,
                'require_check_in' => (bool) $forms->get($type)?->require_check_in,
            ])->values(),
            'activities' => $activities,
            'types' => EventActivity::TYPE_LABELS,
            'mailReady' => SurveyDispatcher::mailReady(),
            'perHour' => (int) config('crm.survey_emails_per_hour'),
        ]);
    }

    public function edit(string $type): Response
    {
        $form = $this->form($type);

        return Inertia::render('surveys/form', [
            'form' => [
                'type' => $form->activity_type,
                'label' => EventActivity::TYPE_LABELS[$form->activity_type],
                'title' => $form->title,
                'intro' => $form->intro,
                'questions' => $form->questions,
                'enabled' => $form->enabled,
                'delay_minutes' => $form->delay_minutes,
                'require_check_in' => $form->require_check_in,
                'updated_at' => $form->updated_at?->toIso8601String(),
            ],
            'defaults' => SurveyForm::DEFAULTS[$form->activity_type]['questions'],
            'questionTypes' => SurveyForm::QUESTION_TYPES,
        ]);
    }

    public function update(Request $request, string $type): RedirectResponse
    {
        $form = $this->form($type);
        $validator = validator($request->all(), [
            'title' => ['required', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:500'],
            'enabled' => ['required', 'boolean'],
            'delay_minutes' => ['required', 'integer', 'between:0,4320'],
            'require_check_in' => ['required', 'boolean'],
            'questions' => ['required', 'array', 'min:1', 'max:20'],
            'questions.*.key' => ['nullable', 'string', 'max:40'],
            'questions.*.type' => ['required', Rule::in(array_keys(SurveyForm::QUESTION_TYPES))],
            'questions.*.label' => ['required', 'string', 'max:200'],
            'questions.*.required' => ['required', 'boolean'],
            'questions.*.options' => ['nullable', 'array', 'max:10'],
            'questions.*.options.*' => ['nullable', 'string', 'max:80'],
        ], ['questions.*.label.required' => 'Escribe la pregunta.'], ['delay_minutes' => 'espera', 'title' => 'título']);

        $validator->after(function (Validator $v) use ($request) {
            foreach ((array) $request->input('questions') as $i => $q) {
                if (($q['type'] ?? null) === 'choice' && count(array_filter((array) ($q['options'] ?? []), 'filled')) < 2) {
                    $v->errors()->add("questions.{$i}.options", 'Una pregunta de opción única necesita al menos dos opciones.');
                }
            }
        });
        $data = $validator->validate();

        // Keys stay as they were, so answers keep pointing at the same question; new ones get a key from their text.
        $used = [];
        $questions = collect($data['questions'])->map(function (array $q) use (&$used) {
            $key = Str::of($q['key'] ?? '')->slug('_')->limit(40, '')->toString() ?: Str::of($q['label'])->slug('_')->limit(30, '')->toString() ?: 'pregunta';
            $base = $key;
            for ($n = 2; in_array($key, $used, true); $n++) {
                $key = "{$base}_{$n}";
            }
            $used[] = $key;

            return array_filter([
                'key' => $key,
                'type' => $q['type'],
                'label' => trim($q['label']),
                'required' => (bool) $q['required'],
                'options' => $q['type'] === 'choice' ? array_values(array_unique(array_map('trim', array_filter($q['options'] ?? [], 'filled')))) : null,
            ], fn ($v) => $v !== null);
        })->values()->all();

        $turnedOn = $data['enabled'] && ! $form->enabled;
        $form->update([
            'title' => $data['title'],
            'intro' => $data['intro'] ?? null,
            'enabled' => $data['enabled'],
            'enabled_at' => $turnedOn ? now() : $form->enabled_at,
            'delay_minutes' => $data['delay_minutes'],
            'require_check_in' => $data['require_check_in'],
            'questions' => $questions,
            'updated_by' => $request->user()->id,
        ]);
        AuditLog::record('survey.form_updated', $form, ['tipo' => EventActivity::TYPE_LABELS[$type]]);

        return back()->with('success', 'Encuesta guardada. Las actividades que terminen desde ahora usan estas preguntas.');
    }

    /**
     * Turns the automatic send of one activity type on or off. Turning it on never reaches activities that already ended.
     */
    public function toggle(Request $request, string $type): RedirectResponse
    {
        $form = $this->form($type);
        $on = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $form->update(['enabled' => $on, 'enabled_at' => $on && ! $form->enabled ? now() : $form->enabled_at, 'updated_by' => $request->user()->id]);
        AuditLog::record($on ? 'survey.enabled' : 'survey.disabled', $form, ['tipo' => EventActivity::TYPE_LABELS[$type]]);

        return back()->with('success', $on
            ? 'Listo: la encuesta sale sola cuando termine cada actividad de este tipo.'
            : 'Encuesta automática apagada para este tipo de actividad.');
    }

    /**
     * Enviar ahora: opens the survey of an activity that already ended and mails it.
     */
    public function open(Request $request, int $activity): RedirectResponse
    {
        if (! SurveyDispatcher::mailReady()) {
            return back()->with('error', self::NO_MAIL);
        }

        $activity = EventActivity::where('evr_id', $activity)->firstOrFail();
        $end = SurveyForm::activityEnd($activity);
        if (! $end || $end->isFuture()) {
            return back()->with('error', 'La encuesta se puede enviar cuando la actividad termine.');
        }

        $survey = $this->dispatcher->open($activity, $this->form($activity->type), Survey::SOURCE_MANUAL, $request->user());
        $count = $this->dispatcher->send($survey);
        AuditLog::record('survey.sent', $survey, ['correos' => $count]);

        return back()->with($count ? 'success' : 'error', $count
            ? "Enviando la encuesta a {$count} personas, ".config('crm.survey_emails_per_hour').' por hora.'
            : 'No hay a quién enviarla: nadie hizo check-in en esta actividad o no tienen correo.');
    }

    /**
     * Sends it to people who qualify and have not got it (late check-ins, or after fixing a failed send).
     */
    public function send(Survey $survey): RedirectResponse
    {
        if (! SurveyDispatcher::mailReady()) {
            return back()->with('error', self::NO_MAIL);
        }

        $survey->invitations()->whereNotNull('failed_at')->whereNull('sent_at')->update(['failed_at' => null]);
        $count = $this->dispatcher->send($survey);
        if ($count) {
            AuditLog::record('survey.sent', $survey, ['correos' => $count]);
        }

        return back()->with($count ? 'success' : 'error', $count ? "Enviando la encuesta a {$count} personas más." : 'No hay encuestas pendientes por enviar en esta actividad.');
    }

    public function resend(SurveyInvitation $invitation): RedirectResponse
    {
        if (! SurveyDispatcher::mailReady()) {
            return back()->with('error', self::NO_MAIL);
        }
        if (! $invitation->email) {
            return back()->with('error', "{$invitation->full_name} no tiene correo, ni la persona que compró.");
        }

        SendSurvey::dispatch($invitation, resend: true);
        $invitation->update(['queued_at' => now(), 'failed_at' => null, 'error' => null]);
        AuditLog::record('survey.resent', $invitation->survey, ['correo' => $invitation->email]);

        return back()->with('success', "Enviando la encuesta a {$invitation->email}.");
    }

    private function form(string $type): SurveyForm
    {
        abort_unless(isset(EventActivity::TYPE_LABELS[$type]), 404);

        return SurveyForm::firstOrCreate(['activity_type' => $type], [
            ...collect(SurveyForm::DEFAULTS[$type])->only(['title', 'intro', 'questions'])->all(),
            'enabled' => false,
        ]);
    }
}
