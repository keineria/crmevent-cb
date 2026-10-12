import { money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useEffect, useMemo, useRef, useState } from 'react';
import './charts.css';

export const SERIES = ['var(--series-1)', 'var(--series-2)', 'var(--series-3)', 'var(--series-4)', 'var(--series-5)', 'var(--series-6)'];

export const compact = new Intl.NumberFormat('es-CO', { notation: 'compact', maximumFractionDigits: 1 });

const shortDay = new Intl.DateTimeFormat('es-CO', { day: 'numeric', month: 'short', timeZone: 'UTC' });
const longDay = new Intl.DateTimeFormat('es-CO', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

function utc(date: string): Date {
    const [y, m, d] = date.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d));
}

function useWidth<T extends HTMLElement>() {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(640);
    useEffect(() => {
        if (!ref.current) return;
        const observer = new ResizeObserver(([entry]) => setWidth(Math.max(260, Math.floor(entry.contentRect.width))));
        observer.observe(ref.current);
        return () => observer.disconnect();
    }, []);
    return [ref, width] as const;
}

/** Rounds the top of the scale up to a tidy value so the gridlines land on round numbers. */
function niceMax(value: number): number {
    if (value <= 0) return 1;
    const power = 10 ** Math.floor(Math.log10(value));
    const step = [1, 2, 2.5, 5, 10].find((s) => s * power >= value) ?? 10;
    return step * power;
}

export interface LineSeries {
    key: string;
    label: string;
    color: string;
    values: number[];
}

/**
 * Lines over days, one money axis, with a crosshair and a tooltip listing every series on the hovered day.
 */
