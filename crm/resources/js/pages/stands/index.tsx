import Flash from '@/components/campaigns/flash';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dateTime, number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FileSpreadsheet, KeyRound, Link2, LoaderCircle, Sheet, Upload } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface WorkbookRow {
    id: number;
    name: string;
    original_name: string;
    source: 'excel' | 'google';
    sheets: string[];
    edits: number;
    creator: string | null;
    editor: string | null;
    created_at: string;
    updated_at: string;
}

interface PageProps {
    workbooks: WorkbookRow[];
    canEdit: boolean;
    google: { email: string | null; canConfigure: boolean };
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Stand comercial', href: '/stands' }];

export default function StandsIndex() {
    const { workbooks, canEdit, google } = usePage<PageProps>().props;
    const sheetForm = useForm({ url: '', sheet: 'Stands', name: '' });
    const keyForm = useForm<{ key: File | null }>({ key: null });
    const { data, setData, post, processing, errors, reset } = useForm<{ file: File | null; name: string }>({ file: null, name: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/stands', { forceFormData: true, onSuccess: () => reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stand comercial" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Stand comercial"
                    description="Sube el Excel de los stands o conecta la hoja de Google donde los llevan. Puedes editar cualquier celda menos las que tienen fórmula, que se recalculan solas, y seguir en «Stands comprados» quién pagó, tiene los documentos y está facturado."
                />

                <Flash />

                {canEdit && (
                    <Card>
                        <CardContent>
                            <form onSubmit={submit} className="grid gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
                                <div className="grid gap-2">
                                    <Label htmlFor="file">Archivo de Excel</Label>
                                    <Input
                                        id="file"
                                        type="file"
                                        accept=".xlsx,.xls,.ods"
                                        required
                                        onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                                    />
                                    <InputError message={errors.file} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Nombre (opcional)</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        maxLength={120}
                                        placeholder="Ej. Stands 2027"
                                        onChange={(e) => setData('name', e.target.value)}
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <Button type="submit" disabled={processing || !data.file}>
                                    {processing ? <LoaderCircle className="animate-spin" aria-hidden /> : <Upload aria-hidden />}
                                    Subir Excel
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {canEdit && (
                    <Card>
                        <CardContent className="space-y-4">
                            <div className="space-y-1">
                                <h3 className="flex items-center gap-2 font-medium">
                                    <Sheet className="text-primary size-4" aria-hidden />
                                    Conectar una hoja de Google
                                </h3>
                                <p className="text-muted-foreground text-sm">
                                    Lo que edites aquí se escribe en la hoja al instante, y lo que cambien en Drive llega solo en un minuto. La hoja
                                    tiene que ser de Hojas de cálculo de Google (no un .xlsx en Drive) y estar compartida como Editor con{' '}
                                    {google.email ? <strong className="text-foreground break-all">{google.email}</strong> : 'la cuenta de servicio'}.
                                </p>
                            </div>

                            {google.canConfigure && (
                                <form
                                    className="flex flex-wrap items-end gap-3"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        keyForm.post('/stands/google/clave', {
                                            forceFormData: true,
                                            preserveScroll: true,
                                            onSuccess: () => keyForm.reset(),
                                        });
                                    }}
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="key">
                                            {google.email
                                                ? 'Cambiar la clave de la cuenta de servicio (.json)'
                                                : 'Clave de la cuenta de servicio (.json)'}
                                        </Label>
                                        <Input
                                            id="key"
                                            type="file"
                                            accept=".json,application/json"
                                            onChange={(e) => keyForm.setData('key', e.target.files?.[0] ?? null)}
                                        />
                                        <InputError message={keyForm.errors.key} />
                                    </div>
                                    <Button type="submit" variant="outline" disabled={keyForm.processing || !keyForm.data.key}>
                                        <KeyRound aria-hidden />
                                        Guardar clave
                                    </Button>
                                </form>
                            )}

                            {google.email ? (
                                <form
                                    className="grid gap-4 md:grid-cols-[2fr_1fr_1fr_auto] md:items-end"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        sheetForm.post('/stands/google');
                                    }}
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="url">Enlace de la hoja</Label>
                                        <Input
                                            id="url"
                                            required
                                            placeholder="https://docs.google.com/spreadsheets/d/…"
                                            value={sheetForm.data.url}
                                            onChange={(e) => sheetForm.setData('url', e.target.value)}
                                        />
                                        <InputError message={sheetForm.errors.url} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="sheet">Pestaña</Label>
                                        <Input
                                            id="sheet"
                                            required
                                            value={sheetForm.data.sheet}
                                            onChange={(e) => sheetForm.setData('sheet', e.target.value)}
                                        />
                                        <InputError message={sheetForm.errors.sheet} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="gname">Nombre (opcional)</Label>
                                        <Input
                                            id="gname"
                                            maxLength={120}
                                            value={sheetForm.data.name}
                                            onChange={(e) => sheetForm.setData('name', e.target.value)}
                                        />
                                    </div>
                                    <Button type="submit" disabled={sheetForm.processing}>
                                        {sheetForm.processing ? <LoaderCircle className="animate-spin" aria-hidden /> : <Link2 aria-hidden />}
                                        Conectar
                                    </Button>
                                </form>
                            ) : (
                                !google.canConfigure && (
                                    <p className="text-muted-foreground text-sm">
                                        Un administrador tiene que subir primero la clave de la cuenta de servicio de Google.
                                    </p>
                                )
                            )}
                        </CardContent>
                    </Card>
                )}

                {workbooks.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm">
                        Todavía no hay ningún Excel del stand comercial.
                    </p>
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {workbooks.map((w) => (
                            <li key={w.id}>
                                <Link
                                    href={`/stands/${w.id}`}
                                    className="hover:bg-accent focus-visible:ring-ring flex h-full gap-3 rounded-lg border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    {w.source === 'google' ? (
                                        <Sheet className="text-primary mt-0.5 size-5 shrink-0" aria-hidden />
                                    ) : (
                                        <FileSpreadsheet className="text-primary mt-0.5 size-5 shrink-0" aria-hidden />
                                    )}
                                    <div className="min-w-0 space-y-1">
                                        <p className="truncate font-medium">{w.name}</p>
                                        <p className="text-muted-foreground truncate text-sm">
                                            {w.source === 'google'
                                                ? `Hoja de Google · pestaña ${w.sheets.join(', ')}`
                                                : `${w.sheets.length} ${w.sheets.length === 1 ? 'hoja' : 'hojas'}: ${w.sheets.join(', ')}`}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {number.format(w.edits)} {w.edits === 1 ? 'cambio' : 'cambios'} · actualizado{' '}
                                            {dateTime.format(new Date(w.updated_at))}
                                            {w.editor ? ` por ${w.editor}` : ''}
                                        </p>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
