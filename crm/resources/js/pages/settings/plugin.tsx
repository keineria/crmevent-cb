import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CircleCheck, CircleX, LoaderCircle, RefreshCw } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Connection {
    base_url: string | null;
    has_key: boolean;
    created_after: string | null;
    enabled: boolean;
    last_synced_at: string | null;
    last_status: 'ok' | 'error' | null;
    last_error: string | null;
    last_result: { activities: number; orders: number; participants: number; new_contacts: number; has_more: boolean } | null;
}

interface PageProps {
    connection: Connection;
    counts: { registrations: number; paid: number };
    flash: { success?: string; error?: string };
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Plugin de inscripciones', href: '/settings/plugin' }];
const dateFormat = new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Bogota' });

export default function PluginSettings() {
    const { connection, counts, flash } = usePage<PageProps>().props;
    const [busy, setBusy] = useState<'test' | 'sync' | null>(null);
    const { data, setData, put, processing, errors } = useForm({
        base_url: connection.base_url ?? '',
        api_key: '',
        created_after: connection.created_after ?? '',
        enabled: connection.enabled,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/settings/plugin', { preserveScroll: true, onSuccess: () => setData('api_key', '') });
    };

    const action = (kind: 'test' | 'sync', extra: Record<string, boolean> = {}) => {
        setBusy(kind);
        router.post(`/settings/plugin/${kind}`, extra, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Plugin de inscripciones" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Plugin de inscripciones"
                        description="Conecta el CRM con el plugin de WordPress para traer las inscripciones. El plugin sigue siendo el que controla cupos, precios y pagos."
                    />

                    {flash.success && (
                        <Alert role="status">
                            <CircleCheck className="h-4 w-4" aria-hidden />
                            <AlertDescription>{flash.success}</AlertDescription>
                        </Alert>
                    )}
                    {flash.error && (
                        <Alert variant="destructive">
                            <CircleX className="h-4 w-4" aria-hidden />
                            <AlertDescription>{flash.error}</AlertDescription>
                        </Alert>
                    )}

                    <ol className="text-muted-foreground list-decimal space-y-1 pl-5 text-sm">
                        <li>
                            En WordPress, instala y activa el plugin <strong className="text-foreground">EVR CRM Bridge</strong>.
                        </li>
                        <li>
                            Ve a <strong className="text-foreground">Ajustes › CRM 360</strong> y copia la URL y la clave.
                        </li>
                        <li>Pégalas aquí, guarda y prueba la conexión.</li>
                    </ol>

                    <form onSubmit={submit} className="space-y-5">
                        <div className="grid gap-2">
                            <Label htmlFor="base_url">URL de la API</Label>
                            <Input
                                id="base_url"
                                type="url"
                                required
                                placeholder="https://colombiabirdfair.com/wp-json/evr-crm/v1"
                                value={data.base_url}
                                onChange={(e) => setData('base_url', e.target.value)}
                            />
                            <InputError message={errors.base_url} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="api_key">Clave</Label>
                            <Input
                                id="api_key"
                                type="password"
                                autoComplete="off"
                                placeholder={connection.has_key ? 'Guardada. Déjala vacía para conservarla.' : 'Pega la clave del plugin'}
                                value={data.api_key}
                                onChange={(e) => setData('api_key', e.target.value)}
                                required={!connection.has_key}
                            />
                            <InputError message={errors.api_key} />
                        </div>

                        <div className="grid max-w-xs gap-2">
                            <Label htmlFor="created_after">Traer compras desde</Label>
                            <Input
                                id="created_after"
                                type="date"
                                value={data.created_after}
                                onChange={(e) => setData('created_after', e.target.value)}
                                aria-describedby="created-after-hint"
                            />
                            <p id="created-after-hint" className="text-muted-foreground text-xs">
                                Las inscripciones anteriores vienen de tus Excel. Si cambias la fecha, se vuelve a leer todo desde el inicio.
                            </p>
                            <InputError message={errors.created_after} />
                        </div>

                        <label className="flex items-center gap-3 text-sm">
                            <Checkbox checked={data.enabled} onCheckedChange={(v) => setData('enabled', v === true)} />
                            Sincronizar automáticamente cada 10 minutos
                        </label>

                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="animate-spin" aria-hidden />}
                            Guardar
                        </Button>
                    </form>

                    <section className="space-y-3 border-t pt-6">
                        <h3 className="text-sm font-medium">Sincronización</h3>
                        <p className="text-muted-foreground text-sm">
                            {counts.registrations} pedidos en el CRM, {counts.paid} pagados.
                            {connection.last_synced_at && <> Última sincronización: {dateFormat.format(new Date(connection.last_synced_at))}.</>}
                        </p>
                        {connection.last_status === 'ok' && connection.last_result && (
                            <p className="text-success text-sm">
                                Última vez: {connection.last_result.orders} pedidos, {connection.last_result.participants} participantes,{' '}
                                {connection.last_result.new_contacts} contactos nuevos y {connection.last_result.activities} actividades.
                                {connection.last_result.has_more && ' Quedan más; se siguen trayendo.'}
                            </p>
                        )}
                        {connection.last_status === 'error' && <p className="text-destructive text-sm">Último error: {connection.last_error}</p>}
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" onClick={() => action('test')} disabled={busy !== null || !connection.has_key}>
                                {busy === 'test' && <LoaderCircle className="animate-spin" aria-hidden />}
                                Probar conexión
                            </Button>
                            <Button onClick={() => action('sync')} disabled={busy !== null || !connection.has_key}>
                                {busy === 'sync' ? <LoaderCircle className="animate-spin" aria-hidden /> : <RefreshCw aria-hidden />}
                                Sincronizar ahora
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => action('sync', { from_start: true })}
                                disabled={busy !== null || !connection.has_key}
                            >
                                Volver a traer todo
                            </Button>
                        </div>
                    </section>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
