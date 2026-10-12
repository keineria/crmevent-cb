import Heading from '@/components/heading';
import Stat from '@/components/stat';
import AppLayout from '@/layouts/app-layout';
import { day, money, number } from '@/lib/format';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface PageProps extends SharedData {
    contacts: { total: number; mailable: number; subscribers: number; loyal: number };
    years: { year: number; attendees: number; returning: number }[];
    plugin: { paid_orders: number; revenue: number; participants: number };
    fullest: { evr_id: number; name: string; event_date: string | null; capacity_total: number; confirmed_count: number; reserved_count: number }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Resumen', href: '/dashboard' }];

export default function Dashboard() {
    const { contacts, years, plugin, fullest, auth } = usePage<PageProps>().props;
    const firstName = auth.user.name.split(' ')[0];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Resumen" />
            <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-6">
                <Heading title={`Hola, ${firstName}`} description="Así va Colombia Birdfair en el CRM." />

                <section className="space-y-3" aria-labelledby="contacts-heading">
                    <h2 id="contacts-heading" className="text-base font-semibold">
                        Comunidad
                    </h2>
                    <dl className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <Stat label="Contactos" value={contacts.total} />
                        <Stat label="Activos con correo" value={contacts.mailable} />
                        <Stat label="Suscriptores web" value={contacts.subscribers} />
                        <Stat label="Fueron a 3 o más ferias" value={contacts.loyal} />
                    </dl>
                </section>

                <section className="space-y-3" aria-labelledby="plugin-heading">
                    <h2 id="plugin-heading" className="text-base font-semibold">
                        Inscripciones del plugin
                    </h2>
                    <dl className="grid grid-cols-2 gap-3 md:grid-cols-3">
                        <Stat label="Pedidos pagados" value={plugin.paid_orders} />
                        <Stat label="Participantes" value={plugin.participants} />
                        <Stat label="Recaudo" value={plugin.revenue} display={money.format(plugin.revenue)} />
                    </dl>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="space-y-3" aria-labelledby="years-heading">
                        <h2 id="years-heading" className="text-base font-semibold">
                            Asistentes por feria
                        </h2>
                        {years.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Importa los Excel de ferias anteriores para ver el historial.</p>
                        ) : (
                            <table className="w-full rounded-xl border text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Feria
                                        </th>
                                        <th scope="col" className="px-4 py-3 text-right font-medium">
                                            Asistentes
                                        </th>
                                        <th scope="col" className="px-4 py-3 text-right font-medium">
                                            Repiten
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {years.map((y) => (
                                        <tr key={y.year}>
                                            <td className="px-4 py-3">
                                                <Link href={`/contacts?anio=${y.year}`} className="font-medium underline-offset-4 hover:underline">
                                                    {y.year}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">{number.format(y.attendees)}</td>
                                            <td className="text-muted-foreground px-4 py-3 text-right tabular-nums">
                                                {y.returning
                                                    ? `${number.format(y.returning)} (${Math.round((y.returning / y.attendees) * 100)}%)`
                                                    : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>

                    <section className="space-y-3" aria-labelledby="fullest-heading">
                        <h2 id="fullest-heading" className="text-base font-semibold">
                            Actividades más llenas
                        </h2>
                        {fullest.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Aparecen cuando conectes el plugin de inscripciones.</p>
                        ) : (
                            <ul className="divide-y rounded-xl border text-sm">
                                {fullest.map((a) => {
                                    const used = a.confirmed_count + a.reserved_count;
                                    return (
                                        <li key={a.evr_id} className="flex items-center justify-between gap-4 px-4 py-3">
                                            <div className="min-w-0">
                                                <Link
                                                    href={`/registrations?actividad=${a.evr_id}`}
                                                    className="block truncate font-medium underline-offset-4 hover:underline"
                                                >
                                                    {a.name}
                                                </Link>
                                                <span className="text-muted-foreground text-xs">{day(a.event_date)}</span>
                                            </div>
                                            <span className="shrink-0 tabular-nums">
                                                {number.format(used)} / {number.format(a.capacity_total)}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
