<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\PluginConnection;
use App\Models\RegistrationParticipant;
use App\Services\Reports\EditionReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Reportes": the numbers of one fair edition. Read-only for every role that can open it.
 */
class ReportController extends Controller
{
    /** People shown in the dialog; the CSV always has everyone. */
    private const DIALOG_LIMIT = 500;

    public function index(Request $request): Response
    {
        $report = $this->report($request);

        return Inertia::render('reports/index', [
            'filters' => [
                'anio' => $report->year,
                'desde' => $report->from?->toDateString(),
                'hasta' => $report->to?->toDateString(),
            ],
            'editions' => array_values(array_unique([...EditionReport::editions(), $report->year])),
            'categories' => $report->overview(),
            'summary' => $report->summary(),
            'timeline' => $report->timeline(),
            'today' => now(CheckIn::TIMEZONE)->toDateString(),
            'lastSync' => PluginConnection::query()->first()?->last_synced_at?->toIso8601String(),
        ]);
    }

    public function category(Request $request, string $category): Response
    {
        abort_unless(isset(EditionReport::CATEGORIES[$category]), 404);
        $report = $this->report($request);

        return Inertia::render('reports/category', [
            'filters' => [
                'anio' => $report->year,
                'desde' => $report->from?->toDateString(),
                'hasta' => $report->to?->toDateString(),
            ],
            'editions' => array_values(array_unique([...EditionReport::editions(), $report->year])),
            'category' => ['key' => $category, 'title' => EditionReport::CATEGORIES[$category]['title']],
            'sections' => collect(EditionReport::CATEGORIES[$category]['sections'])
                ->map(fn ($key) => ['key' => $key, 'title' => EditionReport::SECTIONS[$key]])->values(),
            'today' => now(CheckIn::TIMEZONE)->toDateString(),
        ]);
    }

    public function section(Request $request, string $section): JsonResponse
    {
        abort_unless(isset(EditionReport::SECTIONS[$section]), 404);

        return response()->json(['cards' => $this->report($request)->section($section)]);
    }

    public function people(Request $request, string $section): JsonResponse
    {
        abort_unless(isset(EditionReport::SECTIONS[$section]), 404);
        $card = $this->card($request);
        $report = $this->report($request);
        $people = $report->people($section, $card);

        return response()->json([
            'title' => $report->cardLabel($section, $card),
            'total' => $people->count(),
            'people' => $people->take(self::DIALOG_LIMIT)->map(fn (RegistrationParticipant $p) => $this->row($report, $p, $section, $card))->values(),
        ]);
    }

    public function export(Request $request, string $section): StreamedResponse
    {
        abort_unless(isset(EditionReport::SECTIONS[$section]), 404);
        $card = $this->card($request);
        $report = $this->report($request);
        $people = $report->people($section, $card);
        $name = Str::slug(EditionReport::SECTIONS[$section].' '.$report->cardLabel($section, $card)).'-'.$report->year.'.csv';

        return response()->streamDownload(function () use ($report, $people, $section, $card) {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens accents correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nombre', 'Tipo doc.', 'Documento', 'País', 'Ciudad', 'Teléfono', 'Correo', 'Talla', 'Alimentación', 'Pedido', 'Fecha de compra', 'Activó registro']);
            foreach ($people as $p) {
                $row = $this->row($report, $p, $section, $card);
                fputcsv($out, [
                    $row['name'], $p->document_type, $p->document_number, $row['country'], $row['city'], $row['phone'], $row['email'],
                    $row['shirt_size'], EditionReport::MEALS[$p->meal_type] ?? $p->meal_type, $row['order'],
                    $p->registration->ordered_at?->setTimezone(CheckIn::TIMEZONE)->format('Y-m-d H:i'), $row['checked_in'] ? 'Sí' : 'No',
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(EditionReport $report, RegistrationParticipant $p, string $section, string $card): array
    {
        return [
            'id' => $p->id,
            'contact_id' => $p->contact_id,
            'name' => $p->full_name,
            'document' => trim("{$p->document_type} {$p->document_number}") ?: null,
            'country' => $report->country($p),
            'city' => $report->city($p),
            'phone' => $report->phone($p),
            'email' => $p->email,
            'shirt_size' => $p->shirt_size,
            'order' => $p->registration->number,
            'checked_in' => $report->checkedIn($p, $section, $card),
        ];
    }

    private function card(Request $request): string
    {
        return $request->validate(['tarjeta' => ['required', 'string', 'max:190']])['tarjeta'];
    }

    private function report(Request $request): EditionReport
    {
        $data = $request->validate([
            'anio' => ['nullable', 'integer', 'between:2000,2100'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', ...($request->filled('desde') ? ['after_or_equal:desde'] : [])],
        ]);

        // Default to the newest edition with sales; before the first sync, the current year.
        $year = (int) ($data['anio'] ?? (EditionReport::editions()[0] ?? now(CheckIn::TIMEZONE)->year));
        $day = fn (?string $d) => $d ? Carbon::createFromFormat('Y-m-d', $d, CheckIn::TIMEZONE)->startOfDay() : null;

        return new EditionReport($year, $day($data['desde'] ?? null), $day($data['hasta'] ?? null));
    }
}
