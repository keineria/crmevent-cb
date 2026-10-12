import Flash from '@/components/campaigns/flash';
import Heading from '@/components/heading';
import Stars from '@/components/stars';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { ACTIVITY_STATUS, dateTime, day, money } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';

type Status = 'activo' | 'desinscrito';

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
    awaiting_confirmation: boolean;
    products: string[];
    products_manual: boolean;
    profile: string | null;
    language: string | null;
    referral_source: string | null;
    stars: number | null;
    emails_sent: number;
    emails_opened: number;
    emails_clicked: number;
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
    canEdit: boolean;
    [key: string]: unknown;
}

const SOURCES: Record<string, string> = {
    import: 'Importación de Excel',
    web: 'Formulario web',
    plugin: 'Plugin de inscripciones',
    manual: 'Creado a mano',
};

const STATUS: Record<Status, { label: string; className: string }> = {
    activo: { label: 'Activo', className: 'text-success' },
    desinscrito: { label: 'Desinscrito', className: 'text-destructive' },
};

export default function ContactShow() {
    const { contact, editions, registrations, canEdit } = usePage<PageProps>().props;
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
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title={title} description={types.join(' · ') || 'Contacto'} />
                    {canEdit && (
                        <Button variant="outline" asChild>
                            <Link href={`/contacts/${contact.id}/edit`}>
                                <Pencil aria-hidden />
                                Editar
                            </Link>
                        </Button>
                    )}
                </div>

                <Flash />

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
                                    ['Perfil', contact.profile],
                                    ['Idioma', contact.language],
                                    ['Nos conoció por', contact.referral_source],
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
                        <div className="space-y-3 rounded-xl border p-4 text-sm">
                            <div>
                                <div className="text-muted-foreground text-xs">Producto preferido</div>
                                <div className="font-medium">{contact.products.length ? contact.products.join(', ') : 'Sin compras aún'}</div>
                                <div className="text-muted-foreground text-xs">
                                    {contact.products_manual ? 'Elegido a mano' : 'Calculado con sus compras'}
                                </div>
                            </div>
                            <div>
                                <div className="text-muted-foreground text-xs">Interacción con los correos</div>
                                <Stars value={contact.stars} className="mt-1" />
                                {contact.emails_sent > 0 && (
                                    <div className="text-muted-foreground text-xs">
                                        Abrió {contact.emails_opened} y hizo clic en {contact.emails_clicked} de {contact.emails_sent} campañas
                                    </div>
                                )}
                            </div>
                        </div>
                        <div className="rounded-xl border p-4 text-sm">
                            <div className={`font-medium ${STATUS[contact.status].className}`}>{STATUS[contact.status].label}</div>
                            {contact.awaiting_confirmation && (
                                <p className="text-warning mt-1 text-xs">Se suscribió en el sitio web y aún no confirma su correo.</p>
                            )}
                            {contact.consent_source && contact.status === 'activo' && !contact.awaiting_confirmation && (
                                <p className="text-muted-foreground mt-1 text-xs">
                                    {contact.consent_source}
                                    {contact.consent_at && ` · ${dateTime.format(new Date(contact.consent_at))}`}
                                </p>
                            )}
                            {contact.unsubscribed_at && (
                                <p className="text-muted-foreground mt-1 text-xs">
                                    Se desinscribió el {dateTime.format(new Date(contact.unsubscribed_at))}
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
