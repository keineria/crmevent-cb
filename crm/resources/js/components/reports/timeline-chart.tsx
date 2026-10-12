import { day, number } from '@/lib/format';
import { useState } from 'react';
import { upperFirst } from './types';

interface Point {
    date: string;
    participants: number;
}

const HEIGHT = 160;

/**
 * New participants per day as thin columns, with a hover/focus tooltip and a table view for screen readers.
 */
export default function TimelineChart({ points }: { points: Point[] }) {
    const [active, setActive] = useState<number | null>(null);

    if (points.length === 0) {
        return <p className="text-muted-foreground text-sm">Aún no hay inscripciones pagas en este periodo.</p>;
    }

    const max = Math.max(1, ...points.map((p) => p.participants));
    const ticks = niceTicks(max);
    const top = ticks[ticks.length - 1];
    const shown = active !== null ? points[active] : null;
    const labelEvery = Math.max(1, Math.ceil(points.length / 8));

    return (
        <figure className="space-y-2">
            <div className="text-muted-foreground h-5 text-xs tabular-nums" aria-live="polite">
                {shown ? (
                    <>
                        <span className="text-foreground font-medium">{upperFirst(day(shown.date))}</span>: {number.format(shown.participants)}{' '}
                        {shown.participants === 1 ? 'participante' : 'participantes'}
                    </>
                ) : (
                    'Pasa el cursor por una barra para ver el día.'
                )}
            </div>

            <div className="flex gap-2" aria-hidden>
                <div className="text-muted-foreground relative w-8 shrink-0 text-right text-[11px] tabular-nums" style={{ height: HEIGHT }}>
                    {ticks.map((t) => (
                        <span key={t} className="absolute right-0 -translate-y-1/2" style={{ top: HEIGHT - (t / top) * HEIGHT }}>
                            {number.format(t)}
                        </span>
                    ))}
                </div>
                <div className="min-w-0 flex-1">
                    <div className="relative" style={{ height: HEIGHT }} onMouseLeave={() => setActive(null)}>
                        {ticks.map((t) => (
                            <div key={t} className="border-border/60 absolute inset-x-0 border-t" style={{ top: HEIGHT - (t / top) * HEIGHT }} />
                        ))}
                        <div className="absolute inset-0 flex items-end gap-[2px]">
                            {points.map((p, i) => (
                                <div
                                    key={p.date}
                                    className="flex h-full flex-1 items-end"
                                    onMouseEnter={() => setActive(i)}
                                    onTouchStart={() => setActive(i)}
                                >
                                    <div
                                        className={`w-full rounded-t-[4px] transition-opacity duration-150 ${active === null || active === i ? 'bg-primary' : 'bg-primary/40'}`}
                                        style={{ height: p.participants ? `${Math.max(2, (p.participants / top) * HEIGHT)}px` : 0 }}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                    <div className="text-muted-foreground mt-1 flex text-[11px]">
                        {points.map((p, i) => (
                            <span key={p.date} className="min-w-0 flex-1 overflow-visible text-center whitespace-nowrap">
                                {i % labelEvery === 0 ? shortDay(p.date) : ''}
                            </span>
                        ))}
                    </div>
                </div>
            </div>

            <details className="text-sm">
                <summary className="text-muted-foreground cursor-pointer text-xs">Ver como tabla</summary>
                <table className="mt-2 w-full max-w-sm text-sm">
                    <caption className="sr-only">Participantes nuevos por día</caption>
                    <thead className="text-muted-foreground text-left text-xs">
                        <tr>
                            <th scope="col" className="py-1 font-medium">
                                Día
                            </th>
                            <th scope="col" className="py-1 text-right font-medium">
                                Participantes
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {points
                            .filter((p) => p.participants > 0)
                            .map((p) => (
                                <tr key={p.date}>
                                    <td className="py-1">{upperFirst(day(p.date))}</td>
                                    <td className="py-1 text-right tabular-nums">{number.format(p.participants)}</td>
                                </tr>
                            ))}
                    </tbody>
                </table>
            </details>
        </figure>
    );
}

function shortDay(date: string): string {
    const [, m, d] = date.split('-');
    return `${Number(d)}/${Number(m)}`;
}

/** 0 plus up to four round steps that cover the max. */
function niceTicks(max: number): number[] {
    const raw = max / 4;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const step = Math.max(1, [1, 2, 5, 10].map((m) => m * magnitude).find((s) => s >= raw) ?? raw);
    const ticks: number[] = [];
    for (let t = 0; t < max + step; t += step) ticks.push(t);
    return ticks;
}
