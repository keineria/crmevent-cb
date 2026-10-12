import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { IMPORT_STATUS_LABELS as STATUS_LABELS } from '@/lib/imports';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

interface RecentImport {
    id: number;
    name: string;
    kind: 'asistente' | 'interesado';
    edition_year: number | null;
    status: string;
    created: number;
    updated: number;
    skipped: number;
    user: string | null;
    created_at: string;
}

interface PageProps {
    recent: RecentImport[];
    defaultYear: number;
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Contactos', href: '/contacts' },
    { title: 'Importar', href: '/contacts/import' },
];

const dateFormat = new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Bogota' });

export default function ImportCreate() {
    const { recent, defaultYear } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors, progress } = useForm<{
        file: File | null;
        kind: 'asistente' | 'interesado';
        edition_year: string;
        has_consent: boolean;
    }>({ file: null, kind: 'asistente', edition_year: String(defaultYear - 1), has_consent: false });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/contacts/import', { forceFormData: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Importar contactos" />

            <div className="flex max-w-3xl min-w-0 flex-1 flex-col gap-8 p-4 md:p-6">
                <Heading
                    title="Importar contactos"
                    description="Sube un Excel (.xlsx) o .csv. La primera fila debe tener los títulos de las columnas. En el siguiente paso eliges qué columna va a cada campo."
                />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="file">Archivo</Label>
                        <Input
                            id="file"
                            type="file"
                            accept=".xlsx,.csv"
                            required
                            onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                            aria-describedby="file-hint"
                        />
                        <p id="file-hint" className="text-muted-foreground text-xs">
                            Máximo 20 MB. Si es un .xls antiguo, ábrelo en Excel y guárdalo como .xlsx.
                        </p>
                        <InputError message={errors.file} />
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm font-medium">¿Quiénes son?</legend>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {(
                                [
                                    ['asistente', 'Asistentes', 'Personas que fueron a una feria.'],
                                    ['interesado', 'Interesados', 'Personas que pidieron información o dejaron sus datos.'],
                                ] as const
                            ).map(([value, title, hint]) => (
                                <label
                                    key={value}
                                    className="has-[:checked]:border-primary has-[:checked]:bg-primary/5 flex cursor-pointer gap-3 rounded-lg border p-4"
                                >
                                    <input
                                        type="radio"
                                        name="kind"
                                        value={value}
                                        checked={data.kind === value}
                                        onChange={() => setData('kind', value)}
                                        className="accent-primary mt-1"
                                    />
                                    <span>
                                        <span className="block text-sm font-medium">{title}</span>
                                        <span className="text-muted-foreground block text-xs">{hint}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.kind} />
                    </fieldset>

                    <div className="grid max-w-xs gap-2">
                        <Label htmlFor="edition_year">Año de la feria</Label>
                        <Input
                            id="edition_year"
                            type="number"
                            min={2000}
                            max={2100}
                            value={data.edition_year}
                            onChange={(e) => setData('edition_year', e.target.value)}
                            aria-describedby="year-hint"
                        />
                        <p id="year-hint" className="text-muted-foreground text-xs">
                            Déjalo vacío si el archivo trae una columna con el año o si no aplica.
                        </p>
                        <InputError message={errors.edition_year} />
                    </div>

                    <div className="rounded-lg border p-4">
                        <label className="flex gap-3 text-sm">
                            <Checkbox checked={data.has_consent} onCheckedChange={(v) => setData('has_consent', v === true)} className="mt-0.5" />
                            <span>
                                <span className="block font-medium">Estas personas autorizaron recibir boletines</span>
                                <span className="text-muted-foreground block text-xs">
                                    Márcalo solo si, al registrarse, aceptaron el tratamiento de datos para comunicaciones de la feria (Ley 1581 de
                                    2012). Sin esta autorización quedan en el CRM, pero no reciben difusiones.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div className="flex items-center gap-4">
                        <Button type="submit" disabled={processing || !data.file}>
                            {processing && <LoaderCircle className="animate-spin" aria-hidden />}
                            Subir y continuar
                        </Button>
                        {progress && <span className="text-muted-foreground text-sm tabular-nums">{progress.percentage}%</span>}
                    </div>
                </form>

                {recent.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-sm font-medium">Importaciones recientes</h2>
                        <ul className="divide-y rounded-xl border text-sm">
                            {recent.map((i) => (
                                <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                                    <div>
                                        <Link href={`/contacts/import/${i.id}`} className="font-medium underline-offset-4 hover:underline">
                                            {i.name}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">
                                            {i.kind === 'asistente' ? 'Asistentes' : 'Interesados'}
                                            {i.edition_year ? ` ${i.edition_year}` : ''} · {i.user ?? '—'} ·{' '}
                                            {dateFormat.format(new Date(i.created_at))}
                                        </div>
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        {i.status === 'done'
                                            ? `${i.created} nuevos · ${i.updated} actualizados · ${i.skipped} omitidos`
                                            : STATUS_LABELS[i.status]}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
