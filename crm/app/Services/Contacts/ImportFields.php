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
        'preferred_products' => 'Producto preferido',
        'profile' => 'Perfil',
        'language' => 'Idioma',
        'referral_source' => '¿Cómo nos conoció?',
        'status' => 'Estado (Activo / Desinscrito)',
        'edition_year' => 'Año de la feria',
        self::DETAIL => 'Detalle de la feria',
    ];

    /**
     * Columns of the downloadable template: header => [field, example, help]. Their headers map on their own.
     */
    public const TEMPLATE = [
        'Nombres' => ['first_name', 'Ana María', 'Obligatorio si no hay correo, documento ni teléfono.'],
        'Apellidos' => ['last_name', 'Pérez Gómez', ''],
        'Correo' => ['email', 'ana@ejemplo.com', 'Si el correo ya está en el CRM, se completa ese contacto en vez de crear otro.'],
        'Teléfono' => ['phone', '3001234567', 'Con indicativo si es de otro país, por ejemplo +593 99 123 4567.'],
        'Tipo de documento' => ['document_type', 'CC', 'CC, CE, TI, Pasaporte u otro.'],
        'Documento' => ['document_number', '1020304050', 'Sin puntos. Es lo primero que se usa para no duplicar personas.'],
        'Género' => ['gender', 'Mujer', ''],
        'País' => ['country', 'Colombia', ''],
        'Departamento' => ['state', 'Antioquia', 'Departamento o estado.'],
        'Ciudad' => ['city', 'Medellín', ''],
        'Empresa' => ['organization', 'Club de Observadores', 'Empresa, club u organización.'],
        'Producto preferido' => ['preferred_products', 'Conferencias, Salidas', 'Uno o varios separados por coma: Conferencias, Salidas, Talleres, Otras actividades, Stand. Si lo dejas vacío, el CRM lo calcula con sus compras.'],
        'Perfil' => ['profile', 'Aficionado a las aves', 'Aficionado a las aves, Fotógrafo, Guía de aviturismo, Investigador o académico, Estudiante, Operador turístico o empresa, Prensa u Otro.'],
        'Idioma' => ['language', 'Español', 'Español, Inglés, Portugués u Otro.'],
        '¿Cómo nos conoció?' => ['referral_source', 'Redes sociales', 'Redes sociales, Recomendación, Correo de la feria, Sitio web, Prensa, Ferias anteriores u Otro.'],
        'Año de la feria' => ['edition_year', '2026', 'Año en que asistió o mostró interés. Vacío: se usa el año que elijas al subir el archivo.'],
        'Estado (Activo/Desinscrito)' => ['status', 'Activo', 'Activo recibe correos; Desinscrito no. Vacío = Activo.'],
        'Notas' => ['notes', 'Pidió información de talleres', ''],
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
        'preferred_products' => ['producto preferido', 'productos preferidos', 'producto', 'productos', 'intereses'],
        'profile' => ['perfil', 'tipo de publico', 'ocupacion', 'profesion'],
        'language' => ['idioma', 'language', 'lengua'],
        'referral_source' => ['como nos conocio', 'como se entero', 'como nos conociste', 'fuente', 'canal'],
        'status' => ['estado activo desinscrito', 'estado de suscripcion', 'suscripcion', 'estado del contacto'],
        'edition_year' => ['ano', 'ano de la feria', 'edicion', 'feria', 'year'],
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
