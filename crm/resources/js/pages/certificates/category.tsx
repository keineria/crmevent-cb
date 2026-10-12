import Flash from '@/components/campaigns/flash';
import LayoutEditor, { type Layout } from '@/components/certificates/layout-editor';
import TemplateThumb from '@/components/certificates/template-thumb';
import { selectClass } from '@/components/finance/period-filter';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Braces, ImageUp, LoaderCircle, PenLine, Save, Trash2 } from 'lucide-react';
import { type FormEventHandler, useEffect, useRef, useState } from 'react';

type FileKind = 'logo' | 'fondo';

interface Design {
    name: string;
    activity_type: string;
    template: string;
    primary_color: string;
    accent_color: string;
    heading: string;
    intro: string;
    body: string;
    show_qr: boolean;
    signer_ids: number[];
    layout: Layout;
}

interface Signer {
    id: number;
    name: string;
    role: string | null;
    image: string | null;
}

interface PageProps {
    category: Design & { id: number; files: Record<FileKind, string | null>; certificates: number; updated_at: string | null };
    templates: Record<string, { label: string; hint: string }>;
    types: Record<string, string>;
    fields: Record<string, string>;
    blocks: Record<string, string>;
    fonts: Record<string, string>;
    maxSigners: number;
    signers: Signer[];
    [key: string]: unknown;
}

function ImageField({ categoryId, kind, url, label, hint }: { categoryId: number; kind: FileKind; url: string | null; label: string; hint: string }) {
    const input = useRef<HTMLInputElement>(null);
    const [processing, setProcessing] = useState(false);
    const errors = (usePage().props.errors as Record<string, Record<string, string> | undefined>)[kind] ?? {};

    const upload = (file: File | undefined) => {
        if (!file) return;
        router.post(
            `/certificados/categorias/${categoryId}/archivo/${kind}`,
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                errorBag: kind,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    if (input.current) input.current.value = '';
                },
            },
        );
    };

    return (
        <div className="grid gap-2">
            <span className="text-sm font-medium">{label}</span>
            <div className="flex flex-wrap items-center gap-3">
                <div className="bg-muted/40 flex h-14 w-24 items-center justify-center overflow-hidden rounded-md border">
                    {url ? (
                        <img src={url} alt="" className="max-h-full max-w-full object-contain" />
                    ) : (
                        <span className="text-muted-foreground text-xs">Sin imagen</span>
                    )}
                </div>
                <input ref={input} type="file" accept="image/png,image/jpeg" className="sr-only" onChange={(e) => upload(e.target.files?.[0])} />
                <Button type="button" variant="outline" size="sm" disabled={processing} onClick={() => input.current?.click()}>
                    {processing ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : <ImageUp className="size-4" aria-hidden />}
                    {url ? 'Cambiar' : 'Subir'}
                </Button>
                {url && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            router.delete(`/certificados/categorias/${categoryId}/archivo/${kind}`, { preserveScroll: true, preserveState: true })
                        }
                    >
                        <Trash2 className="size-4" aria-hidden />
                        Quitar
                    </Button>
                )}
            </div>
            <p className="text-muted-foreground text-xs">{hint}</p>
            <InputError message={errors.file} />
        </div>
    );
}

