<?php

namespace App\Services\Stands;

use App\Models\StandWorkbook;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Throwable;

/**
 * A Stand comercial book that is one tab of a Google Sheet: pulls it into the same grid the Excel books use,
 * and writes edits straight to Google, which recalculates the formulas.
 */
class GoogleStandSheet
{
    public function __construct(private GoogleSheetsClient $client) {}

    /** Reads the tab again. Errors are kept on the book so the page can show them. */
    public function pull(StandWorkbook $workbook): bool
    {
        try {
            $sheet = self::snapshot($this->client->grid($workbook->google_spreadsheet_id, $workbook->google_sheet));
        } catch (Throwable $e) {
            $workbook->forceFill(['sync_error' => $e->getMessage()])->save();

            return false;
        }

        $workbook->forceFill(['sheets' => [$sheet], 'synced_at' => now(), 'sync_error' => null])->save();

        return true;
    }

    /**
     * @return array{antes: string, despues: string, hoja: string}
     */
    public function setValue(StandWorkbook $workbook, string $coordinate, ?string $input): array
    {
        return Cache::lock("stand-workbook-{$workbook->id}", 30)->block(15, function () use ($workbook, $coordinate, $input) {
            [$column, $row] = Coordinate::indexesFromString($coordinate);
            $cell = $workbook->sheets[0]['rows'][$row - 1][$column - 1] ?? null;

            // Someone may have added a formula in Drive since the last read.
            $current = $this->client->rawCell($workbook->google_spreadsheet_id, $workbook->google_sheet, $coordinate);
            if (($cell['f'] ?? null) || str_starts_with((string) $current, '=')) {
                throw new DomainException("La celda {$coordinate} tiene una fórmula y se calcula sola.");
            }

            $text = trim((string) $input);
            if (str_starts_with($text, '=')) {
                throw new DomainException('Aquí no se escriben fórmulas; las fórmulas viven en la hoja de Google.');
            }

            $kind = $cell['k'] ?? null;
            $number = $text === '' ? null : StandSpreadsheet::parseNumber($text);
            if ($text === '') {
                $this->client->write($workbook->google_spreadsheet_id, $workbook->google_sheet, $coordinate, '', true);
            } elseif ($kind === 'd') {
                $date = self::parseDate($text) ?? throw new DomainException('Escribe la fecha como 2027-09-30 o 30/09/2027.');
                $this->client->write($workbook->google_spreadsheet_id, $workbook->google_sheet, $coordinate, $date, false);
            } elseif ($number !== null && $kind !== 's') {
                if (str_ends_with($text, '%') || ($cell['p'] ?? false)) {
                    $number /= 100;
                }
                $this->client->write($workbook->google_spreadsheet_id, $workbook->google_sheet, $coordinate, $number, false);
            } else {
                // Text stays text (phone numbers, ids), exactly as typed.
                $this->client->write($workbook->google_spreadsheet_id, $workbook->google_sheet, $coordinate, $text, true);
            }

            $before = (string) ($cell['v'] ?? '');
            $this->pull($workbook);
            $after = (string) ($workbook->sheets[0]['rows'][$row - 1][$column - 1]['v'] ?? $text);
            $workbook->forceFill(['edits' => $workbook->edits + 1])->save();

            return ['antes' => $before, 'despues' => $after, 'hoja' => (string) $workbook->google_sheet];
        });
    }

