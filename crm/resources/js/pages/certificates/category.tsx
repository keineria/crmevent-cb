import Flash from '@/components/campaigns/flash';
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
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Braces, ImageUp, LoaderCircle, Save, Trash2 } from 'lucide-react';
import { type FormEventHandler, useEffect, useRef, useState } from 'react';

type FileKind = 'logo' | 'fondo' | 'firma-0' | 'firma-1';

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
    signatures: { name: string; role: string }[];
}

interface PageProps {
    category: Design & { id: number; files: Record<FileKind, string | null>; certificates: number; updated_at: string | null };
    templates: Record<string, { label: string; hint: string }>;
    types: Record<string, string>;
    fields: Record<string, string>;
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
    const { category, templates, types, fields } = usePage<PageProps>().props;
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
        signatures: category.signatures,
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

    const setSigner = (i: number, key: 'name' | 'role', value: string) =>
        setData(
            'signatures',
            data.signatures.map((s, j) => (j === i ? { ...s, [key]: value } : s)),
        );

    const destroy = () => {
        if (window.confirm(`¿Borrar la categoría ${category.name}? Se quitan también sus actividades asociadas que no se hayan enviado.`)) {
            router.delete(`/certificados/categorias/${category.id}`);
        }
    };

    const signatureErrors = errors as Record<string, string | undefined>;

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
                                hint="PNG o JPG, máximo 2 MB. Con fondo transparente se ve mejor."
                            />
                            <ImageField
                                categoryId={category.id}
                                kind="fondo"
                                url={category.files.fondo}
                                label="Diseño de fondo (opcional)"
                                hint="Una imagen A4 horizontal hecha en Canva, Illustrator o Figma (3508 × 2480 px ideal), PNG o JPG hasta 6 MB. Reemplaza los adornos de la plantilla; el texto se escribe encima en el mismo lugar, así que deja ese espacio libre."
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

                        <section className="grid gap-4 rounded-xl border p-4">
                            <h2 className="font-medium">Firmas</h2>
                            {data.signatures.map((s, i) => (
                                <div key={i} className="grid gap-3 border-t pt-4 first:border-t-0 first:pt-0">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor={`sig-name-${i}`}>Nombre de quien firma {i + 1}</Label>
                                            <Input id={`sig-name-${i}`} value={s.name} onChange={(e) => setSigner(i, 'name', e.target.value)} />
                                            <InputError message={signatureErrors[`signatures.${i}.name`]} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor={`sig-role-${i}`}>Cargo</Label>
                                            <Input id={`sig-role-${i}`} value={s.role} onChange={(e) => setSigner(i, 'role', e.target.value)} />
                                            <InputError message={signatureErrors[`signatures.${i}.role`]} />
                                        </div>
                                    </div>
                                    <ImageField
                                        categoryId={category.id}
                                        kind={`firma-${i}` as FileKind}
                                        url={category.files[`firma-${i}` as FileKind]}
                                        label="Imagen de la firma (opcional)"
                                        hint="PNG con fondo transparente, máximo 2 MB. Sin nombre no se muestra."
                                    />
                                </div>
                            ))}
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
            </form>
        </AppLayout>
    );
}
