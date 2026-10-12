import Heading from '@/components/heading';
import Stat from '@/components/stat';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { ACTIVITY_STATUS, dateTime, day, money, number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Search } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface ParticipantRow {
    id: number;
    contact_id: number | null;
    name: string;
    email: string | null;
    document: string | null;
    shirt_size: string | null;
    meal_type: string | null;
    order: { number: string; status: string; ordered_at: string | null; city: string | null };
    activities: { name: string | null; type: string | null; date: string | null; status: string }[];
}

interface PageProps {
    participants: {
        data: ParticipantRow[];
        total: number;
        from: number | null;
        to: number | null;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { q?: string; estado?: string; actividad?: number; tipo?: string };
    stats: { paid_orders: number; participants: number; revenue: number; pending_orders: number };
    activities: { evr_id: number; name: string; type: string; event_date: string | null }[];
    types: Record<string, string>;
    statusLabels: Record<string, string>;
    lastSync: string | null;
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inscripciones', href: '/registrations' }];
const selectClass =
    'border-input bg-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

const PAID = ['processing', 'completed'];

export default function RegistrationsIndex() {
    const { participants, filters, stats, activities, types, statusLabels, lastSync } = usePage<PageProps>().props;
    const [q, setQ] = useState(filters.q ?? '');

    const apply = (next: Partial<PageProps['filters']>) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== undefined && v !== ''));
        router.get('/registrations', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        apply({ q });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inscripciones" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Inscripciones"
                    description={
                        lastSync
                            ? `Traídas del plugin de inscripciones. Última sincronización: ${dateTime.format(new Date(lastSync))}.`
                            : 'Aún no se ha sincronizado el plugin. Configúralo en Configuración › Plugin de inscripciones.'
                    }
                />

                <dl className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Stat label="Pedidos pagados" value={stats.paid_orders} />
                    <Stat label="Participantes pagados" value={stats.participants} />
                    <Stat label="Recaudo" value={stats.revenue} display={money.format(stats.revenue)} />
                    <Stat label="Pedidos pendientes de pago" value={stats.pending_orders} />
                </dl>

                <div className="grid gap-3 md:grid-cols-[1fr_11rem_12rem_16rem]">
                    <form onSubmit={submitSearch} role="search" className="relative">
                        <Label htmlFor="q" className="sr-only">
                            Buscar
                        </Label>
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden />
                        <Input
                            id="q"
                            type="search"
                            className="pl-9"
                            placeholder="Nombre, correo, documento o pedido"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                        />
                    </form>
                    <label>
                        <span className="sr-only">Estado del pedido</span>
                        <select className={selectClass} value={filters.estado ?? ''} onChange={(e) => apply({ estado: e.target.value })}>
                            <option value="">Todos los pedidos</option>
                            <option value="pagadas">Pagados</option>
                            <option value="pendientes">Pendientes de pago</option>
                            <option value="canceladas">Cancelados</option>
                        </select>
                    </label>
                    <label>
                        <span className="sr-only">Tipo de actividad</span>
                        <select
                            className={selectClass}
                            value={filters.tipo ?? ''}
                            onChange={(e) => apply({ tipo: e.target.value, actividad: undefined })}
                        >
                            <option value="">Todos los tipos</option>
                            {Object.entries(types).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="sr-only">Actividad</span>
                        <select
                            className={selectClass}
                            value={filters.actividad ? String(filters.actividad) : ''}
                            onChange={(e) => apply({ actividad: e.target.value ? Number(e.target.value) : undefined })}
                        >
                            <option value="">Todas las actividades</option>
                            {activities
                                .filter((a) => !filters.tipo || a.type === filters.tipo)
                                .map((a) => (
                                    <option key={a.evr_id} value={a.evr_id}>
                                        {a.name}
                                        {a.event_date ? ` · ${day(a.event_date)}` : ''}
                                    </option>
                                ))}
                        </select>
                    </label>
                </div>

                {participants.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                        No hay inscripciones con estos filtros.
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[820px] text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                <tr>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Participante
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Pedido
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Actividades
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Camisa / comida
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {participants.data.map((p) => (
                                    <tr key={p.id} className="align-top">
                                        <td className="px-4 py-3">
                                            {p.contact_id ? (
                                                <Link href={`/contacts/${p.contact_id}`} className="font-medium underline-offset-4 hover:underline">
                                                    {p.name}
                                                </Link>
                                            ) : (
                                                <span className="font-medium">{p.name}</span>
                                            )}
                                            <div className="text-muted-foreground">{[p.email, p.document].filter(Boolean).join(' · ')}</div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="tabular-nums">#{p.order.number}</div>
                                            <div className={PAID.includes(p.order.status) ? 'text-success' : 'text-warning'}>
                                                {statusLabels[p.order.status] ?? p.order.status}
                                            </div>
                                            {p.order.ordered_at && (
                                                <div className="text-muted-foreground text-xs">{dateTime.format(new Date(p.order.ordered_at))}</div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <ul className="space-y-1">
                                                {p.activities.map((a, i) => (
                                                    <li
                                                        key={i}
                                                        className={
                                                            ['cancelled', 'removed', 'released'].includes(a.status)
                                                                ? 'text-muted-foreground line-through'
                                                                : ''
                                                        }
                                                    >
                                                        <span className="text-muted-foreground tabular-nums">{day(a.date)}</span>{' '}
                                                        {a.name ?? 'Actividad eliminada'}
                                                        {a.status !== 'confirmed' && (
                                                            <span className="text-muted-foreground text-xs">
                                                                {' '}
                                                                · {ACTIVITY_STATUS[a.status] ?? a.status}
                                                            </span>
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3">
                                            {[p.shirt_size, p.meal_type].filter(Boolean).join(' · ') || '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {participants.last_page > 1 && (
                    <nav className="flex items-center justify-between gap-4 text-sm" aria-label="Paginación">
                        <span className="text-muted-foreground">
                            {number.format(participants.from ?? 0)}–{number.format(participants.to ?? 0)} de {number.format(participants.total)}
                        </span>
                        <div className="flex gap-2">
                            {[
                                [participants.prev_page_url, 'Anterior', ChevronLeft] as const,
                                [participants.next_page_url, 'Siguiente', ChevronRight] as const,
                            ].map(([href, label, Icon]) =>
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
            </div>
        </AppLayout>
    );
}