    /**
     * Turns a Sheets API grid into the Stand comercial grid (see StandSpreadsheet::snapshot()).
     *
     * @param  array<string, mixed>  $grid
     * @return array<string, mixed>
     */
    public static function snapshot(array $grid): array
    {
        $data = $grid['data'][0] ?? [];
        $rowData = $data['rowData'] ?? [];
        $lists = [];
        $rows = [];
        $columnCount = 0;

        foreach (array_slice($rowData, 0, StandSpreadsheet::MAX_ROWS) as $r => $rowJson) {
            $row = [];
            foreach (array_slice($rowJson['values'] ?? [], 0, StandSpreadsheet::MAX_COLUMNS) as $c => $value) {
                $row[$c] = self::cell($value, $lists);
                if ($row[$c] !== null) {
                    $columnCount = max($columnCount, $c + 1);
                }
            }
            $rows[$r] = $row;
        }

        // Drop the empty rows Google returns at the bottom of the tab.
        while ($rows !== [] && ! array_filter(end($rows))) {
            array_pop($rows);
        }
        $columnCount = max($columnCount, 1);
        $rows = array_map(fn ($row) => array_pad(array_slice($row, 0, $columnCount), $columnCount, null), $rows ?: [[null]]);

        $widths = [];
        for ($c = 0; $c < $columnCount; $c++) {
            $px = $data['columnMetadata'][$c]['pixelSize'] ?? null;
            $widths[] = $px ? (int) $px : null;
        }

        $merges = [];
        foreach ($grid['merges'] ?? [] as $m) {
            $r1 = (int) ($m['startRowIndex'] ?? 0);
            $c1 = (int) ($m['startColumnIndex'] ?? 0);
            $r2 = min((int) ($m['endRowIndex'] ?? 0), count($rows));
            $c2 = min((int) ($m['endColumnIndex'] ?? 0), $columnCount);
            if ($r1 < count($rows) && $c1 < $columnCount && ($r2 - $r1 > 1 || $c2 - $c1 > 1)) {
                $merges[] = [$r1, $c1, $r2 - $r1, $c2 - $c1];
            }
        }

        $total = count($rowData);

        return [
            'name' => (string) data_get($grid, 'properties.title', 'Stands'),
            'hidden' => false,
            'rows' => $rows,
            'widths' => $widths,
            'merges' => $merges,
            'lists' => $lists,
            'truncated' => $total > StandSpreadsheet::MAX_ROWS,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<list<string>>  $lists
     * @return array<string, mixed>|null
     */
    private static function cell(array $value, array &$lists): ?array
    {
        $formula = data_get($value, 'userEnteredValue.formulaValue');
        $effective = $value['effectiveValue'] ?? [];
        $shown = (string) ($value['formattedValue'] ?? '');
        $format = $value['effectiveFormat'] ?? [];
        $type = data_get($format, 'numberFormat.type');

        $list = null;
        if (data_get($value, 'dataValidation.condition.type') === 'ONE_OF_LIST') {
            $options = array_values(array_filter(array_map(fn ($v) => trim((string) ($v['userEnteredValue'] ?? '')), data_get($value, 'dataValidation.condition.values', []))));
            if ($options !== []) {
                $index = array_search($options, $lists, true);
                if ($index === false) {
                    $lists[] = $options;
                    $index = count($lists) - 1;
                }
                $list = $index;
            }
        }

        if ($formula === null && $effective === [] && $shown === '' && $list === null) {
            return null;
        }

        $kind = match (true) {
            array_key_exists('boolValue', $effective) => 'b',
            array_key_exists('numberValue', $effective) && in_array($type, ['DATE', 'DATE_TIME'], true) => 'd',
            array_key_exists('numberValue', $effective) => 'n',
            default => 's',
        };

        $cell = ['v' => $shown, 'e' => '', 'k' => $kind];
        if ($formula !== null) {
            $cell['f'] = (string) $formula;
        } else {
            $number = $effective['numberValue'] ?? null;
            $cell['e'] = match ($kind) {
                'b' => $effective['boolValue'] ? 'VERDADERO' : 'FALSO',
                'd' => self::serialToDate((float) $number, $type === 'DATE_TIME'),
                'n' => StandSpreadsheet::numberText($type === 'PERCENT' ? $number * 100 : $number),
                default => (string) ($effective['stringValue'] ?? $shown),
            };
        }
        if ($type === 'PERCENT') {
            $cell['p'] = true;
        }
        if (isset($effective['errorValue'])) {
            $cell['x'] = true;
        }
        if (data_get($format, 'textFormat.bold')) {
            $cell['b'] = true;
        }
        $bg = $format['backgroundColor'] ?? null;
        if (is_array($bg) && $bg !== []) {
            $hex = sprintf('#%02X%02X%02X', round(($bg['red'] ?? 0) * 255), round(($bg['green'] ?? 0) * 255), round(($bg['blue'] ?? 0) * 255));
            if ($hex !== '#FFFFFF') {
                $cell['bg'] = $hex;
            }
        }
        $align = strtolower((string) ($format['horizontalAlignment'] ?? ''));
        if (in_array($align, ['left', 'center', 'right'], true)) {
            $cell['a'] = $align;
        }
        if ($list !== null) {
            $cell['l'] = $list;
        }

        return $cell;
    }

    /** Google counts days from 1899-12-30, like Excel. */
    private static function serialToDate(float $serial, bool $withTime): string
    {
        $date = CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->addSeconds((int) round($serial * 86400));

        return $date->format($withTime ? 'Y-m-d H:i' : 'Y-m-d');
    }

    private static function parseDate(string $text): ?string
    {
        foreach (['Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $text);
            if ($date && $date->format($format) === $text) {
                // Google reads ISO dates the same way whatever the sheet's locale is.
                return $date->format(str_contains($format, 'H') ? 'Y-m-d H:i' : 'Y-m-d');
            }
        }

        return null;
    }
}
