<?php

namespace App\Services\Contacts;

use Illuminate\Support\Str;

/**
 * CRM fields a spreadsheet column can be mapped to, with the header names that suggest each one.
 */
final class ImportFields
{
    /** @var array<string, string> field => label shown in the mapping screen */
    public const LABELS = [
        'full_name' => 'Nombre completo',
        'first_name' => 'Nombres',
        'last_name' => 'Apellidos',
        'email' => 'Correo',
        'phone' => 'Teléfono',
        'document_number' => 'Documento',
        'country' => 'País',
        'city' => 'Ciudad',
        'organization' => 'Empresa u organización',
        'notes' => 'Notas',
        'edition_year' => 'Año de la feria',
    ];

    /** Identifiers used to find an existing contact; every row needs at least one. */
    public const IDENTIFIERS = ['email', 'document_number', 'phone'];

    private const SYNONYMS = [
        'full_name' => ['nombre completo', 'nombre y apellido', 'nombres y apellidos', 'full name', 'asistente', 'participante'],
        'first_name' => ['nombre', 'nombres', 'primer nombre', 'first name', 'name'],
        'last_name' => ['apellido', 'apellidos', 'last name', 'surname'],
        'email' => ['correo', 'correo electronico', 'email', 'e mail', 'mail'],
        'phone' => ['telefono', 'celular', 'movil', 'whatsapp', 'phone', 'telefono celular', 'numero de contacto'],
        'document_number' => ['documento', 'cedula', 'numero de documento', 'identificacion', 'cc', 'pasaporte', 'id'],
        'country' => ['pais', 'country', 'pais de residencia'],
        'city' => ['ciudad', 'city', 'municipio', 'ciudad de residencia'],
        'organization' => ['empresa', 'organizacion', 'institucion', 'entidad', 'company', 'organization'],
        'notes' => ['notas', 'observaciones', 'comentarios', 'notes'],
        'edition_year' => ['ano', 'año', 'edicion', 'feria', 'year'],
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
        }

        return $guesses;
    }

    private static function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))));
    }
}
