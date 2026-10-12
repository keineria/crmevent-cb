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
import { FileSpreadsheet, LoaderCircle, Upload } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface WorkbookRow {
    id: number;
    name: string;
    original_name: string;
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
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Stand comercial', href: '/stands' }];

export default function StandsIndex() {
    const { workbooks, canEdit } = usePage<PageProps>().props;
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
                    description="Sube el Excel de los stands: el sistema lee sus hojas, tablas y fórmulas y lo muestra aquí. Puedes editar cualquier celda menos las que tienen fórmula, que se recalculan solas."
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
                                    <FileSpreadsheet className="text-primary mt-0.5 size-5 shrink-0" aria-hidden />
                                    <div className="min-w-0 space-y-1">
                                        <p className="truncate font-medium">{w.name}</p>
                                        <p className="text-muted-foreground truncate text-sm">
                                            {w.sheets.length} {w.sheets.length === 1 ? 'hoja' : 'hojas'}: {w.sheets.join(', ')}
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
