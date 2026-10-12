import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { dateTime, day, money, number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface Activity {
    id: number;
    name: string;
    type: string;
    destination: string | null;
    date: string | null;
    start_time: string | null;
    price: number;
    capacity: number;
    confirmed: number;
    reserved: number;
    status: string;
}

interface PageProps {
    activities: Activity[];
    types: Record<string, string>;
    lastSync: string | null;
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Actividades', href: '/activities' }];

export default function Activities() {
    const { activities, types, lastSync } = usePage<PageProps>().props;
    const groups = Object.entries(types)
        .map(([type, label]) => ({ type, label, items: activities.filter((a) => a.type === type && a.status !== 'archived') }))
        .filter((g) => g.items.length > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Actividades" />

            <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-6">
                <Heading
                    title="Actividades y cupos"
                    description={`Copia de lo que hay en el plugin, que es quien controla los cupos.${lastSync ? ` Actualizado: ${dateTime.format(new Date(lastSync))}.` : ''}`}
                />

                {groups.length === 0 && (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                        Aún no hay actividades. Conecta el plugin en Configuración › Plugin de inscripciones.
                    </div>
                )}

                {groups.map((g) => (
                    <section key={g.type} className="space-y-3">
                        <h2 className="text-base font-semibold">{g.label}</h2>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[720px] text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Actividad
                                        </th>
                                        <th scope="col" className="px-4 py-3 font-medium">
                                            Fecha
                                        </th>
                                        <th scope="col" className="px-4 py-3 text-right font-medium">
                                            Precio
                                        </th>
                                        <th scope="col" className="w-64 px-4 py-3 font-medium">
                                            Ocupación
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {g.items.map((a) => {
                                        const used = a.confirmed + a.reserved;
                                        const pct = a.capacity ? Math.min(100, Math.round((used / a.capacity) * 100)) : 0;
                                        return (
                                            <tr key={a.id}>
                                                <td className="px-4 py-3">
                                                    <Link
                                                        href={`/registrations?actividad=${a.id}`}
                                                        className="font-medium underline-offset-4 hover:underline"
                                                    >
                                                        {a.name}
                                                    </Link>
                                                    {a.destination && <div className="text-muted-foreground text-xs">{a.destination}</div>}
                                                    {a.status !== 'active' && <div className="text-muted-foreground text-xs">Inactiva</div>}
                                                </td>
                                                <td className="text-muted-foreground px-4 py-3 tabular-nums">
                                                    {day(a.date)}
                                                    {a.start_time ? ` · ${a.start_time}` : ''}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">{money.format(a.price)}</td>
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center justify-between text-xs tabular-nums">
                                                        <span>
                                                            {number.format(a.confirmed)} confirmados
                                                            {a.reserved > 0 && ` + ${number.format(a.reserved)} en reserva`}
                                                        </span>
                                                        <span className="text-muted-foreground">de {number.format(a.capacity)}</span>
                                                    </div>
                                                    <div
                                                        className="bg-muted mt-1.5 h-1.5 overflow-hidden rounded-full"
                                                        role="meter"
                                                        aria-valuemin={0}
                                                        aria-valuemax={a.capacity}
                                                        aria-valuenow={used}
                                                        aria-label={`Ocupación de ${a.name}`}
                                                    >
                                                        <div
                                                            className={`h-full rounded-full ${pct >= 90 ? 'bg-warning' : 'bg-primary'}`}
                                                            style={{ width: `${pct}%` }}
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}
