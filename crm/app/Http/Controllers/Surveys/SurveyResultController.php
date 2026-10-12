<?php

namespace App\Http\Controllers\Surveys;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\EventActivity;
use App\Models\Survey;
use App\Models\SurveyInvitation;
use App\Services\Reports\EditionReport;
use App\Services\Surveys\SurveyDispatcher;
use App\Services\Surveys\SurveyResults;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes › Encuestas: satisfaction of every activity of an edition, and the answers of each survey.
 */
class SurveyResultController extends Controller
{
    public function __construct(private SurveyResults $results) {}

    public function index(Request $request): Response
    {
        $years = Survey::whereNotNull('activity_date')->selectRaw('distinct activity_date')->pluck('activity_date')
            ->map(fn ($d) => (int) substr((string) $d, 0, 4))->unique()->sortDesc()->values()->all();
        $year = (int) ($request->validate(['anio' => ['nullable', 'integer', 'between:2000,2100']])['anio']
            ?? ($years[0] ?? EditionReport::editions()[0] ?? now(CheckIn::TIMEZONE)->year));

        return Inertia::render('reports/surveys', [
            'year' => $year,
            'years' => array_values(array_unique([...$years, $year])),
            ...$this->results->edition($year),
            'types' => EventActivity::TYPE_LABELS,
            'canManage' => $request->user()->hasAnyRole(Roles::SURVEY_MANAGERS),
        ]);
    }

    public function show(Request $request, Survey $survey): Response
    {
        $invitations = $survey->invitations()->orderBy('full_name')->get();
        $canManage = $request->user()->hasAnyRole(Roles::SURVEY_MANAGERS);

        return Inertia::render('reports/survey', [
            'survey' => [
                'id' => $survey->id,
                'title' => $survey->title,
                'activity_name' => $survey->activity_name,
                'activity_type' => $survey->activity_type,
                'activity_date' => $survey->activity_date?->toDateString(),
                'source' => $survey->source,
                'require_check_in' => $survey->require_check_in,
                'created_at' => $survey->created_at->toIso8601String(),
            ],
            'totals' => $this->results->totals($survey, $invitations),
            'questions' => $this->results->questions($survey),
            // Who it went to and whether they answered; what each person answered is not shown.
            'people' => $invitations->map(fn (SurveyInvitation $i) => [
                'id' => $i->id,
                'name' => $i->full_name,
                'email' => $i->email,
                'queued' => $i->isQueued(),
                'sent_at' => $i->sent_at?->toIso8601String(),
                'failed_at' => $i->failed_at?->toIso8601String(),
                'error' => $canManage ? $i->error : null,
                'opened' => $i->opened_at !== null,
                'responded' => $i->responded_at !== null,
            ]),
            'types' => EventActivity::TYPE_LABELS,
            'canManage' => $canManage,
            'mailReady' => SurveyDispatcher::mailReady(),
        ]);
    }

    /**
     * One row per answer, without names, for Excel.
     */
    public function export(Survey $survey): StreamedResponse
    {
        $name = 'encuesta-'.Str::slug($survey->activity_name).'.csv';
        AuditLog::record('survey.exported', $survey, ['archivo' => $name]);

        return response()->streamDownload(function () use ($survey) {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens accents correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Fecha de respuesta', ...array_column($survey->questions, 'label')]);
            $survey->invitations()->whereNotNull('responded_at')->orderBy('responded_at')->each(function (SurveyInvitation $i) use ($out, $survey) {
                fputcsv($out, [
                    $i->responded_at->setTimezone(CheckIn::TIMEZONE)->format('Y-m-d H:i'),
                    ...array_map(fn ($q) => $i->answers[$q['key']] ?? '', $survey->questions),
                ]);
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
