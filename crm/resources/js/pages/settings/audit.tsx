import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dateTime, number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Download, Search, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Entry {
    id: number;
    at: string;
    user: string;
    user_id: number | null;
    action: string;
    label: string;
    module: string;
    module_key: string | null;
    subject: string | null;
    changes: Record<string, unknown> | null;
    ip: string | null;
    user_agent: string | null;
}

interface Paginated<T> {
    data: T[];
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface Filters {
    user?: string;
    module?: string;
    action?: string;
    from?: string;
    to?: string;
    q?: string;
}

interface PageProps {
    entries: Paginated<Entry>;
    filters: Filters;
    users: { id: number; name: string; active: boolean }[];
    modules: Record<string, string>;
    actions: { value: string; module: string; label: string }[];
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Configuración', href: '/settings/profile' },
    { title: 'Auditoría', href: '/settings/audit' },
];

const selectClass =
    'border-input bg-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

/** Colour of the module chip, so check-ins, campaigns and sign-ins are easy to tell apart when scanning. */
const MODULE_TONE: Record<string, string> = {
    session: 'bg-muted text-muted-foreground',
    user: 'bg-primary/10 text-primary',
    checkin: 'bg-success/10 text-success',
    participant: 'bg-warning/10 text-warning',
    campaign: 'bg-primary/10 text-primary',
    finance: 'bg-warning/10 text-warning',
};

const DANGER = new Set(['auth.failed', 'auth.lockout', 'auth.blocked', 'user.deactivated', 'user.deleted', 'checkin.undone', 'expense.deleted']);

export default function Audit() {
    const { entries, filters, users, modules, actions } = usePage<PageProps>().props;
    const [q, setQ] = useState(filters.q ?? '');

    const apply = (next: Filters) => {
        const merged: Filters = { ...filters, ...next };
        if (next.module !== undefined && merged.action && !actions.some((a) => a.value === merged.action && a.module === next.module)) {
            merged.action = '';
        }
        const query = Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== undefined && v !== ''));
        router.get('/settings/audit', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        apply({ q });
    };

    const active = Object.values(filters).some((v) => v);
    const exportUrl = `/settings/audit/export?${new URLSearchParams(filters as Record<string, string>).toString()}`;
    const visibleActions = filters.module ? actions.filter((a) => a.module === filters.module) : actions;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Auditoría" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Auditoría"
                        description="Todo lo que hace cada usuario en el CRM: accesos, check-in, cambios de participantes, campañas, costos y configuración."
                    />
                    <Button variant="outline" asChild>
                        <a href={exportUrl}>
                            <Download aria-hidden />
                            Descargar CSV
                        </a>
                    </Button>
                </div>

                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-[1fr_12rem_11rem_14rem_9.5rem_9.5rem]">
                    <form onSubmit={submitSearch} role="search" className="relative md:col-span-2 xl:col-span-1">
                        <Label htmlFor="q" className="sr-only">
                            Buscar
                        </Label>
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden />
                        <Input
                            id="q"
                            type="search"
                            className="pl-9"
                            placeholder="Persona, documento, campaña, IP…"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                        />
                    </form>
                    <FilterSelect
                        label="Usuario"
                        value={filters.user ?? ''}
                        onChange={(v) => apply({ user: v })}
                        options={[
                            ['', 'Todos los usuarios'],
                            ...users.map((u): [string, string] => [String(u.id), u.active ? u.name : `${u.name} (inactivo)`]),
                        ]}
                    />
                    <FilterSelect
                        label="Módulo"
                        value={filters.module ?? ''}
                        onChange={(v) => apply({ module: v })}
                        options={[['', 'Todos los módulos'], ...Object.entries(modules)]}
                    />
                    <FilterSelect
                        label="Acción"
                        value={filters.action ?? ''}
                        onChange={(v) => apply({ action: v })}
                        options={[['', 'Todas las acciones'], ...visibleActions.map((a): [string, string] => [a.value, a.label])]}
                    />
                    <DateFilter label="Desde" value={filters.from ?? ''} onChange={(v) => apply({ from: v })} />
                    <DateFilter label="Hasta" value={filters.to ?? ''} onChange={(v) => apply({ to: v })} />
                </div>

