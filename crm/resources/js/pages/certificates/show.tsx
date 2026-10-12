import Flash from '@/components/campaigns/flash';
import TestDialog from '@/components/campaigns/test-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import Stat from '@/components/stat';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dateTime, day, number } from '@/lib/format';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Clock, Eye, FileDown, RotateCw, Save, Search, Send, Trash2 } from 'lucide-react';
import { type FormEventHandler, useMemo, useState } from 'react';

interface Person {
    key: string;
    name: string;
    document: string | null;
    email: string | null;
    checked_in: boolean;
    checked_in_at: string | null;
    eligible: boolean;
    recipient: number | null;
    code: string | null;
    queued: boolean;
    sent_at: string | null;
    failed_at: string | null;
    error: string | null;
}

interface PageProps extends SharedData {
    certificate: {
        id: number;
        activity_name: string;
        activity_date: string | null;
        hours: number | null;
        require_check_in: boolean;
        sent_at: string | null;
        sent_by: string | null;
        category: { id: number; name: string; type: string };
    };
    people: Person[];
    stats: {
        seats: number;
        checked_in: number;
        eligible: number;
        without_email: number;
        pending: number;
        queued: number;
        sent: number;
        failed: number;
        per_hour: number;
    };
    mailReady: boolean;
    isPast: boolean;
}

function Status({ p }: { p: Person }) {
    if (p.sent_at)
        return (
            <span className="text-success flex items-center gap-1">
                <CircleCheck className="size-4" aria-hidden />
                Enviado {dateTime.format(new Date(p.sent_at))}
            </span>
        );
    if (p.queued)
        return (
            <span className="flex items-center gap-1">
                <Clock className="size-4" aria-hidden />
                En cola
            </span>
        );
    if (p.failed_at)
        return (
            <span className="text-destructive flex items-center gap-1" title={p.error ?? undefined}>
                <CircleAlert className="size-4" aria-hidden />
                Falló{p.error ? `: ${p.error}` : ''}
            </span>
        );
    if (!p.eligible) return <span className="text-muted-foreground">No lo recibe: sin check-in</span>;
    if (!p.email) return <span className="text-destructive">Sin correo</span>;
    return <span className="text-muted-foreground">Pendiente</span>;
}

