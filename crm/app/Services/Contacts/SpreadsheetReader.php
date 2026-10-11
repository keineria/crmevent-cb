<?php

namespace App\Services\Contacts;

use DateTimeInterface;
use Generator;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Streams the rows of the first sheet of an .xlsx file, or of a .csv file, as lists of trimmed strings.
 */
class SpreadsheetReader
{
    public const EXTENSIONS = ['xlsx', 'csv', 'txt'];

    /**
     * @return Generator<int, list<string>>
     */
    public function rows(string $path, string $extension): Generator
    {
        return match (strtolower($extension)) {
            'xlsx' => $this->xlsx($path),
            'csv', 'txt' => $this->csv($path),
            default => throw new RuntimeException('Formato no soportado. Guarda el archivo como .xlsx o .csv.'),
        };
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function xlsx(string $path): Generator
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new RuntimeException('El servidor no puede leer archivos .xlsx (falta la extensión zip de PHP). Guarda el archivo como .csv.');
        }

        $reader = new XlsxReader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield array_map($this->cell(...), $row->toArray());
                }
                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function csv(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo.');
        }

        try {
            $head = (string) fread($handle, 65536);
            // Excel on Windows saves CSV as Windows-1252 unless told otherwise.
            $isUtf8 = mb_check_encoding($head, 'UTF-8');
            $firstLine = strtok(preg_replace('/^\xEF\xBB\xBF/', '', $head), "\r\n") ?: '';
            $delimiter = $this->delimiter($firstLine);
            rewind($handle);

            $first = true;
            while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if ($first) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                    $first = false;
                }

                yield array_map(
                    fn ($value) => trim($isUtf8 ? (string) $value : mb_convert_encoding((string) $value, 'UTF-8', 'Windows-1252')),
                    $row,
                );
            }
        } finally {
            fclose($handle);
        }
    }

    private function delimiter(string $line): string
    {
        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return array_key_first($counts);
    }

    private function cell(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            // Phone and document numbers often come as numeric cells; avoid "3001234567.0" or scientific notation.
            is_float($value) && floor($value) === $value => sprintf('%.0f', $value),
            is_bool($value) => $value ? '1' : '0',
            default => trim((string) $value),
        };
    }
}
