import { crmJson } from '@/components/checkin/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Bold,
    Braces,
    Code,
    FileUp,
    Heading2,
    Image,
    Italic,
    LayoutTemplate,
    Link2,
    List,
    LoaderCircle,
    Monitor,
    MousePointerClick,
    PenLine,
    Smartphone,
} from 'lucide-react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import { type BodyFormat, type Content } from './types';

type Errors = Partial<Record<keyof Content, string>>;

interface Props {
    data: Content;
    setData: <K extends keyof Content>(key: K, value: Content[K]) => void;
    errors: Errors;
    fields: Record<string, string>;
    nameLabel?: string;
    /** Hides the name field, for emails that have a fixed one. */
    showName?: boolean;
    /** Fields that are a whole block (a QR, a list) and go on their own line. */
    blockFields?: string[];
    /** Server route that renders the preview. */
    previewUrl?: string;
    previewNote?: string;
    /** Replaces the last formatting tip (unsubscribe link and legal footer). */
    footerNote?: ReactNode;
}

/** Starting point for HTML mode: a centred 600px card that reads well on phones and in Outlook. */
const HTML_STARTER = `<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    .card { background: #ffffff; border-radius: 12px; padding: 32px; }
    h1 { font-size: 26px; color: #1F2A1C; margin: 0 0 16px; }
    p { font-size: 16px; line-height: 1.6; color: #3A3631; margin: 0 0 16px; }
    @media (max-width: 620px) { .card { padding: 24px 20px !important; } }
  </style>
</head>
<body style="margin:0;padding:0;background:#F6F3EE;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <td align="center" style="padding:24px 12px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
          <tr>
            <td class="card">
              <h1>Hola {{nombre|amigo}}</h1>
              <p>Escribe aquí tu mensaje.</p>
%%BLOQUES%%              <a href="https://colombiabirdfair.com" style="display:inline-block;padding:12px 24px;background:#2F5D2A;color:#ffffff;border-radius:8px;text-decoration:none;font-weight:600;">Ver más</a>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
`;

