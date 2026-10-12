<?php

namespace App\Services\Campaigns;

use App\Models\Contact;
use Illuminate\Support\Str;

/**
 * Turns a campaign's Markdown body into the email people receive.
 *
 * Besides Markdown it understands:
 * - Personal fields: {{nombre}}, {{apellido}}, {{nombre_completo}}, {{correo}}, {{ciudad}}, {{pais}}, {{organizacion}},
 *   with an optional fallback for empty values: {{nombre|amigo}}.
 * - Buttons, one per line: [[Texto del botón|https://enlace]]
 */
class CampaignRenderer
{
    public const FIELDS = [
        'nombre' => 'Nombre',
        'apellido' => 'Apellido',
        'nombre_completo' => 'Nombre completo',
        'correo' => 'Correo',
        'ciudad' => 'Ciudad',
        'pais' => 'País',
        'organizacion' => 'Organización',
    ];

    private const FIELD_PATTERN = '/\{\{\s*([a-z_]+)\s*(?:\|([^}]*))?\}\}/u';

    /**
     * @param  callable(string): string|null  $trackLink  Rewrites each link in the body (click tracking).
     */
    public function body(string $markdown, Contact $contact, ?callable $trackLink = null): string
    {
        $buttons = [];
        $markdown = preg_replace_callback('/^[ \t]*\[\[([^|\]]+)\|\s*(https?:\/\/[^\]\s]+)\s*\]\][ \t]*$/mu', function (array $m) use (&$buttons) {
            $buttons[] = ['text' => trim($m[1]), 'url' => $m[2]];

            return '@@BOTON'.(count($buttons) - 1).'@@';
        }, $markdown);

        $html = Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        $html = preg_replace_callback('/(?:<p>)?@@BOTON(\d+)@@(?:<\/p>)?/', function (array $m) use ($buttons) {
            $button = $buttons[(int) $m[1]];

            return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td style="border-radius:8px;background:#2F5D2A;">'
                .'<a href="'.e($button['url']).'" style="display:inline-block;padding:12px 24px;color:#FFFFFF;font-weight:600;text-decoration:none;border-radius:8px;">'
                .e($button['text']).'</a></td></tr></table>';
        }, $html);

        $html = str_replace('<img ', '<img style="max-width:100%;height:auto;border-radius:8px;" ', $html);
        $html = preg_replace('/<a href="(?![^"]*" style)/', '<a style="color:#2F5D2A;" href="', $html);

        if ($trackLink) {
            $html = preg_replace_callback('/href="(https?:\/\/[^"]+)"/', fn (array $m) => 'href="'.e($trackLink(html_entity_decode($m[1]))).'"', $html);
        }

        return $this->fill($html, $contact, escape: true);
    }

    public function subject(string $subject, Contact $contact): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->fill($subject, $contact, escape: false)));
    }

    /**
     * Replaces {{campo}} and {{campo|respaldo}} with the contact's data.
     */
    public function fill(string $text, Contact $contact, bool $escape): string
    {
        $values = [
            'nombre' => $contact->first_name,
            'apellido' => $contact->last_name,
            'nombre_completo' => $contact->fullName(),
            'correo' => $contact->email,
            'ciudad' => $contact->city,
            'pais' => $contact->country,
            'organizacion' => $contact->organization,
        ];

        return preg_replace_callback(self::FIELD_PATTERN, function (array $m) use ($values, $escape) {
            if (! array_key_exists($m[1], $values)) {
                return $m[0];
            }
            $value = trim((string) $values[$m[1]]);
            if ($m[1] === 'nombre' && $value !== '') {
                // Imports often carry two given names in capitals ("MARÍA JOSÉ"); greet with the first, nicely cased.
                $value = Str::title(Str::lower(Str::before($value, ' ')));
            }
            if ($value === '') {
                $value = html_entity_decode(trim($m[2] ?? ''));
            }

            return $escape ? e($value) : $value;
        }, $text);
    }

    /**
     * Placeholder contact for previews and test sends.
     */
    public static function sampleContact(?Contact $from = null): Contact
    {
        $contact = new Contact($from ? $from->only(['first_name', 'last_name', 'email', 'city', 'country', 'organization']) : [
            'first_name' => 'María',
            'last_name' => 'Pérez',
            'email' => 'maria@ejemplo.com',
            'city' => 'Cali',
            'country' => 'Colombia',
            'organization' => null,
        ]);
        $contact->unsubscribe_token = 'vista-previa';

        return $contact;
    }
}
