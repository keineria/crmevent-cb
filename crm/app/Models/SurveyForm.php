<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The questions sent after every activity of one type, and when they go out.
 */
#[Fillable(['activity_type', 'title', 'intro', 'questions', 'enabled', 'enabled_at', 'delay_minutes', 'require_check_in', 'updated_by'])]
class SurveyForm extends Model
{
    public const QUESTION_TYPES = [
        'rating' => 'Calificación de 1 a 5',
        'nps' => 'Recomendación de 0 a 10',
        'choice' => 'Opción única',
        'text' => 'Respuesta abierta',
    ];

    private const RECOMMEND = '¿Qué tan probable es que recomiendes Colombia Birdfair a un amigo?';

    /** Questions each form starts with, and what "Restaurar preguntas" puts back. */
    public const DEFAULTS = [
        'conference' => [
            'title' => '¿Qué te parecieron las conferencias?',
            'intro' => 'Califica de 1 (muy mal) a 5 (excelente). Te toma un minuto.',
            'questions' => [
                ['key' => 'satisfaccion', 'type' => 'rating', 'label' => 'Satisfacción general', 'required' => true],
                ['key' => 'contenido', 'type' => 'rating', 'label' => 'Contenido de las charlas', 'required' => true],
                ['key' => 'conferencistas', 'type' => 'rating', 'label' => 'Conferencistas', 'required' => true],
                ['key' => 'lugar', 'type' => 'rating', 'label' => 'Lugar, sonido y comodidad', 'required' => false],
                ['key' => 'recomendacion', 'type' => 'nps', 'label' => self::RECOMMEND, 'required' => true],
                ['key' => 'comentarios', 'type' => 'text', 'label' => '¿Qué podríamos mejorar?', 'required' => false],
            ],
        ],
        'workshop' => [
            'title' => '¿Qué te pareció el taller?',
            'intro' => 'Califica de 1 (muy mal) a 5 (excelente). Te toma un minuto.',
            'questions' => [
                ['key' => 'satisfaccion', 'type' => 'rating', 'label' => 'Satisfacción general', 'required' => true],
                ['key' => 'tallerista', 'type' => 'rating', 'label' => 'Tallerista', 'required' => true],
                ['key' => 'aprendizaje', 'type' => 'rating', 'label' => 'Lo que aprendiste', 'required' => true],
                ['key' => 'materiales', 'type' => 'rating', 'label' => 'Materiales y espacio', 'required' => false],
                ['key' => 'duracion', 'type' => 'choice', 'label' => 'La duración fue…', 'required' => false, 'options' => ['Corta', 'Adecuada', 'Larga']],
                ['key' => 'recomendacion', 'type' => 'nps', 'label' => self::RECOMMEND, 'required' => true],
                ['key' => 'comentarios', 'type' => 'text', 'label' => '¿Qué podríamos mejorar?', 'required' => false],
            ],
        ],
        'birding' => [
            'title' => '¿Cómo te fue en la salida?',
            'intro' => 'Califica de 1 (muy mal) a 5 (excelente). Te toma un minuto.',
            'questions' => [
                ['key' => 'satisfaccion', 'type' => 'rating', 'label' => 'Satisfacción general', 'required' => true],
                ['key' => 'guia', 'type' => 'rating', 'label' => 'Guía', 'required' => true],
                ['key' => 'avistamiento', 'type' => 'rating', 'label' => 'Aves que pudiste ver', 'required' => true],
                ['key' => 'transporte', 'type' => 'rating', 'label' => 'Transporte y puntualidad', 'required' => false],
                ['key' => 'alimentacion', 'type' => 'rating', 'label' => 'Alimentación', 'required' => false],
                ['key' => 'recomendacion', 'type' => 'nps', 'label' => self::RECOMMEND, 'required' => true],
                ['key' => 'comentarios', 'type' => 'text', 'label' => 'Cuéntanos qué fue lo mejor y qué mejorarías', 'required' => false],
            ],
        ],
        'other' => [
            'title' => '¿Qué te pareció la actividad?',
            'intro' => 'Califica de 1 (muy mal) a 5 (excelente). Te toma un minuto.',
            'questions' => [
                ['key' => 'satisfaccion', 'type' => 'rating', 'label' => 'Satisfacción general', 'required' => true],
                ['key' => 'organizacion', 'type' => 'rating', 'label' => 'Organización', 'required' => false],
                ['key' => 'recomendacion', 'type' => 'nps', 'label' => self::RECOMMEND, 'required' => true],
                ['key' => 'comentarios', 'type' => 'text', 'label' => '¿Qué podríamos mejorar?', 'required' => false],
            ],
        ],
    ];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'require_check_in' => 'boolean',
        ];
    }

    /**
     * When the survey of an activity goes out: its end (Bogotá time) plus the form's delay.
     * Without an end time, 18:00 of its day; without a date, never.
     */
    public function sendsAt(EventActivity $activity): ?Carbon
    {
        $end = SurveyForm::activityEnd($activity);

        return $end?->addMinutes($this->delay_minutes);
    }

    public static function activityEnd(EventActivity $activity): ?Carbon
    {
        if (! $activity->event_date) {
            return null;
        }

        $time = $activity->end_time ?: '18:00';

        return Carbon::parse($activity->event_date->toDateString().' '.substr($time, 0, 5), CheckIn::TIMEZONE)->utc();
    }
}
