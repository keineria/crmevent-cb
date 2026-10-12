import { crmJson } from '@/components/checkin/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Bold, Braces, Heading2, Image, Italic, Link2, List, Monitor, MousePointerClick, Smartphone } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { type Content } from './types';

type Errors = Partial<Record<keyof Content, string>>;

interface Props {
    data: Content;
    setData: <K extends keyof Content>(key: K, value: Content[K]) => void;
    errors: Errors;
    fields: Record<string, string>;
    nameLabel?: string;
}

/**
 * Name, subject, preview text and Markdown body of a campaign or template, with a live preview
 * rendered by the server exactly as it will be sent.
 */
export default function ContentEditor({ data, setData, errors, fields, nameLabel = 'Nombre interno' }: Props) {
    const body = useRef<HTMLTextAreaElement>(null);
    const lastFocused = useRef<'subject' | 'body'>('body');

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
                <div className="grid gap-2">
                    <Label htmlFor="name">{nameLabel}</Label>
                    <Input id="name" required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    <InputError message={errors.name} />
                </div>
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
                    <Label htmlFor="body">Contenido</Label>
                    <div className="flex flex-wrap items-center gap-1 rounded-md border p-1" role="toolbar" aria-label="Formato del contenido">
                        {tools.map((t) => (
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
                        rows={18}
                        className="font-mono text-sm leading-relaxed"
                        value={data.body}
                        onFocus={() => (lastFocused.current = 'body')}
                        onChange={(e) => setData('body', e.target.value)}
                    />
                    <InputError message={errors.body} />
                    <details className="text-muted-foreground text-xs">
                        <summary className="cursor-pointer">Cómo dar formato</summary>
                        <ul className="mt-2 list-disc space-y-1 pl-5">
                            <li>
                                <code># Título</code>, <code>## Subtítulo</code>, <code>**negrita**</code>, <code>_cursiva_</code>,{' '}
                                <code>- punto de lista</code>.
                            </li>
                            <li>
                                Enlace: <code>[texto](https://…)</code>. Imagen: <code>![descripción](https://…)</code> (súbela antes a la Biblioteca
                                de medios de WordPress y copia su enlace).
                            </li>
                            <li>
                                Botón, en su propia línea: <code>[[Texto del botón|https://…]]</code>.
                            </li>
                            <li>
                                Datos de cada persona: <code>{'{{nombre}}'}</code>. Con respaldo si falta: <code>{'{{nombre|amigo}}'}</code>.
                            </li>
                            <li>El enlace para darse de baja y el pie legal se agregan solos al final.</li>
                        </ul>
                    </details>
                </div>
            </div>

            <Preview subject={data.subject} preheader={data.preheader} body={data.body} />
        </div>
    );
}

function Preview({ subject, preheader, body }: { subject: string; preheader: string; body: string }) {
    const [html, setHtml] = useState('');
    const [renderedSubject, setRenderedSubject] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [mobile, setMobile] = useState(false);

    useEffect(() => {
        const timer = setTimeout(() => {
            crmJson<{ subject: string; html: string }>('/campaigns/preview', { subject, preheader, body })
                .then((r) => {
                    setHtml(r.html);
                    setRenderedSubject(r.subject);
                    setError(null);
                })
                .catch((e: Error) => setError(e.message));
        }, 400);
        return () => clearTimeout(timer);
    }, [subject, preheader, body]);

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
                <p className="text-muted-foreground mt-2 text-xs">Con datos de ejemplo (María Pérez, Cali). Cada persona verá los suyos.</p>
            </div>
        </section>
    );
}