function SendDialog({ certificate, stats, isPast, mailReady }: Pick<PageProps, 'certificate' | 'stats' | 'isPast' | 'mailReady'>) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const hours = Math.ceil(stats.pending / Math.max(1, stats.per_hour));

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" disabled={!mailReady || stats.pending === 0}>
                    <Send aria-hidden />
                    Enviar {stats.pending > 0 ? `a ${number.format(stats.pending)}` : ''}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Enviar los certificados de {certificate.activity_name}?</DialogTitle>
                    <DialogDescription>
                        Le llega el certificado en PDF a {number.format(stats.pending)} {stats.pending === 1 ? 'persona' : 'personas'},{' '}
                        {stats.per_hour} por hora
                        {hours > 1 ? ` (unas ${hours} horas en total)` : ''}. Quienes ya lo recibieron no lo reciben de nuevo.
                    </DialogDescription>
                </DialogHeader>
                {!isPast && (
                    <p className="border-warning/40 bg-warning/10 rounded-lg border p-3 text-sm">
                        Esta actividad aún no ha ocurrido ({day(certificate.activity_date)}). Quien no haya hecho check-in todavía no lo recibirá.
                    </p>
                )}
                {stats.without_email > 0 && (
                    <p className="text-muted-foreground text-sm">
                        {number.format(stats.without_email)} {stats.without_email === 1 ? 'persona no tiene' : 'personas no tienen'} correo; descarga
                        su PDF desde la lista.
                    </p>
                )}
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        disabled={processing}
                        onClick={() =>
                            router.post(
                                `/certificados/${certificate.id}/enviar`,
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => {
                                        setProcessing(false);
                                        setOpen(false);
                                    },
                                },
                            )
                        }
                    >
                        <Send aria-hidden />
                        Sí, enviar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function CertificateShow() {
    const { certificate, people, stats, mailReady, isPast, auth } = usePage<PageProps>().props;
    const [q, setQ] = useState('');
    const anySent = stats.sent > 0;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Certificados', href: '/certificados' },
        { title: certificate.activity_name, href: `/certificados/${certificate.id}` },
    ];

    const { data, setData, put, processing, errors, isDirty } = useForm({
        hours: certificate.hours === null ? '' : String(certificate.hours),
        require_check_in: certificate.require_check_in,
    });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/certificados/${certificate.id}`, { preserveScroll: true });
    };

    const shown = useMemo(() => {
        const term = q.trim().toLowerCase();
        if (!term) return people;
        return people.filter((p) => [p.name, p.document, p.email, p.code].some((v) => v?.toLowerCase().includes(term)));
    }, [people, q]);

    const destroy = () => {
        if (window.confirm(`¿Quitar el certificado de ${certificate.activity_name}?`)) router.delete(`/certificados/${certificate.id}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Certificado · ${certificate.activity_name}`} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={certificate.activity_name}
                        description={`${day(certificate.activity_date)} · Diseño ${certificate.category.name}${
                            certificate.sent_at
                                ? ` · Último envío ${dateTime.format(new Date(certificate.sent_at))}${certificate.sent_by ? ` por ${certificate.sent_by}` : ''}`
                                : ''
                        }`}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a href={`/certificados/${certificate.id}/vista-previa`} target="_blank" rel="noopener">
                                <Eye aria-hidden />
                                Vista previa
                            </a>
                        </Button>
                        <TestDialog
                            description="Llega solo a este correo, con este diseño y esta actividad, a nombre de una persona de ejemplo."
                            defaultEmail={auth.user.email}
                            disabled={!mailReady}
                            onSend={(email) => router.post(`/certificados/${certificate.id}/prueba`, { email }, { preserveScroll: true })}
                        />
                        <SendDialog certificate={certificate} stats={stats} isPast={isPast} mailReady={mailReady} />
                    </div>
                </div>

                <Flash />

                {!mailReady && (
                    <p className="border-warning/40 bg-warning/10 rounded-lg border p-3 text-sm">
                        Para enviar, primero configura y verifica el correo transaccional en{' '}
                        <Link href="/settings/mail" className="underline">
                            Configuración › Correo de envíos
                        </Link>
                        . Mientras tanto puedes descargar cada PDF.
                    </p>
                )}

                <dl className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Stat label="Compraron la actividad" value={stats.seats} />
                    <Stat label="Hicieron check-in" value={stats.checked_in} />
                    <Stat label="Lo reciben" value={stats.eligible} />
                    <Stat
                        label="Enviados"
                        value={stats.sent}
                        display={`${number.format(stats.sent)}${stats.queued ? ` · ${number.format(stats.queued)} en cola` : ''}`}
                    />
                </dl>

                <form onSubmit={save} className="flex flex-wrap items-end gap-4 rounded-xl border p-4">
                    <div className="grid gap-2">
                        <Label htmlFor="hours">Horas</Label>
                        <Input
                            id="hours"
                            type="number"
                            min="0.5"
                            step="0.5"
                            inputMode="decimal"
                            className="w-28"
                            value={data.hours}
                            onChange={(e) => setData('hours', e.target.value)}
                        />
                        <InputError message={errors.hours} />
                    </div>
                    <label className="flex items-center gap-3 pb-2 text-sm">
                        <Checkbox checked={data.require_check_in} onCheckedChange={(v) => setData('require_check_in', v === true)} />
                        Solo para quienes hicieron check-in
                    </label>
                    <Button type="submit" variant="outline" disabled={processing || !isDirty}>
                        <Save aria-hidden />
                        Guardar
                    </Button>
                    {!anySent && (
                        <Button type="button" variant="ghost" className="ml-auto" onClick={destroy}>
                            <Trash2 aria-hidden />
                            Quitar certificado
                        </Button>
                    )}
                </form>

                <section className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="font-medium">Personas ({number.format(people.length)})</h2>
                        <div className="relative w-full sm:w-72">
                            <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden />
                            <Input
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Buscar nombre, documento o código"
                                className="pl-9"
                                aria-label="Buscar"
                            />
                        </div>
                    </div>

                    {people.length === 0 ? (
                        <p className="text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">
                            Nadie ha pagado esta actividad todavía.
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/40 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Persona</th>
                                        <th className="px-3 py-2 font-medium">Correo</th>
                                        <th className="px-3 py-2 font-medium">Check-in</th>
                                        <th className="px-3 py-2 font-medium">Estado</th>
                                        <th className="px-3 py-2 font-medium">
                                            <span className="sr-only">Acciones</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {shown.map((p) => (
                                        <tr key={p.key} className={p.eligible || p.sent_at ? '' : 'text-muted-foreground'}>
                                            <td className="px-3 py-2">
                                                <span className="font-medium">{p.name}</span>
                                                <span className="text-muted-foreground block text-xs">
                                                    {[p.document, p.code].filter(Boolean).join(' · ') || '—'}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2 break-all">{p.email ?? '—'}</td>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {p.checked_in_at ? dateTime.format(new Date(p.checked_in_at)) : 'No'}
                                            </td>
                                            <td className="px-3 py-2">
                                                <Status p={p} />
                                            </td>
                                            <td className="px-3 py-2">
                                                {p.recipient && (
                                                    <div className="flex justify-end gap-1">
                                                        <Button variant="ghost" size="sm" asChild>
                                                            <a href={`/certificados/persona/${p.recipient}/pdf`} title="Descargar PDF">
                                                                <FileDown className="size-4" aria-hidden />
                                                                <span className="sr-only">Descargar PDF de {p.name}</span>
                                                            </a>
                                                        </Button>
                                                        {(p.sent_at || p.failed_at) && p.email && mailReady && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                title="Reenviar"
                                                                onClick={() => {
                                                                    if (window.confirm(`¿Reenviar el certificado a ${p.email}?`)) {
                                                                        router.post(
                                                                            `/certificados/persona/${p.recipient}/reenviar`,
                                                                            {},
                                                                            { preserveScroll: true },
                                                                        );
                                                                    }
                                                                }}
                                                            >
                                                                <RotateCw className="size-4" aria-hidden />
                                                                <span className="sr-only">Reenviar a {p.name}</span>
                                                            </Button>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
