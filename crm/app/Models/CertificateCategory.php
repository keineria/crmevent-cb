<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A certificate design (logo, colours, wording, signatures) shared by the conferences or the workshops it is given for.
 */
#[Fillable([
    'name', 'activity_type', 'template', 'primary_color', 'accent_color', 'heading', 'intro', 'body',
    'logo_path', 'background_path', 'signatures', 'show_qr', 'created_by', 'updated_by',
])]
class CertificateCategory extends Model
{
    /** Plugin activity types that get attendance certificates. */
    public const ACTIVITY_TYPES = [
        'conference' => 'Conferencias',
        'workshop' => 'Talleres',
    ];

    /** Layouts, all A4 landscape. A design image uploaded as background replaces the drawn decoration of any of them. */
    public const TEMPLATES = [
        'selva' => ['label' => 'Selva', 'hint' => 'Franja lateral de color con el logo y el nombre en grande.'],
        'minimal' => ['label' => 'Minimal', 'hint' => 'Blanco, mucho aire, una línea de acento y tipografía protagonista.'],
        'clasico' => ['label' => 'Clásico', 'hint' => 'Marco doble, todo centrado y sello. Formal.'],
        'geometrico' => ['label' => 'Geométrico', 'hint' => 'Esquinas con formas de color, moderno y alegre.'],
    ];

    public const DEFAULTS = [
        'template' => 'selva',
        'primary_color' => '#2F5D2A',
        'accent_color' => '#FF8600',
        'heading' => 'Certificado de asistencia',
        'intro' => 'Se certifica que',
        'body' => 'asistió a {{actividad}}, realizada el {{fecha}} en el marco de Colombia Birdfair {{edicion}}, con una intensidad de {{horas}} horas.',
        'show_qr' => true,
    ];

    /** Fields the certificate text can use. */
    public const FIELDS = [
        'nombre' => 'Nombre de la persona',
        'documento' => 'Número de documento',
        'actividad' => 'Nombre de la actividad',
        'categoria' => 'Nombre de la categoría',
        'fecha' => 'Fecha de la actividad',
        'edicion' => 'Año de la feria',
        'horas' => 'Horas',
    ];

    public const MAX_SIGNATURES = 2;

    protected function casts(): array
    {
        return ['signatures' => 'array', 'show_qr' => 'boolean'];
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * @return list<array{name: string, role: string|null, image_path: string|null}>
     */
    public function signers(): array
    {
        return array_values(array_map(fn (array $s) => [
            'name' => (string) ($s['name'] ?? ''),
            'role' => $s['role'] ?? null,
            'image_path' => $s['image_path'] ?? null,
        ], array_filter($this->signatures ?? [], fn ($s) => is_array($s) && filled($s['name'] ?? null))));
    }

    /**
     * Every uploaded file of the category, to clean up when it is deleted.
     *
     * @return list<string>
     */
    public function files(): array
    {
        return array_values(array_filter([
            $this->logo_path,
            $this->background_path,
            ...array_column($this->signatures ?? [], 'image_path'),
        ]));
    }

    protected static function booted(): void
    {
        static::deleted(function (self $category) {
            Storage::disk('local')->delete($category->files());
        });
    }
}
