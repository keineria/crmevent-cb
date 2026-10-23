<?php

namespace App\Services\Stands;

use App\Models\StandWorkbook;
use Illuminate\Support\Str;

/**
 * The sold stands of a book: every row with a stand number and a name under the "Nombre del Stand" header,
 * grouped by the zone rows in between (e.g. "ZONA EXTERIOR"), with the team's follow-up marks.
 */
class StandPurchases
{
    /** Header columns copied next to each sold stand, when the sheet has them. */
    private const DETAIL_HEADERS = ['email', 'correo', 'telefono', 'celular', 'precio', 'valor', 'abono', 'pago', 'saldo', 'maneja', 'responsable', 'contrato', 'factura'];

    /**
     * @return array{columns: list<string>, stands: list<array<string, mixed>>}
     */
    public static function for(StandWorkbook $workbook): array
    {
        $marks = $workbook->purchases()->with('editor:id,name')->get()->keyBy('stand_key');
        $columns = [];
        $stands = [];

        foreach ($workbook->sheets ?? [] as $sheet) {
            $header = self::header($sheet['rows']);
            if ($header === null) {
                continue;
            }
            [$headerRow, $numberColumn, $nameColumn, $details] = $header;
            foreach (array_keys($details) as $label) {
                if (! in_array($label, $columns, true)) {
                    $columns[] = $label;
                }
            }

            $zone = null;
            foreach (array_slice($sheet['rows'], $headerRow + 1, null, true) as $r => $row) {
                $number = trim((string) ($row[$numberColumn]['v'] ?? ''));
                $filled = array_values(array_filter($row, fn ($c) => $c !== null && trim((string) $c['v']) !== ''));
                // A row with a single text, like "ZONA EXTERIOR", is the title of the zone below it.
                if (count($filled) === 1 && ($filled[0]['k'] ?? 's') === 's' && ! is_numeric($filled[0]['v']) && empty($filled[0]['f'])) {
                    $zone = trim((string) $filled[0]['v']);

                    continue;
                }
                if ($number === '') {
                    continue;
                }

                $name = trim((string) ($row[$nameColumn]['v'] ?? ''));
                if ($name === '' || ($row[$nameColumn]['f'] ?? null)) {
                    continue;
                }

                $key = self::key($sheet['name'], $zone, $number);
                $mark = $marks->get($key);
                $stands[] = [
                    'key' => $key,
                    'sheet' => $sheet['name'],
                    'zone' => $zone,
                    'number' => $number,
                    'name' => $name,
                    'row' => $r + 1,
                    'details' => array_map(fn (int $c) => (string) ($row[$c]['v'] ?? ''), $details),
                    'paid' => (bool) $mark?->paid,
                    'documents' => (bool) $mark?->documents,
                    'invoiced' => (bool) $mark?->invoiced,
                    'updated_by' => $mark?->editor?->name,
                    'updated_at' => $mark?->updated_at?->toIso8601String(),
                ];
            }
        }

        // Each stand's details in the order of the merged column list.
        foreach ($stands as &$stand) {
            $stand['details'] = array_map(fn (string $label) => $stand['details'][$label] ?? '', $columns);
        }

        return ['columns' => $columns, 'stands' => $stands];
    }

    /** Whether a stand of this book (by its key) is currently sold. */
    public static function find(StandWorkbook $workbook, string $key): ?array
    {
        return collect(self::for($workbook)['stands'])->firstWhere('key', $key);
    }

    public static function key(string $sheet, ?string $zone, string $number): string
    {
        return Str::limit(Str::lower(Str::ascii($sheet)).'|'.Str::lower(Str::ascii((string) $zone)).'|'.Str::lower(Str::ascii($number)), 188, '');
    }

    /**
     * Finds the header row by its "Nombre del Stand" cell, in the first rows of the sheet.
     *
     * @param  list<list<array<string, mixed>|null>>  $rows
     * @return array{0: int, 1: int, 2: int, 3: array<string, int>}|null [row, number column, name column, detail label => column]
     */
    private static function header(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 30, true) as $r => $row) {
            $labels = array_map(fn ($c) => $c ? self::normalize(strtok((string) $c['v'], "\n") ?: '') : '', $row);
            $nameColumn = null;
            foreach ($labels as $c => $label) {
                if (str_starts_with($label, 'nombre del stand') || $label === 'nombre stand') {
                    $nameColumn = $c;
                    break;
                }
            }
            if ($nameColumn === null) {
                continue;
            }

            $numberColumn = null;
            foreach ($labels as $c => $label) {
                if ($c !== $nameColumn && (preg_match('/^(stand|n(o|umero)\.?)\s*(#|no\.?|numero)?$/', $label) || $label === '#')) {
                    $numberColumn = $c;
                    break;
                }
            }
            $numberColumn ??= max(0, $nameColumn - 1);

            $details = [];
            foreach ($labels as $c => $label) {
                if ($c === $nameColumn || $c === $numberColumn || $label === '') {
                    continue;
                }
                foreach (self::DETAIL_HEADERS as $wanted) {
                    if (str_contains($label, $wanted)) {
                        $details[trim(strtok((string) $row[$c]['v'], "\n"))] = $c;
                        break;
                    }
                }
            }

            return [$r, $numberColumn, $nameColumn, $details];
        }

        return null;
    }

    private static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))));
    }
}