function ColorField({
    id,
    label,
    value,
    onChange,
    error,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (v: string) => void;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <div className="flex items-center gap-2">
                <input
                    type="color"
                    aria-label={`${label}: selector`}
                    value={/^#[0-9a-f]{6}$/i.test(value) ? value : '#000000'}
                    onChange={(e) => onChange(e.target.value.toUpperCase())}
                    className="h-10 w-12 cursor-pointer rounded-md border bg-transparent p-1"
                />
                <Input id={id} value={value} maxLength={7} onChange={(e) => onChange(e.target.value)} className="w-28 font-mono uppercase" />
            </div>
            <InputError message={error} />
        </div>
    );
}

/** Asks the server for the sample PDF with the design as it is on screen, a moment after the last change. */
function usePreview(categoryId: number, design: Design, filesKey: string) {
    const [url, setUrl] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const body = JSON.stringify(design);

    useEffect(() => {
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setLoading(true);
            try {
                const token = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
                const response = await fetch(`/certificados/categorias/${categoryId}/vista-previa`, {
                    method: 'POST',
                    headers: { Accept: 'application/pdf, application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
                    body,
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (!response.ok) {
                    const json = await response.json().catch(() => ({}));
                    const errors = json.errors ? Object.values(json.errors as Record<string, string[]>).flat() : [];
                    throw new Error(errors[0] ?? 'No se pudo dibujar la vista previa.');
                }
                const blob = await response.blob();
                setUrl((old) => {
                    if (old) URL.revokeObjectURL(old);
                    return URL.createObjectURL(blob);
                });
                setError(null);
            } catch (e) {
                if ((e as Error).name !== 'AbortError') setError((e as Error).message);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 700);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [categoryId, body, filesKey]);

    return { url, loading, error };
}

export default function CategoryEditor() {
    const { category, templates, types, fields, blocks, fonts, maxSigners, signers } = usePage<PageProps>().props;
    const bodyRef = useRef<HTMLTextAreaElement>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Certificados', href: '/certificados' },
        { title: category.name, href: `/certificados/categorias/${category.id}` },
    ];

    const { data, setData, put, processing, errors, isDirty } = useForm<Design>({
        name: category.name,
        activity_type: category.activity_type,
        template: category.template,
        primary_color: category.primary_color,
        accent_color: category.accent_color,
        heading: category.heading,
        intro: category.intro,
        body: category.body,
        show_qr: category.show_qr,
        signer_ids: category.signer_ids,
        layout: category.layout,
    });

    const preview = usePreview(category.id, data, JSON.stringify(category.files));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/certificados/categorias/${category.id}`, { preserveScroll: true });
    };

    const insertField = (key: string) => {
        const el = bodyRef.current;
        const token = `{{${key}}}`;
        if (!el) {
            setData('body', data.body + token);
            return;
        }
        const { selectionStart: start, selectionEnd: end, value } = el;
        setData('body', value.slice(0, start) + token + value.slice(end));
        requestAnimationFrame(() => {
            el.focus();
            el.setSelectionRange(start + token.length, start + token.length);
        });
    };

    const toggleSigner = (id: number, on: boolean) =>
        setData('signer_ids', on ? [...data.signer_ids, id].slice(0, maxSigners) : data.signer_ids.filter((x) => x !== id));
    const chosenSigners = data.signer_ids.map((id) => signers.find((s) => s.id === id)).filter((s): s is Signer => !!s);
    const own = data.template === 'propio';
    const sample: Record<string, string> = {
        nombre: 'María Pérez Gómez',
        documento: '1.020.304.050',
        actividad: data.activity_type === 'workshop' ? 'Taller de fotografía de aves' : 'Conferencias Colombia Birdfair',
        categoria: data.name,
        fecha: '30 de septiembre de 2027',
        edicion: '2027',
        horas: '8',
    };
    const sampleTexts: Record<string, string> = {
        heading: data.heading,
        intro: data.intro,
        name: sample.nombre,
        document: `Documento ${sample.documento}`,
        body: data.body.replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (m, k: string) => sample[k] ?? m),
    };

    const destroy = () => {
        if (window.confirm(`¿Borrar la categoría ${category.name}? Se quitan también sus actividades asociadas que no se hayan enviado.`)) {
            router.delete(`/certificados/categorias/${category.id}`);
        }
    };

    const layoutErrors = Object.entries(errors as Record<string, string | undefined>).filter(([k]) => k.startsWith('layout.'));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Diseño · ${category.name}`} />

            <form onSubmit={submit} className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={category.name}
                        description={`Diseño de los certificados de ${(types[category.activity_type] ?? '').toLowerCase()}. ${
                            category.updated_at ? `Editado ${dateTime.format(new Date(category.updated_at))}.` : ''
                        }`}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="ghost" onClick={destroy}>
                            <Trash2 aria-hidden />
                            Borrar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? <LoaderCircle className="animate-spin" aria-hidden /> : <Save aria-hidden />}
                            {isDirty ? 'Guardar diseño' : 'Guardado'}
                        </Button>
                    </div>
                </div>

                <Flash />

                <div className="grid gap-6 xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
                    <div className="grid content-start gap-6">
                        <section className="grid gap-4 rounded-xl border p-4">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nombre de la categoría</Label>
                                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="type">Para</Label>
                                <select
                                    id="type"
                                    className={selectClass}
                                    value={data.activity_type}
                                    disabled={category.certificates > 0}
                                    onChange={(e) => setData('activity_type', e.target.value)}
                                >
                                    {Object.entries(types).map(([key, label]) => (
                                        <option key={key} value={key}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                                {category.certificates > 0 && (
                                    <p className="text-muted-foreground text-xs">No se puede cambiar: ya tiene actividades asociadas.</p>
                                )}
                            </div>
                        </section>

                        <section className="grid gap-3 rounded-xl border p-4">
                            <h2 className="font-medium">Plantilla</h2>
                            <div role="radiogroup" aria-label="Plantilla" className="grid grid-cols-2 gap-3">
                                {Object.entries(templates).map(([key, t]) => (
                                    <button
                                        key={key}
                                        type="button"
                                        role="radio"
                                        aria-checked={data.template === key}
                                        onClick={() => setData('template', key)}
                                        className={cn(
                                            'focus-visible:ring-ring grid gap-1.5 rounded-lg border p-2 text-left transition-colors focus-visible:ring-2 focus-visible:outline-hidden',
                                            data.template === key ? 'border-primary ring-primary ring-1' : 'hover:bg-muted/40',
                                        )}
                                    >
                                        <TemplateThumb template={key} primary={data.primary_color} accent={data.accent_color} />
                                        <span className="text-sm font-medium">{t.label}</span>
                                        <span className="text-muted-foreground text-xs">{t.hint}</span>
                                    </button>
                                ))}
                            </div>
                            <div className="flex flex-wrap gap-4">
                                <ColorField
                                    id="primary"
                                    label="Color principal"
                                    value={data.primary_color}
                                    onChange={(v) => setData('primary_color', v)}
                                    error={errors.primary_color}
                                />
                                <ColorField
                                    id="accent"
                                    label="Color de acento"
                                    value={data.accent_color}
                                    onChange={(v) => setData('accent_color', v)}
                                    error={errors.accent_color}
                                />
                            </div>
                        </section>

                        <section className="grid gap-4 rounded-xl border p-4">
                            <h2 className="font-medium">Imágenes</h2>
                            <ImageField
                                categoryId={category.id}
                                kind="logo"
                                url={category.files.logo}
                                label="Logo"
                                hint={
                                    own
                                        ? 'En el diseño propio no se usa: el logo va dentro de tu imagen.'
                                        : 'PNG o JPG, máximo 2 MB. Con fondo transparente se ve mejor.'
                                }
                            />
                            <ImageField
                                categoryId={category.id}
                                kind="fondo"
                                url={category.files.fondo}
                                label={own ? 'Diseño de fondo' : 'Diseño de fondo (opcional)'}
                                hint={
                                    own
                                        ? 'Tu certificado completo sin los datos de cada persona: A4 horizontal (3508 × 2480 px ideal), PNG o JPG hasta 6 MB. Expórtalo como imagen desde Canva, Illustrator o Figma.'
                                        : 'Una imagen A4 horizontal hecha en Canva, Illustrator o Figma (3508 × 2480 px ideal), PNG o JPG hasta 6 MB. Reemplaza los adornos de la plantilla; el texto se escribe encima en el mismo lugar, así que deja ese espacio libre.'
                                }
                            />
                        </section>

                        <section className="grid gap-4 rounded-xl border p-4">
                            <h2 className="font-medium">Texto</h2>
                            <div className="grid gap-2">
                                <Label htmlFor="heading">Título</Label>
                                <Input id="heading" value={data.heading} onChange={(e) => setData('heading', e.target.value)} />
                                <InputError message={errors.heading} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="intro">Frase antes del nombre</Label>
                                <Input
                                    id="intro"
                                    value={data.intro}
                                    onChange={(e) => setData('intro', e.target.value)}
                                    placeholder="Se certifica que"
                                />
                                <InputError message={errors.intro} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="body">Texto después del nombre</Label>
                                <Textarea id="body" ref={bodyRef} rows={4} value={data.body} onChange={(e) => setData('body', e.target.value)} />
                                <div className="flex flex-wrap gap-1.5">
                                    {Object.entries(fields)
                                        .filter(([key]) => key !== 'nombre')
                                        .map(([key, label]) => (
                                            <Button
                                                key={key}
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="h-7 px-2 text-xs"
                                                onClick={() => insertField(key)}
                                            >
                                                <Braces className="size-3" aria-hidden />
                                                {label}
                                            </Button>
                                        ))}
                                </div>
                                <p className="text-muted-foreground text-xs">
                                    La actividad sale en negrilla. Si un certificado no tiene horas, la frase que las nombra se quita sola.
                                </p>
                                <InputError message={errors.body} />
                            </div>
                            <label className="flex items-start gap-3 text-sm">
                                <Checkbox checked={data.show_qr} onCheckedChange={(v) => setData('show_qr', v === true)} className="mt-0.5" />
                                <span>
                                    <span className="font-medium">Código QR de verificación</span>
                                    <span className="text-muted-foreground block">
                                        Lleva a una página pública que confirma que el certificado es auténtico y deja descargarlo de nuevo.
                                    </span>
                                </span>
                            </label>
                        </section>

                        <section className="grid gap-3 rounded-xl border p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="font-medium">Firmas</h2>
                                <Button type="button" variant="ghost" size="sm" asChild>
                                    <Link href="/certificados/firmantes">
                                        <PenLine className="size-4" aria-hidden />
                                        Firmantes
                                    </Link>
                                </Button>
                            </div>
                            {signers.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    Aún no hay firmantes.{' '}
                                    <Link href="/certificados/firmantes" className="text-primary underline">
                                        Agrégalos con su firma
                                    </Link>{' '}
                                    y luego elígelos aquí.
                                </p>
                            ) : (
                                <>
                                    <p className="text-muted-foreground text-xs">Elige hasta {maxSigners}. Salen en el orden en que los marques.</p>
                                    <div className="grid gap-1">
                                        {signers.map((signer) => {
                                            const position = data.signer_ids.indexOf(signer.id);
                                            return (
                                                <label
                                                    key={signer.id}
                                                    className="hover:bg-muted/40 flex cursor-pointer items-center gap-3 rounded-md p-2 text-sm"
                                                >
                                                    <Checkbox
                                                        checked={position >= 0}
                                                        disabled={position < 0 && data.signer_ids.length >= maxSigners}
                                                        onCheckedChange={(v) => toggleSigner(signer.id, v === true)}
                                                    />
                                                    <span className="min-w-0 flex-1">
                                                        <span className="font-medium">{signer.name}</span>
                                                        {signer.role && <span className="text-muted-foreground block text-xs">{signer.role}</span>}
                                                    </span>
                                                    {signer.image && <img src={signer.image} alt="" className="h-8 max-w-24 object-contain" />}
                                                    {position >= 0 && <span className="text-muted-foreground text-xs">{position + 1}.º</span>}
                                                </label>
                                            );
                                        })}
                                    </div>
                                </>
                            )}
                            <InputError message={errors.signer_ids} />
                        </section>
                    </div>

                    <section className="grid content-start gap-2 xl:sticky xl:top-4">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="font-medium">Vista previa</h2>
                            {preview.loading && <LoaderCircle className="text-muted-foreground size-4 animate-spin" aria-label="Actualizando" />}
                        </div>
                        <p className="text-muted-foreground text-xs">
                            Con una persona y una actividad de ejemplo. Se actualiza mientras editas, aunque no hayas guardado.
                        </p>
                        {preview.error && <p className="text-destructive text-sm">{preview.error}</p>}
                        <div className="bg-muted/40 aspect-[297/210] w-full overflow-hidden rounded-xl border">
                            {preview.url && (
                                <iframe
                                    title="Vista previa del certificado"
                                    src={`${preview.url}#toolbar=0&navpanes=0&view=Fit`}
                                    className="h-full w-full"
                                />
                            )}
                        </div>
                        {preview.url && (
                            <a href={preview.url} target="_blank" rel="noopener" className="text-primary text-sm underline">
                                Abrir la vista previa en otra pestaña
                            </a>
                        )}
                    </section>
                </div>

                {own && (
                    <section className="grid gap-3 rounded-xl border p-4">
                        <div>
                            <h2 className="font-medium">Ubicar los textos sobre tu diseño</h2>
                            <p className="text-muted-foreground text-sm">
                                Tu imagen es todo el certificado. Coloca el nombre, el texto, las firmas y el QR donde tu diseño dejó el espacio.
                            </p>
                        </div>
                        {!category.files.fondo && (
                            <p className="border-warning/40 bg-warning/10 rounded-lg border p-3 text-sm">
                                Falta tu diseño: súbelo en «Diseño de fondo» (A4 horizontal, PNG o JPG).
                            </p>
                        )}
                        <LayoutEditor
                            layout={data.layout}
                            onChange={(layout) => setData('layout', layout)}
                            background={category.files.fondo}
                            blocks={blocks}
                            fonts={fonts}
                            texts={sampleTexts}
                            signers={chosenSigners}
                        />
                        {layoutErrors.length > 0 && <InputError message={layoutErrors[0][1]} />}
                    </section>
                )}
            </form>
        </AppLayout>
    );
}
