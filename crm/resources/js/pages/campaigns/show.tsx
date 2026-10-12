import Flash from '@/components/campaigns/flash';
import { type CampaignStats, type CampaignStatus, percent, StatusBadge } from '@/components/campaigns/types';
import Heading from '@/components/heading';
import Stat from '@/components/stat';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { dateTime, number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Copy, Pause, Play, RotateCcw, Search, XCircle } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

type RecipientStatus = 'pending' | 'queued' | 'sent' | 'failed' | 'skipped';

interface Recipient {
    id: number;
    contact_id: number | null;
    name: string | null;
    email: string;
    status: RecipientStatus;
    error: string | null;
    sent_at: string | null;
    opened_at: string | null;
    clicked_at: string | null;
    click_count: number;
}

interface PageProps {
    campaign: {
        id: number;
        name: string;
        subject: string;
        status: CampaignStatus;
        segment: string[];
        scheduled_at: string | null;
        started_at: string | null;
        finished_at: string | null;
        creator: string | null;
    };
    stats: Required<CampaignStats>;
    links: { url: string; clicks: number; people: number }[];
    recipients: {
        data: Recipient[];
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { q?: string; estado?: string };
    pace: { hourly: number; daily: number | null };
    tracking: { opens: boolean; clicks: boolean };
    canEdit: boolean;
    [key: string]: unknown;
}

const RECIPIENT_STATUS: Record<RecipientStatus, { label: string; className: string }> = {
    pending: { label: 'En espera', className: 'text-muted-foreground' },
    queued: { label: 'En cola', className: 'text-muted-foreground' },
    sent: { label: 'Enviado', className: 'text-success' },
    failed: { label: 'Falló', className: 'text-destructive' },
    skipped: { label: 'Omitido', className: 'text-warning' },
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

export default function CampaignShow() {
    const { campaign, stats, links, recipients, filters, pace, tracking, canEdit } = usePage<PageProps>().props;
    const [q, setQ] = useState(filters.q ?? '');
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Campañas', href: '/campaigns' },
        { title: campaign.name, href: `/campaigns/${campaign.id}` },
    ];

    // While it sends, refresh the numbers every 30 seconds.
    useEffect(() => {
        if (campaign.status !== 'sending') return;
        const timer = setInterval(() => router.reload({ only: ['campaign', 'stats', 'recipients', 'links'] }), 30000);
        return () => clearInterval(timer);
    }, [campaign.status]);

    const apply = (next: Partial<PageProps['filters']>) => {
        const query = Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v));
        router.get(`/campaigns/${campaign.id}`, query, { preserveState: true, preserveScroll: true, replace: true });
    };
    const search: FormEventHandler = (e) => {
        e.preventDefault();
        apply({ q });
    };
    const action = (name: string, message?: string) => {
        if (message && !confirm(message)) return;
        router.post(`/campaigns/${campaign.id}/${name}`, {}, { preserveScroll: true });
    };

    const done = stats.sent + stats.failed + stats.skipped;
    const progress = stats.recipients ? Math.round((done / stats.recipients) * 100) : 0;
    const hoursLeft = Math.ceil(stats.pending / pace.hourly);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={campaign.name} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-start gap-3">
                        <Heading title={campaign.name} description={campaign.subject} />
                        <StatusBadge status={campaign.status} />
                    </div>
                    {canEdit && (
                        <div className="flex flex-wrap gap-2">
                            {campaign.status === 'sending' && (
                                <Button variant="outline" onClick={() => action('pause')}>
                                    <Pause aria-hidden />
                                    Pausar
                                </Button>
                            )}
                            {campaign.status === 'paused' && (
                                <Button variant="outline" onClick={() => action('resume')}>
                                    <Play aria-hidden />
                                    Reanudar
                                </Button>
                            )}
                            {stats.failed > 0 && ['sending', 'paused', 'sent'].includes(campaign.status) && (
                                <Button variant="outline" onClick={() => action('retry')}>
                                    <RotateCcw aria-hidden />
                                    Reintentar fallidos
                                </Button>
                            )}
                            {['sending', 'paused'].includes(campaign.status) && (
                                <Button
                                    variant="outline"
                                    className="text-destructive"
                                    onClick={() => action('cancel', '¿Cancelar la campaña? Los correos que faltan no saldrán.')}
                                >
                                    <XCircle aria-hidden />
                                    Cancelar
                                </Button>
                            )}
                            {campaign.status === 'scheduled' && (
                                <Button variant="outline" asChild>
                                    <Link href={`/campaigns/${campaign.id}/edit`}>Editar</Link>
                                </Button>
                            )}
                            <Button variant="outline" onClick={() => action('duplicate')}>
                                <Copy aria-hidden />
                                Duplicar
                            </Button>
                        </div>
                    )}
                </div>

                <Flash />

                <div className="text-muted-foreground flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <span>Público: {campaign.segment.join(' · ')}</span>
                    {campaign.scheduled_at && campaign.status === 'scheduled' && (
                        <span>Sale el {dateTime.format(new Date(campaign.scheduled_at))}</span>
                    )}
                    {campaign.started_at && <span>Empezó: {dateTime.format(new Date(campaign.started_at))}</span>}
                    {campaign.finished_at && <span>Terminó: {dateTime.format(new Date(campaign.finished_at))}</span>}
                    {campaign.creator && <span>Creada por {campaign.creator}</span>}
                </div>

                {stats.recipients > 0 && (
                    <div className="grid gap-2">
                        <div className="flex justify-between text-sm">
                            <span>
                                {number.format(done)} de {number.format(stats.recipients)} procesados
                            </span>
                            {stats.pending > 0 && campaign.status === 'sending' && (
                                <span className="text-muted-foreground">
                                    Faltan {number.format(stats.pending)} · unas {hoursLeft} {hoursLeft === 1 ? 'hora' : 'horas'} a{' '}
                                    {number.format(pace.hourly)} por hora
                                </span>
                            )}
                        </div>
                        <div
                            className="bg-muted h-2 overflow-hidden rounded-full"
                            role="progressbar"
                            aria-valuenow={progress}
                            aria-valuemin={0}
                            aria-valuemax={100}
                        >
                            <div className="bg-primary h-full transition-[width]" style={{ width: `${progress}%` }} />
                        </div>
                    </div>
                )}

                <dl className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <Stat label="Destinatarios" value={stats.recipients} />
                    <Stat label="Enviados" value={stats.sent} />
                    <Stat
                        label="Aperturas"
                        value={stats.opened}
                        display={tracking.opens || stats.opened ? `${percent(stats.opened, stats.sent)}` : 'Sin medir'}
                    />
                    <Stat
                        label="Clics"
                        value={stats.clicked}
                        display={tracking.clicks || stats.clicked ? `${percent(stats.clicked, stats.sent)}` : 'Sin medir'}
                    />
                    <Stat label="Bajas" value={stats.unsubscribed} />
                    <Stat label="Fallidos u omitidos" value={stats.failed + stats.skipped} />
                </dl>
                <p className="text-muted-foreground -mt-3 text-xs">
                    Aperturas y clics son personas únicas sobre los enviados. Las aperturas son aproximadas: Apple Mail las marca solas y otros
                    programas bloquean las imágenes.
                </p>

                {links.length > 0 && (
                    <section aria-labelledby="links-title" className="grid gap-2">
                        <h2 id="links-title" className="text-lg font-semibold">
                            Enlaces más visitados
                        </h2>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">Enlace</th>
                                        <th className="px-4 py-2 text-right font-medium">Personas</th>
                                        <th className="px-4 py-2 text-right font-medium">Clics</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {links.map((l) => (
                                        <tr key={l.url}>
                                            <td className="max-w-md truncate px-4 py-2">
                                                <a href={l.url} target="_blank" rel="noreferrer" className="underline-offset-4 hover:underline">
                                                    {l.url}
                                                </a>
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">{number.format(l.people)}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{number.format(l.clicks)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                <section aria-labelledby="recipients-title" className="grid gap-3">
                    <h2 id="recipients-title" className="text-lg font-semibold">
                        Destinatarios
                    </h2>
                    <div className="grid gap-3 md:grid-cols-[1fr_14rem]">
                        <form onSubmit={search} role="search" className="relative">
                            <Search
                                className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                                aria-hidden
                            />
                            <Input
                                type="search"
                                className="pl-9"
                                placeholder="Buscar por correo"
                                aria-label="Buscar por correo"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                            />
                        </form>
                        <select
                            aria-label="Filtrar"
                            className={selectClass}
                            value={filters.estado ?? ''}
                            onChange={(e) => apply({ estado: e.target.value })}
                        >
                            <option value="">Todos</option>
                            <option value="sent">Enviados</option>
                            <option value="opened">Abrieron</option>
                            <option value="clicked">Hicieron clic</option>
                            <option value="unsubscribed">Se dieron de baja</option>
                            <option value="pending">En espera</option>
                            <option value="failed">Fallaron</option>
                            <option value="skipped">Omitidos</option>
                        </select>
                    </div>

                    {recipients.data.length === 0 ? (
                        <div className="text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">
                            {campaign.status === 'scheduled' ? 'La lista se arma cuando empiece el envío.' : 'Nadie coincide con el filtro.'}
                        </div>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[760px] text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        {['Contacto', 'Estado', 'Enviado', 'Abrió', 'Clics'].map((h) => (
                                            <th key={h} scope="col" className="px-4 py-2 font-medium">
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {recipients.data.map((r) => (
                                        <tr key={r.id} className="align-top">
                                            <td className="px-4 py-2">
                                                {r.contact_id ? (
                                                    <Link
                                                        href={`/contacts/${r.contact_id}`}
                                                        className="font-medium underline-offset-4 hover:underline"
                                                    >
                                                        {r.name ?? r.email}
                                                    </Link>
                                                ) : (
                                                    <span className="font-medium">{r.name ?? r.email}</span>
                                                )}
                                                {r.name && <div className="text-muted-foreground text-xs break-all">{r.email}</div>}
                                            </td>
                                            <td className={`px-4 py-2 ${RECIPIENT_STATUS[r.status].className}`}>
                                                {RECIPIENT_STATUS[r.status].label}
                                                {r.error && <div className="text-muted-foreground max-w-xs text-xs break-words">{r.error}</div>}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2 text-xs whitespace-nowrap">
                                                {r.sent_at ? dateTime.format(new Date(r.sent_at)) : '—'}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2 text-xs whitespace-nowrap">
                                                {r.opened_at ? dateTime.format(new Date(r.opened_at)) : '—'}
                                            </td>
                                            <td className="px-4 py-2 tabular-nums">{r.click_count || '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {(recipients.prev_page_url || recipients.next_page_url) && (
                        <nav className="flex items-center justify-between gap-4 text-sm" aria-label="Paginación">
                            <span className="text-muted-foreground">
                                {number.format(recipients.from ?? 0)}–{number.format(recipients.to ?? 0)} de {number.format(recipients.total)}
                            </span>
                            <div className="flex gap-2">
                                {[
                                    { href: recipients.prev_page_url, label: 'Anterior', icon: ChevronLeft },
                                    { href: recipients.next_page_url, label: 'Siguiente', icon: ChevronRight },
                                ].map(({ href, label, icon: Icon }) =>
                                    href ? (
                                        <Button key={label} variant="outline" size="icon" asChild>
                                            <Link href={href} preserveScroll aria-label={label}>
                                                <Icon aria-hidden />
                                            </Link>
                                        </Button>
                                    ) : (
                                        <Button key={label} variant="outline" size="icon" disabled aria-label={label}>
                                            <Icon aria-hidden />
                                        </Button>
                                    ),
                                )}
                            </div>
                        </nav>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
