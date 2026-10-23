<?php

namespace App\Services\Stands;

use DomainException;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Reads an Excel book with its formulas, turns it into the grid the Stand comercial page shows,
 * and writes edits back so every formula recomputes the way Excel would.
 */
class StandSpreadsheet
{
    /** Largest grid shown; bigger sheets are cut and flagged. */
    public const MAX_ROWS = 3000;

    public const MAX_COLUMNS = 120;

    public static function load(string $path): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($path);
        $spreadsheet = $reader->load($path);
        // Recalculate from the inputs instead of trusting the values cached by whoever saved the file last.
        Calculation::getInstance($spreadsheet)->clearCalculationCache();

        return $spreadsheet;
    }

    public static function save(Spreadsheet $spreadsheet, string $path): void
    {
        $writer = new Xlsx($spreadsheet);
        // Cache the computed values in the file, so Excel and previews open with the right numbers.
        $writer->setPreCalculateFormulas(true);
        $tmp = $path.'.tmp';
        $writer->save($tmp);
        rename($tmp, $path);
    }

    /**
     * Every sheet as rows of cells. A cell is null when empty, otherwise
     * ['v' => shown text, 'e' => value to edit, 'f' => formula, 'k' => kind, ...style].
     *
     * @return list<array<string, mixed>>
     */
    public static function snapshot(Spreadsheet $spreadsheet): array
    {
        $sheets = [];
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $sheets[] = self::sheet($sheet);
        }

        return $sheets;
    }

    /**
     * Runs with numbers formatted the Colombian way (1.200.000,50). Only around formatting,
     * so formulas like VALUE() keep calculating as the file expects.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function colombian(callable $callback): mixed
    {
        $decimal = StringHelper::getDecimalSeparator();
        $thousands = StringHelper::getThousandsSeparator();
        StringHelper::setDecimalSeparator(',');
        StringHelper::setThousandsSeparator('.');

        try {
            return $callback();
        } finally {
            StringHelper::setDecimalSeparator($decimal);
            StringHelper::setThousandsSeparator($thousands);
        }
    }

    /** @return array<string, mixed> */
    private static function sheet(Worksheet $sheet): array
    {
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $rowCount = min($highestRow, self::MAX_ROWS);
        $columnCount = min($highestColumn, self::MAX_COLUMNS);

        $rows = array_fill(0, $rowCount, array_fill(0, $columnCount, null));
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            [$column, $row] = Coordinate::indexesFromString($coordinate);
            if ($row > $rowCount || $column > $columnCount) {
                continue;
            }
            $cell = $sheet->getCell($coordinate);
            if (! $cell->isFormula() && ($cell->getValue() === null || $cell->getValue() === '')) {
                continue;
            }
            $rows[$row - 1][$column - 1] = self::cell($cell);
        }

        // A single empty cell keeps an empty sheet editable.
        if ($rows === []) {
            $rows = [[null]];
            $columnCount = 1;
        }

        $widths = [];
        for ($c = 1; $c <= max($columnCount, 1); $c++) {
            $width = $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->getWidth();
            $widths[] = $width > 0 ? (int) round($width * 7 + 5) : null;
        }

        [$lists, $listOf] = self::lists($sheet, $rowCount, $columnCount);
        foreach ($listOf as $key => $list) {
            [$r, $c] = explode(':', $key);
            if ($rows[$r][$c] === null) {
                $rows[$r][$c] = ['v' => '', 'e' => '', 'k' => 's'];
            }
            if (! isset($rows[$r][$c]['f'])) {
                $rows[$r][$c]['l'] = $list;
            }
        }

        return [
            'name' => $sheet->getTitle(),
            'hidden' => $sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE,
            'rows' => $rows,
            'widths' => $widths,
            'merges' => self::merges($sheet, $rowCount, $columnCount),
            'lists' => $lists,
            'truncated' => $highestRow > $rowCount || $highestColumn > $columnCount,
        ];
    }

    /** @return array<string, mixed> */
    private static function cell(Cell $cell): array
    {
        $format = $cell->getStyle()->getNumberFormat()->getFormatCode() ?? NumberFormat::FORMAT_GENERAL;
        $formula = $cell->isFormula() ? (string) $cell->getValue() : null;
        $error = false;

        if ($formula !== null) {
            try {
                $value = $cell->getCalculatedValue();
            } catch (Throwable) {
                $value = $cell->getOldCalculatedValue();
                $error = true;
            }
        } else {
            $value = $cell->getValue();
        }
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        $isDate = is_numeric($value) && ExcelDate::isDateTimeFormatCode($format);
        $kind = match (true) {
            is_bool($value) => 'b',
            $isDate => 'd',
            is_int($value) || is_float($value) => 'n',
            default => 's',
        };
        if (is_string($value) && array_key_exists($value, DataType::getErrorCodes())) {
            $error = true;
        }

        $data = [
            'v' => self::display($value, $format),
            'e' => $formula === null ? self::editable($value, $format, $kind) : '',
            'k' => $kind,
        ];
        if ($formula !== null) {
            $data['f'] = $formula;
        }
        if ($error) {
            $data['x'] = true;
        }

        $style = $cell->getStyle();
        if ($style->getFont()->getBold()) {
            $data['b'] = true;
        }
        $fill = $style->getFill();
        if ($fill->getFillType() === Fill::FILL_SOLID) {
            $rgb = strtoupper(substr((string) $fill->getStartColor()->getRGB(), -6));
            if ($rgb !== '' && $rgb !== 'FFFFFF' && preg_match('/^[0-9A-F]{6}$/', $rgb)) {
                $data['bg'] = '#'.$rgb;
            }
        }
        $align = $style->getAlignment()->getHorizontal();
        if (in_array($align, ['center', 'right', 'left'], true)) {
            $data['a'] = $align;
        }

        return $data;
    }

    private static function display(mixed $value, string $format): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'VERDADERO' : 'FALSO';
        }
        if (is_int($value) || is_float($value)) {
            try {
                return self::colombian(fn () => NumberFormat::toFormattedString($value, $format));
            } catch (Throwable) {
                return (string) $value;
            }
        }

        return (string) $value;
    }

    /** What the edit box starts with: plain numbers, ISO dates, percentages as typed in Excel. */
    private static function editable(mixed $value, string $format, string $kind): string
    {
        if ($value === null) {
            return '';
        }

        return match ($kind) {
            'b' => $value ? 'VERDADERO' : 'FALSO',
            'd' => self::dateText((float) $value, $format),
            'n' => self::numberText(str_contains($format, '%') ? $value * 100 : $value),
            default => (string) $value,
        };
    }

    private static function dateText(float $serial, string $format): string
    {
        $date = ExcelDate::excelToDateTimeObject($serial);
        $hasTime = fmod($serial, 1.0) !== 0.0 || preg_match('/[hs]/i', preg_replace('/"[^"]*"/', '', $format));

        return $date->format($hasTime ? 'Y-m-d H:i' : 'Y-m-d');
    }

    public static function numberText(int|float $number): string
    {
        if (is_float($number)) {
            $number = round($number, 10);
            if (floor($number) === $number && abs($number) < 1e15) {
                return (string) (int) $number;
            }
        }

        return rtrim(rtrim(sprintf('%.10F', $number), '0'), '.');
    }

    /**
     * Writes what someone typed into a cell, keeping the cell's kind (number, date, percent, text).
     *
     * @return array{antes: string, despues: string}
     */
    public static function setValue(Spreadsheet $spreadsheet, int $sheetIndex, string $coordinate, ?string $input): array
    {
        if ($sheetIndex < 0 || $sheetIndex >= $spreadsheet->getSheetCount()) {
            throw new DomainException('Esa hoja no existe en el Excel.');
        }
        $sheet = $spreadsheet->getSheet($sheetIndex);
        [$column, $row] = Coordinate::indexesFromString($coordinate);
        if ($row > self::MAX_ROWS + 1 || $column > self::MAX_COLUMNS) {
            throw new DomainException('Esa celda está fuera de la tabla.');
        }

        $cell = $sheet->getCell($coordinate);
        if ($cell->isFormula()) {
            throw new DomainException("La celda {$coordinate} tiene una fórmula y se calcula sola.");
        }
        $format = $cell->getStyle()->getNumberFormat()->getFormatCode() ?? NumberFormat::FORMAT_GENERAL;
        $before = self::display($cell->getValue() instanceof RichText ? $cell->getValue()->getPlainText() : $cell->getValue(), $format);

        $text = trim((string) $input);
        if ($text === '') {
            $cell->setValueExplicit(null, DataType::TYPE_NULL);
        } elseif (str_starts_with($text, '=')) {
            throw new DomainException('Aquí no se escriben fórmulas; las fórmulas vienen del Excel.');
        } elseif (ExcelDate::isDateTimeFormatCode($format) && ! is_numeric($text)) {
            $serial = self::parseDate($text);
            if ($serial === null) {
                throw new DomainException('Escribe la fecha como 2027-09-30 o 30/09/2027.');
            }
            $cell->setValueExplicit($serial, DataType::TYPE_NUMERIC);
        } elseif (($number = self::parseNumber($text)) !== null) {
            if (str_ends_with($text, '%') || str_contains($format, '%')) {
                $number /= 100;
            }
            $cell->setValueExplicit($number, DataType::TYPE_NUMERIC);
        } elseif (in_array(mb_strtoupper($text), ['VERDADERO', 'FALSO', 'TRUE', 'FALSE'], true) && is_bool($cell->getValue())) {
            $cell->setValueExplicit(in_array(mb_strtoupper($text), ['VERDADERO', 'TRUE'], true), DataType::TYPE_BOOL);
        } else {
            $cell->setValueExplicit($text, DataType::TYPE_STRING);
        }

        Calculation::getInstance($spreadsheet)->clearCalculationCache();

        return ['antes' => $before, 'despues' => self::display($cell->getValue(), $format)];
    }

    /** Numbers as people type them here: 1.200.000, 1200000, 1.250,50, 1,250.50, $ 300.000, 15%. */
    public static function parseNumber(string $text): ?float
    {
        $t = str_replace([' ', "\u{00A0}", '$', 'COP', 'USD'], '', $text);
        $t = rtrim($t, '%');
        if ($t === '' || ! preg_match('/^[+-]?\d[\d.,]*$/', $t) || preg_match('/[.,]$/', $t)) {
            return null;
        }

        $dots = substr_count($t, '.');
        $commas = substr_count($t, ',');
        if ($dots && $commas) {
            // The separator written last is the decimal one.
            [$thousands, $decimal] = strrpos($t, '.') > strrpos($t, ',') ? [',', '.'] : ['.', ','];
            $t = str_replace([$thousands, $decimal], ['', '.'], $t);
        } elseif ($dots + $commas > 0) {
            $sep = $dots ? '.' : ',';
            $grouped = preg_match('/^[+-]?\d{1,3}(\\'.$sep.'\d{3})+$/', $t);
            // 1.500 and 1.200.000 are thousands; 12.5 and 0,75 are decimals.
            $t = ($grouped || $dots + $commas > 1) ? str_replace($sep, '', $t) : str_replace($sep, '.', $t);
        }

        return is_numeric($t) ? (float) $t : null;
    }

    private static function parseDate(string $text): ?float
    {
        foreach (['Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y', 'd/m/y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $text);
            if ($date && $date->format($format) === $text) {
                return (float) ExcelDate::PHPToExcel($date);
            }
        }

        return null;
    }

    /** @return list<array{0: int, 1: int, 2: int, 3: int}> [row, column, rows, columns], zero-based. */
    private static function merges(Worksheet $sheet, int $rowCount, int $columnCount): array
    {
        $merges = [];
        foreach ($sheet->getMergeCells() as $range) {
            [[$c1, $r1], [$c2, $r2]] = Coordinate::rangeBoundaries($range);
            if ($r1 > $rowCount || $c1 > $columnCount) {
                continue;
            }
            $r2 = min($r2, $rowCount);
            $c2 = min($c2, $columnCount);
            if ($r2 > $r1 || $c2 > $c1) {
                $merges[] = [$r1 - 1, $c1 - 1, $r2 - $r1 + 1, $c2 - $c1 + 1];
            }
        }

        return $merges;
    }

    /**
     * Drop-down lists set with data validation, resolved to their options.
     *
     * @return array{0: list<list<string>>, 1: array<string, int>} the option lists, and "row:column" => list index
     */
    private static function lists(Worksheet $sheet, int $rowCount, int $columnCount): array
    {
        $lists = [];
        $cells = [];
        foreach ($sheet->getDataValidationCollection() as $ranges => $validation) {
            if ($validation->getType() !== DataValidation::TYPE_LIST) {
                continue;
            }
            $options = self::listOptions($sheet, (string) $validation->getFormula1());
            if ($options === []) {
                continue;
            }
            $index = array_search($options, $lists, true);
            if ($index === false) {
                $lists[] = $options;
                $index = count($lists) - 1;
            }
            foreach (preg_split('/\s+/', trim((string) $ranges)) as $range) {
                [[$c1, $r1], [$c2, $r2]] = Coordinate::rangeBoundaries(str_contains($range, ':') ? $range : "{$range}:{$range}");
                for ($r = $r1; $r <= min($r2, $rowCount); $r++) {
                    for ($c = $c1; $c <= min($c2, $columnCount); $c++) {
                        $cells[($r - 1).':'.($c - 1)] = $index;
                    }
                }
            }
        }

        return [$lists, $cells];
    }

    /** @return list<string> */
    private static function listOptions(Worksheet $sheet, string $formula): array
    {
        $formula = trim($formula);
        if (str_starts_with($formula, '"')) {
            return array_values(array_filter(array_map('trim', explode(',', trim($formula, '"'))), fn ($o) => $o !== ''));
        }

        try {
            $target = $sheet;
            $range = ltrim($formula, '=');
            if (str_contains($range, '!')) {
                [$name, $range] = explode('!', $range, 2);
                $target = $sheet->getParent()?->getSheetByName(trim($name, "'"));
            } elseif ($named = $sheet->getParent()?->getNamedRange($range)) {
                $target = $named->getWorksheet();
                $range = $named->getRange();
            }
            if (! $target) {
                return [];
            }
            $values = $target->rangeToArray(str_replace('$', '', $range), null, true, true);
        } catch (Throwable) {
            return [];
        }

        $options = [];
        array_walk_recursive($values, function ($v) use (&$options) {
            if ($v !== null && $v !== '') {
                $options[] = (string) $v;
            }
        });

        return array_slice(array_values(array_unique($options)), 0, 300);
    }
}
