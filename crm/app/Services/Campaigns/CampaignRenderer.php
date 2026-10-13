<?php

namespace App\Services\Campaigns;

use App\Models\Contact;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * Turns a campaign's body into the email people receive. The body is Markdown, or HTML pasted from a template builder
 * (FORMAT_HTML): a fragment goes inside the CRM layout, a whole document (with <html> or <body>) is sent as written.
 *
 * In both formats it understands:
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

    public const FORMAT_MARKDOWN = 'markdown';

    public const FORMAT_HTML = 'html';

    public const FORMATS = [self::FORMAT_MARKDOWN, self::FORMAT_HTML];

    /** Elements dropped with everything inside them. */
    private const BLOCKED_TAGS = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button',
        'textarea', 'select', 'base', 'noscript', 'template', 'portal'];

    /** Attributes holding a URL, checked for javascript: and the like. */
    private const URL_ATTRIBUTES = ['href', 'src', 'background', 'action', 'poster', 'xlink:href', 'formaction', 'srcset'];

    private const FIELD_PATTERN = '/\{\{\s*([a-z_]+)\s*(?:\|([^}]*))?\}\}/u';

    /**
     * @param  callable(string): string|null  $trackLink  Rewrites each link in the body (click tracking).
     */
    public function body(string $markdown, Contact $contact, ?callable $trackLink = null, string $format = self::FORMAT_MARKDOWN): string
    {
        return $this->fill($this->html($markdown, $trackLink, $format), $contact, escape: true);
    }

    /**
     * Markdown (or pasted HTML) and buttons to email-safe HTML, before any {{campo}} is replaced.
     *
     * @param  callable(string): string|null  $trackLink
     */
    public function html(string $markdown, ?callable $trackLink = null, string $format = self::FORMAT_MARKDOWN): string
    {
        if ($format === self::FORMAT_HTML) {
            $html = $this->cleanHtml($this->buttons($markdown, fn (array $b) => $this->button($b['text'], $b['url'])));

            return $trackLink ? $this->trackLinks($html, $trackLink) : $html;
        }

        $buttons = [];
        $markdown = $this->buttons($markdown, function (array $button) use (&$buttons) {
            $buttons[] = $button;

            return '@@BOTON'.(count($buttons) - 1).'@@';
        });

        $html = Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        $html = preg_replace_callback('/(?:<p>)?@@BOTON(\d+)@@(?:<\/p>)?/', fn (array $m) => $this->button($buttons[(int) $m[1]]['text'], $buttons[(int) $m[1]]['url']), $html);

        $html = str_replace('<img ', '<img style="max-width:100%;height:auto;border-radius:8px;" ', $html);
        $html = preg_replace('/<a href="(?![^"]*" style)/', '<a style="color:#2F5D2A;" href="', $html);

        return $trackLink ? $this->trackLinks($html, $trackLink) : $html;
    }

    /**
     * True when the body is a whole HTML document, sent as written instead of inside the CRM layout.
     */
    public static function isDocument(string $html): bool
    {
        return (bool) preg_match('/<(html|body)[\s>]/i', $html);
    }

    /**
     * A whole pasted document as sent: the preview text right after <body> and $append (footer, tracking pixel)
     * right before </body>.
     */
    public static function document(string $html, ?string $preheader, string $append = ''): string
    {
        if ($preheader) {
            $hidden = '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">'.e($preheader)
                .str_repeat('&#8204;&nbsp;', 6).'</div>';
            $html = preg_replace_callback('/<body\b[^>]*>/i', fn (array $m) => $m[0].$hidden, $html, 1);
        }
        if ($append !== '') {
            $html = str_contains(strtolower($html), '</body>')
                ? substr_replace($html, $append, strripos($html, '</body>'), 0)
                : $html.$append;
        }

        return $html;
    }

    /**
     * The CRM's button: a table cell so it keeps its colour in Outlook.
     */
    public function button(string $text, string $url): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td style="border-radius:8px;background:#2F5D2A;">'
            .'<a href="'.e($url).'" style="display:inline-block;padding:12px 24px;color:#FFFFFF;font-weight:600;text-decoration:none;border-radius:8px;">'
            .e($text).'</a></td></tr></table>';
    }

    /**
     * Pasted HTML made safe to send: no scripts, forms, embeds or event handlers, no javascript: links, and the
     * rules of its <style> blocks copied onto each element (many email apps ignore <style>). The <style> blocks
     * stay for the apps that read them (media queries). {{campos}} survive untouched, also inside links.
     */
    public function cleanHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // libxml percent-encodes {{ }} inside href and src; park the fields as plain words while the HTML is parsed.
        $fields = [];
        $html = preg_replace_callback('/\{\{.*?\}\}/su', function (array $m) use (&$fields) {
            $fields[] = $m[0];

            return 'crmcampo'.(count($fields) - 1).'x';
        }, $html);

        $document = self::isDocument($html);
        $dom = $this->parse($document ? $html : '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="crm-fragment">'.$html.'</div></body></html>');
        $this->sanitize($dom);

        if ($dom->getElementsByTagName('style')->length > 0) {
            $dom = $this->parse((new CssToInlineStyles)->convert($this->serialize($dom, true)));
        }

        if ($document) {
            $out = $this->serialize($dom, true);
        } else {
            $root = $dom->getElementById('crm-fragment');
            $out = '';
            foreach ($root?->childNodes ?? [] as $node) {
                $out .= $dom->saveHTML($node);
            }
        }

        return preg_replace_callback('/crmcampo(\d+)x/', fn (array $m) => $fields[(int) $m[1]] ?? $m[0], $out);
    }

    private function parse(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $errors = libxml_use_internal_errors(true);
        $dom->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'), LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);

        return $dom;
    }

    private function serialize(DOMDocument $dom, bool $withDoctype): string
    {
        $html = $dom->documentElement ? $dom->saveHTML($dom->documentElement) : '';

        return ($withDoctype ? "<!DOCTYPE html>\n" : '').$html;
    }

    private function sanitize(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);

        foreach (self::BLOCKED_TAGS as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        foreach (iterator_to_array($xpath->query('//meta[@http-equiv] | //link[not(@rel="stylesheet")]')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        /** @var DOMElement $element */
        foreach (iterator_to_array($xpath->query('//*[@*]')) as $element) {
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = $attribute->nodeValue ?? '';
                $plain = strtolower(preg_replace('/[\s\x00-\x1F]+/', '', html_entity_decode($value)));

                $unsafe = str_starts_with($name, 'on')
                    || $name === 'srcdoc'
                    || (in_array($name, self::URL_ATTRIBUTES, true) && preg_match('/^(javascript|vbscript|livescript):/', $plain))
                    || (in_array($name, self::URL_ATTRIBUTES, true) && str_starts_with($plain, 'data:') && ! ($name === 'src' && str_starts_with($plain, 'data:image/') && ! str_starts_with($plain, 'data:image/svg')))
                    || ($name === 'style' && preg_match('/expression\(|javascript:|behavior:|-moz-binding/', $plain));

                if ($unsafe) {
                    $element->removeAttribute($attribute->nodeName);
                }
            }
        }

        foreach ($dom->getElementsByTagName('style') as $style) {
            $css = (string) $style->textContent;
            if (preg_match('/expression\(|javascript:|behavior:|-moz-binding|@import/i', $css)) {
                $style->textContent = preg_replace('/expression\(|javascript:|behavior:|-moz-binding|@import[^;]*;?/i', '', $css);
            }
        }
    }

    /**
     * Replaces each [[Texto|https://enlace]] line with what $make returns for it.
     *
     * @param  callable(array{text: string, url: string}): string  $make
     */
    private function buttons(string $body, callable $make): string
    {
        return preg_replace_callback('/^[ \t]*\[\[([^|\]]+)\|\s*(https?:\/\/[^\]\s]+)\s*\]\][ \t]*$/mu',
            fn (array $m) => $make(['text' => trim($m[1]), 'url' => $m[2]]), $body);
    }

    /**
     * @param  callable(string): string  $trackLink
     */
    private function trackLinks(string $html, callable $trackLink): string
    {
        return preg_replace_callback('/href="(https?:\/\/[^"]+)"/', fn (array $m) => 'href="'.e($trackLink(html_entity_decode($m[1]))).'"', $html);
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

        return $this->replace($text, $values, $escape);
    }

    /**
     * Replaces {{campo}} and {{campo|respaldo}} with the given values; unknown fields stay as written.
     *
     * @param  array<string, string|null>  $values
     */
    public function replace(string $text, array $values, bool $escape): string
    {
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
