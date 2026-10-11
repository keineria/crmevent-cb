import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { ACTIVITY_STATUS, dateTime, day, money } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';

type Status = 'acepta' | 'baja' | 'pendiente' | 'sin_consentimiento';

interface ContactData {
    id: number;
    name: string | null;
    email: string | null;
    phone: string | null;
    document: string | null;
    gender: string | null;
    organization: string | null;
    location: string | null;
    notes: string | null;
    source: string;
    is_attendee: boolean;
    is_interested: boolean;
    is_subscriber: boolean;
    status: Status;
    consent_source: string | null;
    consent_at: string | null;
    unsubscribed_at: string | null;
    created_at: string;
}

interface PageProps {
    contact: ContactData;
    editions: { year: number; role: string; details: Record<string, string> }[];
    registrations: {
        order: string;
        status: string;
        status_label: string;
        ordered_at: string | null;
        shirt_size: string | null;
        meal_type: string | null;
        allergies: string | null;
        activities: { name: string | null; type: string | null; date: string | null; status: string; total: number }[];
    }[];
    [key: string]: unknown;
}

const SOURCES: Record<string, string> = {
    import: 'Importación de Excel',
    web: 'Formulario web',
    plugin: 'Plugin de inscripciones',
    manual: 'Creado a mano',
};

const STATUS: Record<Status, { label: string; className: string }> = {
    acepta: { label: 'Acepta difusiones', className: 'text-success' },
    pendiente: { label: 'Confirmación pendiente', className: 'text-warning' },
    sin_consentimiento: { label: 'Sin autorización para difusiones', className: 'text-muted-foreground' },
    baja: { label: 'Dado de baja', className: 'text-destructive' },
};

export default function ContactShow() {
    const { contact, editions, registrations } = usePage<PageProps>().props;
    const title = contact.name ?? contact.email ?? 'Contacto';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Contactos', href: '/contacts' },
        { title, href: `/contacts/${contact.id}` },
    ];
    const types = [contact.is_attendee && 'Asistente', contact.is_interested && 'Interesado', contact.is_subscriber && 'Suscriptor'].filter(Boolean);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex max-w-5xl min-w-0 flex-1 flex-col gap-8 p-4 md:p-6">
                <div>
                    <Heading title={title} description={types.join(' · ') || 'Contacto'} />
                </div>

                <div className="grid gap-6 lg:grid-cols-[20rem_1fr]">
                    <aside className="space-y-4">
                        <dl className="divide-y rounded-xl border text-sm">
                            {(
                                [
                                    ['Correo', contact.email],
                                    ['Teléfono', contact.phone],
                                    ['Documento', contact.document],
                                    ['Género', contact.gender],
                                    ['Organización', contact.organization],
                                    ['Ubicación', contact.location],
                                    ['Origen', SOURCES[contact.source] ?? contact.source],
                                    ['En el CRM desde', dateTime.format(new Date(contact.created_at))],
                                ] as const
                            ).map(([label, value]) => (
                                <div key={label} className="grid grid-cols-[7.5rem_1fr] gap-2 px-4 py-2.5">
                                    <dt className="text-muted-foreground">{label}</dt>
                                    <dd className="break-words">{value || '—'}</dd>
                                </div>
                            ))}
                        </dl>
                        <div className="rounded-xl border p-4 text-sm">
                            <div className={`font-medium ${STATUS[contact.status].className}`}>{STATUS[contact.status].label}</div>
                            {contact.consent_source && contact.status === 'acepta' && (
                                <p className="text-muted-foreground mt-1 text-xs">
                                    {contact.consent_source}
                                    {contact.consent_at && ` · ${dateTime.format(new Date(contact.consent_at))}`}
                                </p>
                            )}
                            {contact.unsubscribed_at && (
                                <p className="text-muted-foreground mt-1 text-xs">
                                    Se dio de baja el {dateTime.format(new Date(contact.unsubscribed_at))}
                                </p>
                            )}
                        </div>
                        {contact.notes && <p className="text-muted-foreground rounded-xl border p-4 text-sm whitespace-pre-line">{contact.notes}</p>}
                    </aside>

                    <div className="min-w-0 space-y-8">
                        <section className="space-y-3">
                            <h2 className="text-base font-semibold">Inscripciones del plugin</h2>
                            {registrations.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Sin inscripciones desde el plugin.</p>
                            ) : (
                                registrations.map((r) => (
                                    <article key={r.order} className="rounded-xl border p-4 text-sm">
                                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                                            <h3 className="font-medium tabular-nums">Pedido #{r.order}</h3>
                                            <span className="text-muted-foreground text-xs">
                                                {r.status_label}
                                                {r.ordered_at && ` · ${dateTime.format(new Date(r.ordered_at))}`}
                                            </span>
                                        </div>
                                        <ul className="mt-3 divide-y">
                                            {r.activities.map((a, i) => (
                                                <li key={i} className="flex justify-between gap-4 py-1.5">
                                                    <span
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
                                                    </span>
                                                    <span className="tabular-nums">{money.format(a.total)}</span>
                                                </li>
                                            ))}
                                        </ul>
                                        {(r.shirt_size || r.meal_type || r.allergies) && (
                                            <p className="text-muted-foreground mt-2 text-xs">
                                                {[r.shirt_size && `Camisa ${r.shirt_size}`, r.meal_type, r.allergies && `Alergias: ${r.allergies}`]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        )}
                                    </article>
                                ))
                            )}
                        </section>

                        <section className="space-y-3">
                            <h2 className="text-base font-semibold">Historial por feria</h2>
                            {editions.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Sin ferias registradas.</p>
                            ) : (
                                editions.map((e) => (
                                    <article key={`${e.year}-${e.role}`} className="rounded-xl border p-4 text-sm">
                                        <h3 className="font-medium">
                                            {e.year} · {e.role === 'asistente' ? 'Asistente' : 'Interesado'}
                                        </h3>
                                        {Object.keys(e.details).length > 0 && (
                                            <dl className="mt-3 grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                                                {Object.entries(e.details).map(([k, v]) => (
                                                    <div key={k} className="grid grid-cols-[9rem_1fr] gap-2">
                                                        <dt className="text-muted-foreground truncate" title={k}>
                                                            {k}
                                                        </dt>
                                                        <dd className="break-words">{v}</dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        )}
                                    </article>
                                ))
                            )}
                        </section>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