/** Plain text of an HTML body, keeping paragraph breaks, for going back to the simple editor. */
function htmlToText(html: string): string {
    const doc = new DOMParser().parseFromString(html.replace(/<(br|\/p|\/h[1-6]|\/li|\/tr|\/div)\b[^>]*>/gi, '$&\n\n'), 'text/html');
    doc.querySelectorAll('style, script, head').forEach((el) => el.remove());
    return (doc.body.textContent ?? '')
        .split('\n')
        .map((l) => l.trim())
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

/**
 * Name, subject, preview text and body of a campaign or template, with a live preview rendered by the server
 * exactly as it will be sent. The body is written in a simple Markdown editor, or pasted as HTML (HTML mode),
 * for designs made in a template builder.
 */
export default function ContentEditor({
    data,
    setData,
    errors,
    fields,
    nameLabel = 'Nombre interno',
    showName = true,
    blockFields = [],
    previewUrl = '/campaigns/preview',
    previewNote = 'Con datos de ejemplo (María Pérez, Cali). Cada persona verá los suyos.',
    footerNote = 'El enlace para darse de baja y el pie legal se agregan solos al final.',
}: Props) {
    const body = useRef<HTMLTextAreaElement>(null);
    const file = useRef<HTMLInputElement>(null);
    const lastFocused = useRef<'subject' | 'body'>('body');
    const [switching, setSwitching] = useState(false);
    const [switchError, setSwitchError] = useState<string | null>(null);
    const html = data.body_format === 'html';

    const switchTo = async (format: BodyFormat) => {
        if (format === data.body_format) return;
        setSwitchError(null);
        if (format === 'html') {
            if (data.body.trim() !== '') {
                // What was written keeps its formatting, now as HTML.
                setSwitching(true);
                try {
                    const r = await crmJson<{ html: string }>('/plantillas/markdown-a-html', { body: data.body });
                    setData('body', r.html);
                } catch (e) {
                    setSwitchError((e as Error).message);
                    return;
                } finally {
                    setSwitching(false);
                }
            }
            setData('body_format', 'html');
            return;
        }
        if (
            data.body.trim() !== '' &&
            !window.confirm('El editor sencillo no usa HTML: se conserva solo el texto y se pierde el diseño. ¿Cambiar de todas formas?')
        ) {
            return;
        }
        setData('body', htmlToText(data.body));
        setData('body_format', 'markdown');
    };

    const insertStarter = () => {
        if (data.body.trim() !== '' && !window.confirm('¿Reemplazar el contenido por la plantilla base?')) return;
        // The email's own blocks ({{qr}}, {{actividades}}) come in already placed.
        setData('body', HTML_STARTER.replace('%%BLOQUES%%', blockFields.map((f) => `              {{${f}}}\n`).join('')));
    };

    const openFile = async (picked: File | undefined) => {
        if (!picked) return;
        if (data.body.trim() !== '' && !window.confirm(`¿Reemplazar el contenido por ${picked.name}?`)) return;
        setData('body', await picked.text());
    };

    /** Replaces the selection in the body with what `make` returns, then reselects the placeholder part. */
    const edit = (make: (selected: string) => { text: string; select?: [number, number] }, ownLine = false) => {
        const el = body.current;
        if (!el) return;
        const { selectionStart: start, selectionEnd: end, value } = el;
        let { text, select } = make(value.slice(start, end));
        if (ownLine) {
            const before = start > 0 && value[start - 1] !== '\n' ? '\n\n' : '';
            const after = value[end] && value[end] !== '\n' ? '\n\n' : '';
            text = before + text + after;
            if (select) select = [select[0] + before.length, select[1] + before.length];
        }
        setData('body', value.slice(0, start) + text + value.slice(end));
        requestAnimationFrame(() => {
            el.focus();
            const [a, b] = select ?? [text.length, text.length];
            el.setSelectionRange(start + a, start + b);
        });
    };

    const wrap = (mark: string, placeholder: string) =>
        edit((sel) => {
            const inner = sel || placeholder;
            return { text: `${mark}${inner}${mark}`, select: [mark.length, mark.length + inner.length] };
        });

    const insertField = (key: string) => {
        if (blockFields.includes(key) && !html) {
            edit(() => ({ text: `{{${key}}}` }), true);
            return;
        }
        const tag = key === 'nombre' ? '{{nombre|amigo}}' : `{{${key}}}`;
        if (lastFocused.current === 'subject') {
            setData('subject', `${data.subject}${data.subject && !data.subject.endsWith(' ') ? ' ' : ''}${tag}`);
            return;
        }
        edit(() => ({ text: tag }));
    };

    const tools = [
        { label: 'Negrita', icon: Bold, run: () => wrap('**', 'texto en negrita') },
        { label: 'Cursiva', icon: Italic, run: () => wrap('_', 'texto en cursiva') },
        {
            label: 'Subtítulo',
            icon: Heading2,
            run: () => edit((sel) => ({ text: `## ${sel || 'Subtítulo'}`, select: [3, 3 + (sel || 'Subtítulo').length] }), true),
        },
        {
            label: 'Lista',
            icon: List,
            run: () =>
                edit(
                    (sel) => ({
                        text: (sel || 'Primer punto\nSegundo punto')
                            .split('\n')
                            .map((l) => `- ${l}`)
                            .join('\n'),
                    }),
                    true,
                ),
        },
        {
            label: 'Enlace',
            icon: Link2,
            run: () =>
                edit((sel) => {
                    const t = sel || 'texto del enlace';
                    return { text: `[${t}](https://)`, select: [t.length + 3, t.length + 11] };
                }),
        },
        {
            label: 'Botón',
            icon: MousePointerClick,
            run: () =>
                edit(
                    (sel) => ({ text: `[[${sel || 'Inscribirme'}|https://colombiabirdfair.com]]`, select: [2, 2 + (sel || 'Inscribirme').length] }),
                    true,
                ),
        },
        {
            label: 'Imagen',
            icon: Image,
            run: () => edit(() => ({ text: '![Descripción de la imagen](https://)', select: [28, 36] }), true),
        },
    ];

    return (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div className="grid content-start gap-4">
                {showName && (
                    <div className="grid gap-2">
                        <Label htmlFor="name">{nameLabel}</Label>
                        <Input id="name" required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        <InputError message={errors.name} />
                    </div>
                )}
                <div className="grid gap-2">
                    <Label htmlFor="subject">Asunto</Label>
                    <Input
                        id="subject"
                        value={data.subject}
                        placeholder="{{nombre|Hola}}, ya abrimos inscripciones"
                        onFocus={() => (lastFocused.current = 'subject')}
                        onChange={(e) => setData('subject', e.target.value)}
                    />
                    <p className="text-muted-foreground text-xs">
                        {data.subject.length} caracteres. Lo ideal es menos de 60 para que se lea completo en el celular.
                    </p>
                    <InputError message={errors.subject} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="preheader">Texto de vista previa</Label>
                    <Input
                        id="preheader"
                        value={data.preheader}
                        placeholder="Aparece junto al asunto en la bandeja de entrada"
                        onChange={(e) => setData('preheader', e.target.value)}
                    />
                    <InputError message={errors.preheader} />
                </div>
                <div className="grid gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Label htmlFor="body">Contenido</Label>
                        <div className="bg-muted inline-flex rounded-md p-0.5" role="group" aria-label="Forma de editar el contenido">
                            <Button
                                type="button"
                                size="sm"
                                variant={html ? 'ghost' : 'secondary'}
                                aria-pressed={!html}
                                disabled={switching}
                                onClick={() => switchTo('markdown')}
                            >
                                <PenLine aria-hidden />
                                Editor
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant={html ? 'secondary' : 'ghost'}
                                aria-pressed={html}
                                disabled={switching}
                                onClick={() => switchTo('html')}
                            >
                                {switching ? <LoaderCircle className="animate-spin" aria-hidden /> : <Code aria-hidden />}
                                HTML
                            </Button>
                        </div>
                    </div>
                    <InputError message={switchError ?? undefined} />
                    <div className="flex flex-wrap items-center gap-1 rounded-md border p-1" role="toolbar" aria-label="Formato del contenido">
                        {html && (
                            <>
                                <Button type="button" variant="ghost" size="sm" onClick={() => file.current?.click()} title="Abrir un archivo .html">
                                    <FileUp aria-hidden />
                                    <span className="sr-only sm:not-sr-only">Abrir archivo</span>
                                </Button>
                                <input
                                    ref={file}
                                    type="file"
                                    accept=".html,.htm,text/html"
                                    className="hidden"
                                    onChange={(e) => {
                                        openFile(e.target.files?.[0]);
                                        e.target.value = '';
                                    }}
                                />
                                <Button type="button" variant="ghost" size="sm" onClick={insertStarter} title="Empezar con una plantilla base">
                                    <LayoutTemplate aria-hidden />
                                    <span className="sr-only sm:not-sr-only">Plantilla base</span>
                                </Button>
                            </>
                        )}
                        {!html &&
                            tools.map((t) => (
                                <Button key={t.label} type="button" variant="ghost" size="sm" onClick={t.run} title={t.label}>
                                    <t.icon aria-hidden />
                                    <span className="sr-only sm:not-sr-only">{t.label}</span>
                                </Button>
                            ))}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button type="button" variant="ghost" size="sm" title="Campo personalizado">
                                    <Braces aria-hidden />
                                    <span className="sr-only sm:not-sr-only">Campo</span>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {Object.entries(fields).map(([key, label]) => (
                                    <DropdownMenuItem key={key} onSelect={() => insertField(key)}>
                                        {label}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                    <Textarea
                        id="body"
                        ref={body}
                        rows={html ? 26 : 18}
                        spellCheck={!html}
                        placeholder={html ? 'Pega aquí el código HTML completo de tu plantilla, o solo una parte.' : undefined}
                        className={`font-mono text-sm leading-relaxed ${html ? 'text-xs' : ''}`}
                        value={data.body}
                        onFocus={() => (lastFocused.current = 'body')}
                        onChange={(e) => setData('body', e.target.value)}
                        onKeyDown={(e) => {
                            // In code, Tab indents instead of leaving the field.
                            if (html && e.key === 'Tab' && !e.shiftKey) {
                                e.preventDefault();
                                edit(() => ({ text: '  ' }));
                            }
                        }}
                    />
                    <InputError message={errors.body} />
                    <InputError message={errors.body_format} />
                    {html ? (
                        <details className="text-muted-foreground text-xs">
                            <summary className="cursor-pointer">Cómo usar el modo HTML</summary>
                            <ul className="mt-2 list-disc space-y-1 pl-5">
                                <li>
                                    Pega el código completo que exportas de tu creador de plantillas (con <code>&lt;html&gt;</code> y{' '}
                                    <code>&lt;body&gt;</code>): se envía con tu propio diseño. Si pegas solo una parte, va dentro del diseño del CRM
                                    con el logo.
                                </li>
                                <li>
                                    Los campos funcionan igual: <code>{'{{nombre|amigo}}'}</code>, también dentro de enlaces (
                                    <code>{'href="https://…?correo={{correo}}"'}</code>).
                                </li>
                                <li>
                                    Al enviar se quitan scripts, formularios y videos incrustados, y los estilos de <code>&lt;style&gt;</code> se
                                    copian a cada elemento para que se vean en Gmail y Outlook.
                                </li>
                                <li>
                                    Las imágenes deben estar en internet (por ejemplo en la Biblioteca de medios de WordPress), con su enlace https.
                                </li>
                                <li>{footerNote}</li>
                            </ul>
                        </details>
                    ) : (
                        <details className="text-muted-foreground text-xs">
                            <summary className="cursor-pointer">Cómo dar formato</summary>
                            <ul className="mt-2 list-disc space-y-1 pl-5">
                                <li>
                                    <code># Título</code>, <code>## Subtítulo</code>, <code>**negrita**</code>, <code>_cursiva_</code>,{' '}
                                    <code>- punto de lista</code>.
                                </li>
                                <li>
                                    Enlace: <code>[texto](https://…)</code>. Imagen: <code>![descripción](https://…)</code> (súbela antes a la
                                    Biblioteca de medios de WordPress y copia su enlace).
                                </li>
                                <li>
                                    Botón, en su propia línea: <code>[[Texto del botón|https://…]]</code>.
                                </li>
                                <li>
                                    Datos de cada persona: <code>{'{{nombre}}'}</code>. Con respaldo si falta: <code>{'{{nombre|amigo}}'}</code>.
                                </li>
                                <li>{footerNote}</li>
                            </ul>
                        </details>
                    )}
                </div>
            </div>

            <Preview
                subject={data.subject}
                preheader={data.preheader}
                body={data.body}
                format={data.body_format}
                url={previewUrl}
                note={previewNote}
            />
        </div>
    );
}

function Preview({
    subject,
    preheader,
    body,
    format,
    url,
    note,
}: {
    subject: string;
    preheader: string;
    body: string;
    format: BodyFormat;
    url: string;
    note: string;
}) {
    const [html, setHtml] = useState('');
    const [renderedSubject, setRenderedSubject] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [mobile, setMobile] = useState(false);

    useEffect(() => {
        const timer = setTimeout(() => {
            crmJson<{ subject: string; html: string }>(url, { subject, preheader, body, body_format: format })
                .then((r) => {
                    setHtml(r.html);
                    setRenderedSubject(r.subject);
                    setError(null);
                })
                .catch((e: Error) => setError(e.message));
        }, 400);
        return () => clearTimeout(timer);
    }, [subject, preheader, body, format, url]);

    return (
        <section aria-label="Vista previa" className="grid content-start gap-2 xl:sticky xl:top-4">
            <div className="flex items-center justify-between gap-2">
                <h2 className="text-sm font-medium">Vista previa</h2>
                <div className="flex gap-1">
                    <Button type="button" size="sm" variant={mobile ? 'ghost' : 'secondary'} onClick={() => setMobile(false)} aria-pressed={!mobile}>
                        <Monitor aria-hidden />
                        <span className="sr-only">Computador</span>
                    </Button>
                    <Button type="button" size="sm" variant={mobile ? 'secondary' : 'ghost'} onClick={() => setMobile(true)} aria-pressed={mobile}>
                        <Smartphone aria-hidden />
                        <span className="sr-only">Celular</span>
                    </Button>
                </div>
            </div>
            <div className="bg-muted/40 rounded-xl border p-3">
                <div className="bg-background mb-3 rounded-md border px-3 py-2 text-sm">
                    <div className="truncate font-medium">{renderedSubject || 'Sin asunto'}</div>
                    {preheader && <div className="text-muted-foreground truncate text-xs">{preheader}</div>}
                </div>
                {error ? (
                    <p className="text-destructive p-4 text-sm">{error}</p>
                ) : (
                    <iframe
                        title="Vista previa del correo"
                        srcDoc={html}
                        sandbox=""
                        className={`mx-auto block h-[640px] rounded-md border bg-white transition-[width] ${mobile ? 'w-[375px] max-w-full' : 'w-full'}`}
                    />
                )}
                <p className="text-muted-foreground mt-2 text-xs">{note}</p>
            </div>
        </section>
    );
}
