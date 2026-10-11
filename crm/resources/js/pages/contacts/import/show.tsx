import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { IMPORT_STATUS_LABELS as STATUS_LABELS } from '@/lib/imports';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CircleCheck, CircleX, LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

interface ImportData {
    id: number;
    name: string;
    kind: 'asistente' | 'interesado';
    edition_year: number | null;
    has_consent: boolean;
    headers: string[];
    sample: string[][];
    mapping: (string | null)[];
    status: 'mapping' | 'queued' | 'processing' | 'done' | 'failed';
    total_rows: number;
    created: number;
    updated: number;
    skipped: number;
    errors: { row: number | null; message: string }[];
    finished_at: string | null;
}

interface PageProps {
    import: ImportData;
    fields: Record<string, string>;
    [key: string]: unknown;
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring flex h-9 w-full min-w-40 rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

const number = new Intl.NumberFormat('es-CO');

export default function ImportShow() {
    const { import: imp, fields } = usePage<PageProps>().props;
    const running = imp.status === 'queued' || imp.status === 'processing';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Contactos', href: '/contacts' },
        { title: 'Importar', href: '/contacts/import' },
        { title: imp.name, href: `/contacts/import/${imp.id}` },
    ];

    useEffect(() => {
        if (!running) return;
        const id = setInterval(() => router.reload({ only: ['import'] }), 2000);
        return () => clearInterval(id);
    }, [running]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Importar ${imp.name}`} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <Heading
                    title={imp.status === 'mapping' ? 'Asigna las columnas' : imp.name}
                    description={`${imp.kind === 'asistente' ? 'Asistentes' : 'Interesados'}${imp.edition_year ? ` ${imp.edition_year}` : ''} · ${number.format(imp.total_rows)} filas · ${imp.has_consent ? 'con autorización para boletines' : 'sin autorización para boletines'}`}
                />

                {imp.status === 'mapping' ? <MappingForm imp={imp} fields={fields} /> : <Progress imp={imp} running={running} />}
            </div>
        </AppLayout>
    );
}

function MappingForm({ imp, fields }: { imp: ImportData; fields: Record<string, string> }) {
    const { data, setData, post, processing, errors } = useForm<{ mapping: (string | null)[] }>({ mapping: imp.mapping });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/contacts/import/${imp.id}/run`);
    };

    const setColumn = (index: number, value: string) => {
        const next = [...data.mapping];
        next[index] = value || null;
        setData('mapping', next);
    };

    const used = new Set(data.mapping.filter(Boolean));

    return (
        <form onSubmit={submit} className="space-y-4">
            <p className="text-muted-foreground max-w-3xl text-sm">
                Revisa la sugerencia de cada columna. Las que dejes en «No importar» se ignoran. Si una persona ya existe (mismo correo o documento),
                solo se completan los datos que le falten; nada de lo que ya está guardado se sobrescribe.
            </p>

            <div className="overflow-x-auto rounded-xl border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            {imp.headers.map((header, i) => (
                                <th key={i} scope="col" className="px-3 py-3 align-top font-medium">
                                    <div className="mb-2 truncate" title={header}>
                                        {header || `Columna ${i + 1}`}
                                    </div>
                                    <label>
                                        <span className="sr-only">Campo para la columna {header || i + 1}</span>
                                        <select className={selectClass} value={data.mapping[i] ?? ''} onChange={(e) => setColumn(i, e.target.value)}>
                                            <option value="">No importar</option>
                                            {Object.entries(fields).map(([key, label]) => (
                                                <option key={key} value={key} disabled={used.has(key) && data.mapping[i] !== key}>
                                                    {label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {imp.sample.map((row, r) => (
                            <tr key={r}>
                                {imp.headers.map((_, i) => (
                                    <td key={i} className={`max-w-56 truncate px-3 py-2 ${data.mapping[i] ? '' : 'text-muted-foreground/60'}`}>
                                        {row[i] ?? ''}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <p className="text-muted-foreground text-xs">Vista previa de las primeras {imp.sample.length} filas.</p>

            <InputError message={errors.mapping} />

            <Button type="submit" disabled={processing}>
                {processing && <LoaderCircle className="animate-spin" aria-hidden />}
                Importar {number.format(imp.total_rows)} filas
            </Button>
        </form>
    );
}

function Progress({ imp, running }: { imp: ImportData; running: boolean }) {
    const processed = imp.created + imp.updated + imp.skipped;
    const percent = imp.total_rows ? Math.min(100, Math.round((processed / imp.total_rows) * 100)) : 0;

    return (
        <div className="max-w-3xl space-y-6">
            {running && (
                <div className="space-y-2" role="status">
                    <div className="flex items-center gap-2 text-sm">
                        <LoaderCircle className="size-4 animate-spin" aria-hidden />
                        {STATUS_LABELS[imp.status]}… {percent}%
                    </div>
                    <div className="bg-muted h-2 overflow-hidden rounded-full">
                        <div className="bg-primary h-full transition-[width] duration-500" style={{ width: `${percent}%` }} />
                    </div>
                </div>
            )}

            {imp.status === 'done' && (
                <Alert role="status">
                    <CircleCheck className="h-4 w-4" aria-hidden />
                    <AlertDescription>Importación terminada.</AlertDescription>
                </Alert>
            )}
            {imp.status === 'failed' && (
                <Alert variant="destructive">
                    <CircleX className="h-4 w-4" aria-hidden />
                    <AlertDescription>La importación se detuvo. Los contactos procesados antes del error sí quedaron guardados.</AlertDescription>
                </Alert>
            )}

            <dl className="grid grid-cols-3 gap-3">
                <Count label="Nuevos" value={imp.created} />
                <Count label="Actualizados" value={imp.updated} />
                <Count label="Omitidos" value={imp.skipped} />
            </dl>

            {imp.errors.length > 0 && (
                <section className="space-y-2">
                    <h2 className="text-sm font-medium">Filas con problemas</h2>
                    <ul className="max-h-80 divide-y overflow-auto rounded-xl border text-sm">
                        {imp.errors.map((e, i) => (
                            <li key={i} className="flex gap-3 px-4 py-2">
                                {e.row && <span className="text-muted-foreground w-16 shrink-0 tabular-nums">Fila {e.row}</span>}
                                <span>{e.message}</span>
                            </li>
                        ))}
                    </ul>
                    <p className="text-muted-foreground text-xs">
                        «Omitidos» también incluye filas de personas que ya estaban completas en el CRM. Corrige las filas con problemas en el archivo
                        y vuelve a importarlo: lo ya importado no se duplica.
                    </p>
                </section>
            )}

            {!running && (
                <div className="flex gap-2">
                    <Button asChild>
                        <Link href="/contacts">Ver contactos</Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href="/contacts/import">Importar otro archivo</Link>
                    </Button>
                </div>
            )}
        </div>
    );
}

function Count({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl border p-4">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="mt-1 text-2xl font-semibold tabular-nums">{number.format(value)}</dd>
        </div>
    );
}
