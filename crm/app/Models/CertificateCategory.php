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
    'logo_path', 'background_path', 'signer_ids', 'layout', 'show_qr', 'created_by', 'updated_by',
])]
class CertificateCategory extends Model
{
    /** Plugin activity types that get attendance certificates. */
    public const ACTIVITY_TYPES = [
        'conference' => 'Conferencias',
        'workshop' => 'Talleres',
    ];

    /**
     * Layouts, all A4 landscape. A design image uploaded as background replaces the drawn decoration of any of them.
     * "propio" draws nothing: the uploaded design is the certificate and each text goes where it was placed.
     */
    public const TEMPLATES = [
        'selva' => ['label' => 'Selva', 'hint' => 'Franja lateral de color con el logo y el nombre en grande.'],
        'minimal' => ['label' => 'Minimal', 'hint' => 'Blanco, mucho aire, una línea de acento y tipografía protagonista.'],
        'clasico' => ['label' => 'Clásico', 'hint' => 'Marco doble, todo centrado y sello. Formal.'],
        'geometrico' => ['label' => 'Geométrico', 'hint' => 'Esquinas con formas de color, moderno y alegre.'],
        'propio' => ['label' => 'Diseño propio', 'hint' => 'Sube tu diseño completo y ubica encima el nombre, el texto, las firmas y el QR.'],
    ];

    /** Blocks of the "propio" template that can be moved and styled. */
    public const BLOCKS = [
        'heading' => 'Título',
        'intro' => 'Frase antes del nombre',
        'name' => 'Nombre',
        'document' => 'Documento',
        'body' => 'Texto',
        'signers' => 'Firmas',
        'qr' => 'Código QR',
    ];

    public const FONTS = ['serif' => 'Serif elegante', 'sans' => 'Sans moderna', 'script' => 'Caligráfica'];

    /**
     * Where each block starts on a fresh "propio" design: x, y and width in mm on the A4 landscape page,
     * font size in pt (the QR's size is its side in mm).
     */
    public const DEFAULT_LAYOUT = [
        'heading' => ['x' => 30, 'y' => 38, 'w' => 237, 'size' => 13, 'color' => '#2F5D2A', 'align' => 'center', 'font' => 'sans', 'visible' => true],
        'intro' => ['x' => 30, 'y' => 52, 'w' => 237, 'size' => 14, 'color' => '#5F5A52', 'align' => 'center', 'font' => 'serif', 'visible' => true],
        'name' => ['x' => 30, 'y' => 64, 'w' => 237, 'size' => 40, 'color' => '#1C1A17', 'align' => 'center', 'font' => 'serif', 'visible' => true],
        'document' => ['x' => 30, 'y' => 86, 'w' => 237, 'size' => 10, 'color' => '#5F5A52', 'align' => 'center', 'font' => 'sans', 'visible' => true],
        'body' => ['x' => 48, 'y' => 98, 'w' => 201, 'size' => 12.5, 'color' => '#3A3631', 'align' => 'center', 'font' => 'sans', 'visible' => true],
        'signers' => ['x' => 48, 'y' => 150, 'w' => 201, 'size' => 10.5, 'color' => '#1C1A17', 'align' => 'center', 'font' => 'sans', 'visible' => true],
        'qr' => ['x' => 252, 'y' => 165, 'size' => 22, 'visible' => true],
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

    public const MAX_SIGNERS = 3;

    protected function casts(): array
    {
        return ['signer_ids' => 'array', 'layout' => 'array', 'show_qr' => 'boolean'];
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * The people who sign, in the chosen order. Deleted signers are skipped.
     *
     * @return list<array{name: string, role: string|null, image_path: string|null}>
     */
    public function signers(): array
    {
        $ids = array_values(array_filter(array_map('intval', $this->signer_ids ?? [])));
        if (! $ids) {
            return [];
        }
        $signers = CertificateSigner::whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id) => $signers->has($id) ? [
            'name' => $signers[$id]->name,
            'role' => $signers[$id]->role,
            'image_path' => $signers[$id]->image_path,
        ] : null, $ids)));
    }

    /**
     * The "propio" layout with every block present, filling gaps with the defaults.
     *
     * @param  array<string, mixed>|null  $layout
     * @return array<string, array<string, mixed>>
     */
    public static function normalizeLayout(?array $layout): array
    {
        $result = [];
        foreach (self::DEFAULT_LAYOUT as $block => $defaults) {
            $given = is_array($layout[$block] ?? null) ? $layout[$block] : [];
            $result[$block] = array_replace($defaults, array_intersect_key($given, $defaults));
        }

        return $result;
    }

    /**
     * Every uploaded file of the category, to clean up when it is deleted.
     *
     * @return list<string>
     */
    public function files(): array
    {
        return array_values(array_filter([$this->logo_path, $this->background_path]));
    }

    protected static function booted(): void
    {
        static::deleted(function (self $category) {
            Storage::disk('local')->delete($category->files());
        });
    }
}
