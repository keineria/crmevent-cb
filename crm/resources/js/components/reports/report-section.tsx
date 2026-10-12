import { day, number } from '@/lib/format';
import { ChevronDown } from 'lucide-react';
import { useEffect, useState } from 'react';
import { getJson, reportQuery, upperFirst, type ReportCard, type ReportFilters } from './types';

const ACTIVITY_SECTIONS = ['salidas', 'conferencias', 'talleres-pagos', 'talleres-gratuitos', 'otras'];

interface Props {
    sectionKey: string;
    title: string;
    filters: ReportFilters;
    defaultOpen?: boolean;
    onPick: (card: ReportCard) => void;
}

/**
 * One collapsible group of cards. Its numbers load the first time it opens, so the page stays light.
 */
export default function ReportSection({ sectionKey, title, filters, defaultOpen = false, onPick }: Props) {
    const [open, setOpen] = useState(defaultOpen);
    const [cards, setCards] = useState<ReportCard[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const load = () => {
        setLoading(true);
        setError(null);
        getJson<{ cards: ReportCard[] }>(`/reports/${sectionKey}?${reportQuery(filters)}`)
            .then((d) => setCards(d.cards))
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoading(false));
    };

    // The page remounts sections when filters change, so loading once per mount is enough.
    useEffect(() => {
        if (defaultOpen) load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const toggle = () => {
        if (!open && cards === null && !loading) load();
        setOpen(!open);
    };

    const isActivity = ACTIVITY_SECTIONS.includes(sectionKey);
    const total = cards?.reduce((sum, c) => sum + c.count, 0) ?? 0;
    const days = isActivity && sectionKey === 'salidas' ? groupByDay(cards ?? []) : [{ date: null, cards: cards ?? [] }];

    return (
        <section className="rounded-xl border">
            <h2>
                <button
                    type="button"
                    className="hover:bg-muted/40 flex w-full items-center justify-between gap-4 rounded-xl px-4 py-3 text-left"
                    aria-expanded={open}
                    aria-controls={`section-${sectionKey}`}
                    onClick={toggle}
                >
                    <span className="text-base font-semibold">{title}</span>
                    <span className="text-muted-foreground flex items-center gap-3 text-sm tabular-nums">
                        {cards && isActivity && `${number.format(total)} inscripciones`}
                        <ChevronDown className={`size-4 transition-transform duration-200 ${open ? 'rotate-180' : ''}`} aria-hidden />
                    </span>
                </button>
            </h2>

            {open && (
                <div id={`section-${sectionKey}`} className="space-y-4 border-t p-4">
                    {loading && <p className="text-muted-foreground text-sm">Cargando…</p>}
                    {error && (
                        <p className="text-destructive text-sm">
                            {error}{' '}
                            <button type="button" className="underline" onClick={load}>
                                Reintentar
                            </button>
                        </p>
                    )}
                    {cards && cards.length === 0 && <p className="text-muted-foreground text-sm">No hay datos para estos filtros.</p>}

                    {cards &&
                        cards.length > 0 &&
                        days.map((group) => (
                            <div key={group.date ?? 'all'} className="space-y-2">
                                {group.date !== null && <h3 className="text-muted-foreground text-sm font-medium">{upperFirst(day(group.date))}</h3>}
                                <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                    {group.cards.map((card) => (
                                        <li key={card.key}>
                                            <CardButton card={card} activity={isActivity} onPick={onPick} />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                </div>
            )}
        </section>
    );
}

function CardButton({ card, activity, onPick }: { card: ReportCard; activity: boolean; onPick: (card: ReportCard) => void }) {
    const share = card.total ? Math.round((card.count / card.total) * 100) : null;
    const used = activity && card.capacity ? Math.min(100, Math.round((card.count / card.capacity) * 100)) : null;

    return (
        <button
            type="button"
            onClick={() => onPick(card)}
            className="hover:border-primary/60 focus-visible:ring-ring h-full w-full rounded-lg border p-3 text-left transition-colors duration-150 focus-visible:ring-2 focus-visible:outline-hidden"
        >
            <div className="flex items-start justify-between gap-3">
                <span className="min-w-0">
                    <span className="block truncate text-sm font-medium" title={card.label}>
                        {card.label}
                    </span>
                    {activity && (
                        <span className="text-muted-foreground block text-xs">
                            {[card.date ? day(card.date) : null, card.time, card.destination].filter(Boolean).join(' · ')}
                        </span>
                    )}
                </span>
                <span className="text-2xl font-semibold tabular-nums">{number.format(card.count)}</span>
            </div>

            {activity ? (
                <>
                    {used !== null && (
                        <div className="bg-muted mt-2 h-1.5 overflow-hidden rounded-full" aria-hidden>
                            <div className={`h-full rounded-full ${used >= 90 ? 'bg-warning' : 'bg-primary'}`} style={{ width: `${used}%` }} />
                        </div>
                    )}
                    <div className="text-muted-foreground mt-2 flex justify-between text-xs tabular-nums">
                        <span>
                            {card.available !== null && card.available !== undefined
                                ? `${number.format(card.available)} cupos libres`
                                : 'Sin cupo definido'}
                        </span>
                        <span>{number.format(card.checked_in ?? 0)} activados</span>
                    </div>
                </>
            ) : (
                share !== null && <div className="text-muted-foreground mt-1 text-xs tabular-nums">{share}% del total</div>
            )}
        </button>
    );
}

function groupByDay(cards: ReportCard[]): { date: string | null; cards: ReportCard[] }[] {
    const groups = new Map<string, ReportCard[]>();
    for (const card of cards) {
        const key = card.date ?? '';
        groups.set(key, [...(groups.get(key) ?? []), card]);
    }
    return [...groups.entries()].map(([date, items]) => ({ date: date || null, cards: items }));
}