export function LineChart({ dates, series, height = 240 }: { dates: string[]; series: LineSeries[]; height?: number }) {
    const [ref, width] = useWidth<HTMLDivElement>();
    const [hover, setHover] = useState<number | null>(null);
    const pad = { top: 12, right: 12, bottom: 28, left: 52 };
    const innerW = width - pad.left - pad.right;
    const innerH = height - pad.top - pad.bottom;

    const max = niceMax(Math.max(0, ...series.flatMap((s) => s.values)));
    const x = (i: number) => pad.left + (dates.length < 2 ? innerW / 2 : (i / (dates.length - 1)) * innerW);
    const y = (v: number) => pad.top + innerH - (v / max) * innerH;
    const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => t * max);
    const labelEvery = Math.max(1, Math.ceil(dates.length / Math.max(2, Math.floor(innerW / 70))));

    const onMove = (event: React.PointerEvent<SVGRectElement>) => {
        const rect = event.currentTarget.getBoundingClientRect();
        const ratio = (event.clientX - rect.left) / rect.width;
        setHover(Math.min(dates.length - 1, Math.max(0, Math.round(ratio * (dates.length - 1)))));
    };

    if (dates.length === 0) {
        return <div className="text-muted-foreground flex h-40 items-center justify-center text-sm">Sin movimientos en este periodo.</div>;
    }

    return (
        <div ref={ref} className="viz-root relative">
            <svg width={width} height={height} role="img" aria-label={`Gráfica de ${series.map((s) => s.label).join(' y ')} por día`}>
                {ticks.map((t) => (
                    <g key={t}>
                        <line x1={pad.left} x2={width - pad.right} y1={y(t)} y2={y(t)} className="stroke-border" strokeWidth={1} />
                        <text x={pad.left - 8} y={y(t)} dy="0.32em" textAnchor="end" className="fill-muted-foreground text-[11px] tabular-nums">
                            {compact.format(t)}
                        </text>
                    </g>
                ))}
                {dates.map((d, i) =>
                    i % labelEvery === 0 ? (
                        <text key={d} x={x(i)} y={height - 8} textAnchor="middle" className="fill-muted-foreground text-[11px]">
                            {shortDay.format(utc(d))}
                        </text>
                    ) : null,
                )}
                {series.map((s) => (
                    <polyline
                        key={s.key}
                        fill="none"
                        stroke={s.color}
                        strokeWidth={2}
                        strokeLinejoin="round"
                        strokeLinecap="round"
                        points={s.values.map((v, i) => `${x(i)},${y(v)}`).join(' ')}
                    />
                ))}
                {hover !== null && (
                    <g>
                        <line
                            x1={x(hover)}
                            x2={x(hover)}
                            y1={pad.top}
                            y2={pad.top + innerH}
                            className="stroke-muted-foreground"
                            strokeDasharray="3 3"
                        />
                        {series.map((s) => (
                            <circle
                                key={s.key}
                                cx={x(hover)}
                                cy={y(s.values[hover])}
                                r={4.5}
                                fill={s.color}
                                className="stroke-card"
                                strokeWidth={2}
                            />
                        ))}
                    </g>
                )}
                <rect
                    x={pad.left}
                    y={pad.top}
                    width={innerW}
                    height={innerH}
                    fill="transparent"
                    onPointerMove={onMove}
                    onPointerDown={onMove}
                    onPointerLeave={() => setHover(null)}
                />
            </svg>
            {hover !== null && (
                <div
                    className="bg-popover text-popover-foreground pointer-events-none absolute top-2 z-10 min-w-40 rounded-md border px-3 py-2 text-xs shadow-md"
                    style={x(hover) > width / 2 ? { right: width - x(hover) + 12 } : { left: x(hover) + 12 }}
                >
                    <div className="mb-1 font-medium">{longDay.format(utc(dates[hover]))}</div>
                    {series.map((s) => (
                        <div key={s.key} className="flex items-center justify-between gap-4">
                            <span className="flex items-center gap-1.5">
                                <span className="h-2 w-2 rounded-full" style={{ background: s.color }} aria-hidden />
                                {s.label}
                            </span>
                            <span className="tabular-nums">{money.format(s.values[hover])}</span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export interface Slice {
    key: string;
    label: string;
    value: number;
    detail?: string;
}

/**
 * Donut with a legend that carries every value, so no slice depends on color alone.
 * Beyond six slices the smallest fold into "Otros".
 */
export function DonutChart({
    slices,
    selected,
    onSelect,
    emptyText,
    centerLabel,
}: {
    slices: Slice[];
    selected?: string | null;
    onSelect?: (key: string | null) => void;
    emptyText: string;
    centerLabel: string;
}) {
    const [hover, setHover] = useState<string | null>(null);
    const shown = useMemo(() => {
        const positive = slices.filter((s) => s.value > 0);
        if (positive.length <= 6) return positive.map((s, i) => ({ ...s, color: SERIES[i] }));
        const sorted = [...positive].sort((a, b) => b.value - a.value);
        const rest = sorted.slice(5);
        return [
            ...sorted.slice(0, 5).map((s, i) => ({ ...s, color: SERIES[i] })),
            { key: '__otros', label: 'Otros', value: rest.reduce((sum, s) => sum + s.value, 0), color: 'var(--series-other)', detail: undefined },
        ];
    }, [slices]);
    const total = shown.reduce((sum, s) => sum + s.value, 0);

    if (total <= 0) {
        return <div className="text-muted-foreground flex h-40 items-center justify-center text-sm">{emptyText}</div>;
    }

    const size = 176;
    const r = size / 2;
    const inner = r * 0.62;
    let angle = -Math.PI / 2;
    const arcs = shown.map((s) => {
        const sweep = (s.value / total) * Math.PI * 2;
        const start = angle;
        angle += sweep;
        return { ...s, start, end: angle, sweep };
    });
    const active = arcs.find((a) => a.key === (hover ?? selected));

    const path = (start: number, end: number) => {
        if (end - start >= Math.PI * 2 - 1e-6) end = start + Math.PI * 2 - 1e-4;
        const large = end - start > Math.PI ? 1 : 0;
        const p = (rad: number, a: number) => `${r + rad * Math.cos(a)},${r + rad * Math.sin(a)}`;
        return `M${p(r, start)} A${r},${r} 0 ${large} 1 ${p(r, end)} L${p(inner, end)} A${inner},${inner} 0 ${large} 0 ${p(inner, start)} Z`;
    };

    const toggle = (key: string) => onSelect && key !== '__otros' && onSelect(selected === key ? null : key);

    return (
        <div className="viz-root flex flex-col items-center gap-4 sm:flex-row sm:items-start">
            <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label={centerLabel} className="shrink-0">
                {arcs.map((a) => (
                    <path
                        key={a.key}
                        d={path(a.start, a.end)}
                        fill={a.color}
                        className={cn('stroke-card transition-opacity', onSelect && a.key !== '__otros' && 'cursor-pointer')}
                        strokeWidth={2}
                        opacity={(hover ?? selected) && (hover ?? selected) !== a.key ? 0.35 : 1}
                        onPointerEnter={() => setHover(a.key)}
                        onPointerLeave={() => setHover(null)}
                        onClick={() => toggle(a.key)}
                    />
                ))}
                <text x={r} y={r - 6} textAnchor="middle" className="fill-muted-foreground text-[11px]">
                    {active ? active.label : centerLabel}
                </text>
                <text x={r} y={r + 12} textAnchor="middle" className="fill-foreground text-sm font-semibold tabular-nums">
                    {active ? `${Math.round((active.value / total) * 100)} %` : compact.format(total)}
                </text>
            </svg>
            <ul className="w-full min-w-0 space-y-1 text-sm">
                {arcs.map((a) => (
                    <li key={a.key}>
                        <button
                            type="button"
                            disabled={!onSelect || a.key === '__otros'}
                            onClick={() => toggle(a.key)}
                            onPointerEnter={() => setHover(a.key)}
                            onPointerLeave={() => setHover(null)}
                            aria-pressed={onSelect ? selected === a.key : undefined}
                            className={cn(
                                'flex w-full items-center gap-2 rounded-md px-2 py-1 text-left',
                                onSelect && a.key !== '__otros' && 'hover:bg-muted',
                                selected === a.key && 'bg-muted',
                            )}
                        >
                            <span className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ background: a.color }} aria-hidden />
                            <span className="min-w-0 flex-1 truncate">{a.label}</span>
                            <span className="text-muted-foreground text-xs tabular-nums">{Math.round((a.value / total) * 100)} %</span>
                            <span className="w-24 text-right tabular-nums">{money.format(a.value)}</span>
                        </button>
                        {a.detail && <div className="text-muted-foreground pl-6 text-xs">{a.detail}</div>}
                    </li>
                ))}
            </ul>
        </div>
    );
}
