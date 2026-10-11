import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Code, Copy, Search, Upload } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Status = 'acepta' | 'baja' | 'pendiente' | 'sin_consentimiento';

interface ContactRow {
    id: number;
    name: string | null;
    email: string | null;
    phone: string | null;
    location: string | null;
    organization: string | null;
    is_attendee: boolean;
    is_interested: boolean;
    is_subscriber: boolean;
    status: Status;
    years: number[];
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface Filters {
    q?: string;
    tipo?: string;
    estado?: string;
    anio?: number;
}

interface PageProps {
    contacts: Paginated<ContactRow>;
    filters: Filters;
    stats: { total: number; attendees: number; interested: number; subscribers: number; mailable: number };
    years: number[];
    formEndpoint: string;
    canImport: boolean;
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Contactos', href: '/contacts' }];

const selectClass =
    'border-input bg-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

const STATUS: Record<Status, { label: string; className: string }> = {
    acepta: { label: 'Acepta difusiones', className: 'text-success' },
    pendiente: { label: 'Confirmación pendiente', className: 'text-warning' },
    sin_consentimiento: { label: 'Sin autorización', className: 'text-muted-foreground' },
    baja: { label: 'Dado de baja', className: 'text-destructive' },
};

const number = new Intl.NumberFormat('es-CO');

export default function ContactsIndex() {
    const { contacts, filters, stats, years, formEndpoint, canImport } = usePage<PageProps>().props;
    const [q, setQ] = useState(filters.q ?? '');

    const apply = (next: Filters) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== undefined && v !== ''));
        router.get('/contacts', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        apply({ q });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Contactos" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Contactos" description="Asistentes de ferias anteriores, interesados y suscriptores del sitio web." />
                    <div className="flex flex-wrap gap-2">
                        <FormSnippetDialog endpoint={formEndpoint} />
                        {canImport && (
                            <Button asChild>
                                <Link href="/contacts/import">
                                    <Upload aria-hidden />
                                    Importar Excel
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <dl className="grid grid-cols-2 gap-3 md:grid-cols-5">
                    <Stat label="Total" value={stats.total} />
                    <Stat label="Asistentes" value={stats.attendees} />
                    <Stat label="Interesados" value={stats.interested} />
                    <Stat label="Suscriptores" value={stats.subscribers} />
                    <Stat label="Aceptan difusiones" value={stats.mailable} />
                </dl>

                <div className="grid gap-3 md:grid-cols-[1fr_11rem_13rem_10rem]">
                    <form onSubmit={submitSearch} role="search" className="relative">
                        <Label htmlFor="q" className="sr-only">
                            Buscar
                        </Label>
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden />
                        <Input
                            id="q"
                            type="search"
                            className="pl-9"
                            placeholder="Nombre, correo, teléfono, documento u organización"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                        />
                    </form>
                    <FilterSelect
                        label="Tipo"
                        value={filters.tipo ?? ''}
                        onChange={(v) => apply({ tipo: v })}
                        options={[
                            ['', 'Todos los tipos'],
                            ['asistentes', 'Asistentes'],
                            ['interesados', 'Interesados'],
                            ['suscriptores', 'Suscriptores'],
                        ]}
                    />
                    <FilterSelect
                        label="Autorización"
                        value={filters.estado ?? ''}
                        onChange={(v) => apply({ estado: v })}
                        options={[
                            ['', 'Cualquier autorización'],
                            ['acepta', STATUS.acepta.label],
                            ['pendiente', STATUS.pendiente.label],
                            ['sin_consentimiento', STATUS.sin_consentimiento.label],
                            ['baja', STATUS.baja.label],
                        ]}
                    />
                    <FilterSelect
                        label="Año"
                        value={filters.anio ? String(filters.anio) : ''}
                        onChange={(v) => apply({ anio: v ? Number(v) : undefined })}
                        options={[['', 'Todos los años'], ...years.map((y): [string, string] => [String(y), String(y)])]}
                    />
                </div>

                {contacts.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                        {stats.total === 0
                            ? 'Aún no hay contactos. Importa tu Excel de asistentes o conecta el formulario del sitio web.'
                            : 'Ningún contacto coincide con los filtros.'}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[720px] text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                <tr>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Contacto
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Tipo
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Ubicación
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Ferias
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Autorización
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {contacts.data.map((c) => (
                                    <tr key={c.id} className="align-top">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{c.name ?? c.email ?? c.phone ?? 'Sin nombre'}</div>
                                            <div className="text-muted-foreground">{[c.email, c.phone].filter(Boolean).join(' · ')}</div>
                                            {c.organization && <div className="text-muted-foreground text-xs">{c.organization}</div>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap gap-1">
                                                {c.is_attendee && <Tag>Asistente</Tag>}
                                                {c.is_interested && <Tag>Interesado</Tag>}
                                                {c.is_subscriber && <Tag>Suscriptor</Tag>}
                                            </div>
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3">{c.location ?? '—'}</td>
                                        <td className="text-muted-foreground px-4 py-3 tabular-nums">{c.years.length ? c.years.join(', ') : '—'}</td>
                                        <td className={`px-4 py-3 ${STATUS[c.status].className}`}>{STATUS[c.status].label}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {contacts.last_page > 1 && (
                    <nav className="flex items-center justify-between gap-4 text-sm" aria-label="Paginación">
                        <span className="text-muted-foreground">
                            {number.format(contacts.from ?? 0)}–{number.format(contacts.to ?? 0)} de {number.format(contacts.total)}
                        </span>
                        <div className="flex gap-2">
                            <PageLink href={contacts.prev_page_url} label="Anterior">
                                <ChevronLeft aria-hidden />
                            </PageLink>
                            <PageLink href={contacts.next_page_url} label="Siguiente">
                                <ChevronRight aria-hidden />
                            </PageLink>
                        </div>
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl border p-4">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="mt-1 text-2xl font-semibold tabular-nums">{number.format(value)}</dd>
        </div>
    );
}

function Tag({ children }: { children: React.ReactNode }) {
    return <span className="bg-secondary text-secondary-foreground rounded-full px-2 py-0.5 text-xs font-medium">{children}</span>;
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

function FormSnippetDialog({ endpoint }: { endpoint: string }) {
    const [copied, setCopied] = useState(false);
    const snippet = `<form action="${endpoint}" method="post">
  <input type="text" name="first_name" placeholder="Nombre">
  <input type="email" name="email" placeholder="Correo" required>
  <label>
    <input type="checkbox" name="consent" value="1" required>
    Autorizo el tratamiento de mis datos según la <a href="https://colombiabirdfair.com/politica-de-privacidad">política de privacidad</a>.
  </label>
  <!-- Campo trampa para bots: no lo quites -->
  <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
  <input type="hidden" name="redirect" value="https://colombiabirdfair.com/gracias">
  <button type="submit">Suscribirme</button>
</form>`;

    const copy = async () => {
        await navigator.clipboard.writeText(snippet);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <Code aria-hidden />
                    Formulario web
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Formulario de suscripción para el sitio web</DialogTitle>
                    <DialogDescription>
                        Pega este código en un bloque HTML personalizado de WordPress. Quien se suscriba recibe un correo para confirmar y, al
                        hacerlo, aparece aquí como suscriptor. Cambia el enlace de la política y la página de gracias por los tuyos.
                    </DialogDescription>
                </DialogHeader>
                <pre className="bg-muted max-h-72 overflow-auto rounded-md p-4 text-xs leading-relaxed">
                    <code>{snippet}</code>
                </pre>
                <Button onClick={copy} className="justify-self-start">
                    <Copy aria-hidden />
                    {copied ? 'Copiado' : 'Copiar código'}
                </Button>
            </DialogContent>
        </Dialog>
    );
}