                {active && (
                    <div>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setQ('');
                                router.get('/settings/audit', {}, { preserveScroll: true, replace: true });
                            }}
                        >
                            <X aria-hidden />
                            Quitar filtros
                        </Button>
                    </div>
                )}

                {entries.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                        {active ? 'Ningún registro coincide con los filtros.' : 'Aún no hay actividad registrada.'}
                    </div>
                ) : (
                    <ol className="divide-y rounded-xl border">
                        {entries.data.map((entry) => (
                            <EntryRow key={entry.id} entry={entry} onUser={(id) => apply({ user: String(id) })} />
                        ))}
                    </ol>
                )}

                {entries.last_page > 1 && (
                    <nav className="flex items-center justify-between gap-4 text-sm" aria-label="Paginación">
                        <span className="text-muted-foreground">
                            {number.format(entries.from ?? 0)}–{number.format(entries.to ?? 0)} de {number.format(entries.total)}
                        </span>
                        <div className="flex gap-2">
                            <PageLink href={entries.prev_page_url} label="Anterior">
                                <ChevronLeft aria-hidden />
                            </PageLink>
                            <PageLink href={entries.next_page_url} label="Siguiente">
                                <ChevronRight aria-hidden />
                            </PageLink>
                        </div>
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}

function EntryRow({ entry, onUser }: { entry: Entry; onUser: (id: number) => void }) {
    const details = entry.changes ? Object.entries(entry.changes) : [];

    return (
        <li className="grid gap-x-4 gap-y-1 px-4 py-3 text-sm md:grid-cols-[10.5rem_1fr]">
            <time dateTime={entry.at} className="text-muted-foreground text-xs tabular-nums md:pt-0.5">
                {dateTime.format(new Date(entry.at))}
            </time>
            <div className="min-w-0 space-y-1">
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span
                        className={`rounded-full px-2 py-0.5 text-xs font-medium ${MODULE_TONE[entry.module_key ?? ''] ?? 'bg-secondary text-secondary-foreground'}`}
                    >
                        {entry.module}
                    </span>
                    {entry.user_id ? (
                        <button type="button" className="font-medium underline-offset-4 hover:underline" onClick={() => onUser(entry.user_id!)}>
                            {entry.user}
                        </button>
                    ) : (
                        <span className="font-medium">{entry.user}</span>
                    )}
                    <span className={DANGER.has(entry.action) ? 'text-destructive' : undefined}>{entry.label.toLowerCase()}</span>
                    {entry.subject && <span className="text-muted-foreground min-w-0 break-words">· {entry.subject}</span>}
                </p>
                {details.length > 0 && (
                    <dl className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-0.5 text-xs">
                        {details.map(([key, value]) => (
                            <div key={key} className="flex min-w-0 gap-1">
                                <dt className="shrink-0">{humanize(key)}:</dt>
                                <dd className="text-foreground min-w-0 break-words">{show(value)}</dd>
                            </div>
                        ))}
                    </dl>
                )}
                {(entry.ip || entry.user_agent) && (
                    <p className="text-muted-foreground text-xs" title={entry.user_agent ?? undefined}>
                        {[entry.ip, browser(entry.user_agent)].filter(Boolean).join(' · ')}
                    </p>
                )}
            </div>
        </li>
    );
}

function humanize(key: string): string {
    const text = key.replace(/[_-]/g, ' ');
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function show(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (value === true) return 'sí';
    if (value === false) return 'no';
    if (Array.isArray(value)) return value.map(show).join(', ');
    if (typeof value === 'object') {
        const v = value as Record<string, unknown>;
        if ('antes' in v && 'despues' in v) return `${show(v.antes)} → ${show(v.despues)}`;
        return Object.entries(v)
            .map(([k, x]) => `${humanize(k)}: ${show(x)}`)
            .join('; ');
    }
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T/.test(value)) return dateTime.format(new Date(value));
    return String(value);
}

/** Short device name from the user agent, enough to tell a phone at the desk from an office laptop. */
function browser(agent: string | null): string | null {
    if (!agent) return null;
    const os = /Android/.test(agent)
        ? 'Android'
        : /iPhone|iPad/.test(agent)
          ? 'iOS'
          : /Windows/.test(agent)
            ? 'Windows'
            : /Mac OS/.test(agent)
              ? 'Mac'
              : /Linux/.test(agent)
                ? 'Linux'
                : null;
    const name = /Edg\//.test(agent)
        ? 'Edge'
        : /OPR\//.test(agent)
          ? 'Opera'
          : /Chrome\//.test(agent)
            ? 'Chrome'
            : /Firefox\//.test(agent)
              ? 'Firefox'
              : /Safari\//.test(agent)
                ? 'Safari'
                : null;
    return [name, os].filter(Boolean).join(' en ') || null;
}

function FilterSelect({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: [string, string][];
}) {
    return (
        <label>
            <span className="sr-only">{label}</span>
            <select className={selectClass} value={value} onChange={(e) => onChange(e.target.value)}>
                {options.map(([v, text]) => (
                    <option key={v} value={v}>
                        {text}
                    </option>
                ))}
            </select>
        </label>
    );
}

function DateFilter({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
    return (
        <label className="relative">
            <span className="text-muted-foreground bg-background pointer-events-none absolute -top-2 left-2 px-1 text-[0.7rem]">{label}</span>
            <Input type="date" value={value} onChange={(e) => onChange(e.target.value)} aria-label={label} />
        </label>
    );
}

function PageLink({ href, label, children }: { href: string | null; label: string; children: React.ReactNode }) {
    if (!href) {
        return (
            <Button variant="outline" size="icon" disabled aria-label={label}>
                {children}
            </Button>
        );
    }
    return (
        <Button variant="outline" size="icon" asChild>
            <Link href={href} preserveScroll aria-label={label}>
                {children}
            </Link>
        </Button>
    );
}
