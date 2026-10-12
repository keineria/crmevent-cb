<?php

namespace App\Services\Contacts;

use Illuminate\Support\Str;

/**
 * CRM fields a spreadsheet column can be mapped to, with the header names that suggest each one.
 */
final class ImportFields
{
    /** Keeps the column as a detail of that person's fair (plan, activities, shirt…); may be used on many columns. */
    public const DETAIL = 'detail';

    /** @var array<string, string> field => label shown in the mapping screen */
    public const LABELS = [
        'full_name' => 'Nombre completo',
        'first_name' => 'Nombres',
        'last_name' => 'Apellidos',
        'gender' => 'Género',
        'email' => 'Correo',
        'phone' => 'Teléfono',
        'document_type' => 'Tipo de documento',
        'document_number' => 'Documento',
        'country' => 'País',
        'state' => 'Departamento o estado',
        'city' => 'Ciudad',
        'organization' => 'Empresa u organización',
        'notes' => 'Notas',
        'edition_year' => 'Año de la feria',
        self::DETAIL => 'Detalle de la feria',
    ];

    /** Identifiers used to find an existing contact; every row needs at least one. */
    public const IDENTIFIERS = ['email', 'document_number', 'phone'];

    private const SYNONYMS = [
        'full_name' => ['nombre completo', 'nombre y apellido', 'nombres y apellidos', 'full name', 'asistente', 'participante'],
        'first_name' => ['nombre', 'nombres', 'primer nombre', 'first name', 'name'],
        'last_name' => ['apellido', 'apellidos', 'last name', 'surname'],
        'gender' => ['genero', 'sexo', 'gender'],
        'email' => ['correo', 'correo electronico', 'email', 'e mail', 'mail'],
        'phone' => ['telefono', 'celular', 'movil', 'whatsapp', 'phone', 'telefono celular', 'numero de contacto'],
        'document_type' => ['tipo id', 'tipo de documento', 'tipo documento', 'tipo de identificacion'],
        'document_number' => ['documento', 'cedula', 'numero de documento', 'identificacion', 'cc', 'pasaporte', 'id', 'no id', 'numero id'],
        'country' => ['pais', 'country', 'pais de residencia'],
        'state' => ['departamento', 'dpto', 'dpto est', 'estado', 'provincia', 'state'],
        'city' => ['ciudad', 'city', 'municipio', 'ciudad de residencia'],
        'organization' => ['empresa', 'organizacion', 'institucion', 'entidad', 'company', 'organization'],
        'notes' => ['notas', 'observaciones', 'comentarios', 'notes'],
        'edition_year' => ['ano', 'edicion', 'feria', 'year'],
    ];

    /** Registration columns of the fair masters worth keeping as details of each person's edition. */
    private const DETAIL_HEADERS = [
        'fecha', 'registro', 'plan', 'valor total', 'valor conferencias', 'valor talleres', 'conferencias', 'salidas', 'talleres',
        'stand', 'lanzamiento', 'jueves', 'jueves taller', 'viernes', 'vie taller', 'sabado', 'sab taller', 'domingo', 'dom taller',
        'alimentacion', 'camisa', 'hospedaje', 'kit', 'escarapela', 'analisis', 'nota',
    ];

    /**
     * Suggested field for each header; null when nothing matches or the field is already taken.
     *
     * @param  list<string>  $headers
     * @return array<int, string|null>
     */
    public static function guess(array $headers): array
    {
        $taken = [];
        $guesses = [];

        foreach ($headers as $index => $header) {
            $normalized = self::normalize($header);
            $guesses[$index] = null;

            foreach (self::SYNONYMS as $field => $names) {
                if (! isset($taken[$field]) && in_array($normalized, array_map(self::normalize(...), $names), true)) {
                    $guesses[$index] = $field;
                    $taken[$field] = true;
                    break;
                }
            }

            if ($guesses[$index] === null && in_array($normalized, self::DETAIL_HEADERS, true)) {
                $guesses[$index] = self::DETAIL;
            }
        }

        // A lone "Nombre" column without a surname column holds the full name.
        if (isset($taken['first_name']) && ! isset($taken['last_name']) && ! isset($taken['full_name'])) {
            $guesses[array_search('first_name', $guesses, true)] = 'full_name';
        }

        return $guesses;
    }

    /**
     * Whether a row repeats the header (masters sometimes paste the title row again mid-sheet).
     *
     * @param  list<string>  $row
     * @param  list<string>  $headers
     */
    public static function isHeaderRow(array $row, array $headers): bool
    {
        $matches = 0;
        foreach ($headers as $i => $header) {
            if ($header !== '' && self::normalize($row[$i] ?? '') === self::normalize($header)) {
                $matches++;
            }
        }

        return $matches >= max(2, (int) ceil(count(array_filter($headers)) / 2));
    }

    public static function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))));
    }
}
