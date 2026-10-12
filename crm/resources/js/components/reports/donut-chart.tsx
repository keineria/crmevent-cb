import { number } from '@/lib/format';
import { useState } from 'react';

interface Slice {
    label: string;
    count: number;
}

/** Each value keeps its color wherever it appears, whatever its rank. */
const COLORS: Record<string, string> = {
    Masculino: 'var(--chart-2)',
    Femenino: 'var(--chart-3)',
    Otro: 'var(--chart-5)',
    'Sin dato': 'var(--chart-4)',
};

const R = 42;
const STROKE = 16;
const CIRCUMFERENCE = 2 * Math.PI * R;

/**
 * A donut for a handful of shares (sex), with a legend that names every value and its count.
 */
export default function DonutChart({ slices, label }: { slices: Slice[]; label: string }) {
    const [active, setActive] = useState<string | null>(null);
    const total = slices.reduce((sum, s) => sum + s.count, 0);
    if (total === 0) return null;

    let offset = 0;
    const arcs = slices.map((s) => {
        const length = (s.count / total) * CIRCUMFERENCE;
        // A 2px gap between slices, unless one slice is the whole ring.
        const arc = { ...s, dash: Math.max(0, length - (slices.length > 1 ? 2 : 0)), offset };
        offset += length;
        return arc;
    });
    const shown = slices.find((s) => s.label === active);

    return (
        <figure className="flex flex-wrap items-center gap-6">
            <svg viewBox="0 0 120 120" className="size-36 shrink-0" role="img" aria-label={label}>
                <g transform="rotate(-90 60 60)">
                    <circle cx="60" cy="60" r={R} fill="none" stroke="var(--muted)" strokeWidth={STROKE} />
                    {arcs.map((a) => (
                        <circle
                            key={a.label}
                            cx="60"
                            cy="60"
                            r={R}
                            fill="none"
                            stroke={COLORS[a.label] ?? 'var(--chart-1)'}
                            strokeWidth={STROKE}
                            strokeDasharray={`${a.dash} ${CIRCUMFERENCE - a.dash}`}
                            strokeDashoffset={-a.offset}
                            opacity={active === null || active === a.label ? 1 : 0.35}
                            onMouseEnter={() => setActive(a.label)}
                            onMouseLeave={() => setActive(null)}
                        >
                            <title>{`${a.label}: ${number.format(a.count)}`}</title>
                        </circle>
                    ))}
                </g>
                <text x="60" y="60" textAnchor="middle" dominantBaseline="central" className="fill-foreground text-sm font-semibold">
                    {shown ? `${Math.round((shown.count / total) * 100)}%` : number.format(total)}
                </text>
            </svg>
            <ul className="space-y-1.5 text-sm">
                {slices.map((s) => (
                    <li
                        key={s.label}
                        className="flex items-center gap-2"
                        onMouseEnter={() => setActive(s.label)}
                        onMouseLeave={() => setActive(null)}
                    >
                        <span className="size-2.5 shrink-0 rounded-full" style={{ background: COLORS[s.label] ?? 'var(--chart-1)' }} aria-hidden />
                        <span>{s.label}</span>
                        <span className="text-muted-foreground tabular-nums">
                            {number.format(s.count)} · {Math.round((s.count / total) * 100)}%
                        </span>
                    </li>
                ))}
            </ul>
        </figure>
    );
}
