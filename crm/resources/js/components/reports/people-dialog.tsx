import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { number } from '@/lib/format';
import { Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useEffect, useState } from 'react';
import { getJson, reportQuery, type ReportFilters, type ReportPerson } from './types';

interface Target {
    section: string;
    card: string;
    label: string;
}

interface Payload {
    title: string;
    total: number;
    people: ReportPerson[];
}

export default function PeopleDialog({ filters, target, onClose }: { filters: ReportFilters; target: Target | null; onClose: () => void }) {
    const [data, setData] = useState<Payload | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!target) return;
        let alive = true;
        setData(null);
        setError(null);
        getJson<Payload>(`/reports/${target.section}/personas?${reportQuery(filters, { tarjeta: target.card })}`)
            .then((d) => alive && setData(d))
            .catch((e: Error) => alive && setError(e.message));
        return () => {
            alive = false;
        };
    }, [filters, target]);

    const csv = target ? `/reports/${target.section}/csv?${reportQuery(filters, { tarjeta: target.card })}` : '#';

    return (
        <Dialog open={target !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="flex max-h-[90vh] max-w-4xl flex-col">
                <DialogHeader>
                    <DialogTitle>{target?.label}</DialogTitle>
                    <DialogDescription>
                        {data ? `${number.format(data.total)} ${data.total === 1 ? 'persona' : 'personas'}` : 'Cargando…'}
                        {data &&
                            data.total > data.people.length &&
                            ` · se muestran ${number.format(data.people.length)}; descarga el CSV para verlas todas`}
                    </DialogDescription>
                </DialogHeader>

                {error && <p className="text-destructive text-sm">{error}</p>}

                {data && data.people.length === 0 && <p className="text-muted-foreground text-sm">Nadie en este grupo con los filtros actuales.</p>}

                {data && data.people.length > 0 && (
                    <div className="-mx-6 min-h-0 flex-1 overflow-auto border-y">
                        <table className="w-full min-w-[640px] text-sm">
                            <thead className="bg-muted/50 text-muted-foreground sticky top-0 text-left text-xs">
                                <tr>
                                    <th scope="col" className="px-6 py-2 font-medium">
                                        Nombre
                                    </th>
                                    <th scope="col" className="px-3 py-2 font-medium">
                                        Documento
                                    </th>
                                    <th scope="col" className="px-3 py-2 font-medium">
                                        País / ciudad
                                    </th>
                                    <th scope="col" className="px-3 py-2 font-medium">
                                        Teléfono
                                    </th>
                                    <th scope="col" className="px-6 py-2 font-medium">
                                        Registro
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {data.people.map((p) => (
                                    <tr key={p.id}>
                                        <td className="px-6 py-2">
                                            {p.contact_id ? (
                                                <Link href={`/contacts/${p.contact_id}`} className="font-medium underline-offset-4 hover:underline">
                                                    {p.name}
                                                </Link>
                                            ) : (
                                                <span className="font-medium">{p.name}</span>
                                            )}
                                            {p.email && <div className="text-muted-foreground text-xs">{p.email}</div>}
                                        </td>
                                        <td className="px-3 py-2 tabular-nums">{p.document ?? '—'}</td>
                                        <td className="px-3 py-2">{[p.country, p.city].filter(Boolean).join(' · ') || '—'}</td>
                                        <td className="px-3 py-2 tabular-nums">{p.phone ?? '—'}</td>
                                        <td className="px-6 py-2">
                                            {p.checked_in ? <Badge>Activado</Badge> : <Badge variant="outline">Sin activar</Badge>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="flex justify-end">
                    <Button variant="outline" asChild disabled={!data || data.total === 0}>
                        <a href={csv} download>
                            <Download className="size-4" />
                            Descargar CSV
                        </a>
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
