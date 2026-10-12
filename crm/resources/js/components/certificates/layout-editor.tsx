import { selectClass } from '@/components/finance/period-filter';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type KeyboardEvent, type PointerEvent, useEffect, useRef, useState } from 'react';

export interface Block {
    x: number;
    y: number;
    w?: number;
    size: number;
    color?: string;
    align?: 'left' | 'center' | 'right';
    font?: string;
    visible: boolean;
}

export type Layout = Record<string, Block>;

interface Props {
    layout: Layout;
    onChange: (layout: Layout) => void;
    background: string | null;
    blocks: Record<string, string>;
    fonts: Record<string, string>;
    /** Sample text shown in each block. */
    texts: Record<string, string>;
    signers: { name: string; role: string | null; image: string | null }[];
}

const PAGE = { w: 297, h: 210 };
const PT_TO_MM = 0.3528;
const FAMILY: Record<string, string> = {
    serif: 'CertSerif, Georgia, serif',
    sans: 'CertSans, ui-sans-serif, system-ui, sans-serif',
    script: 'CertScript, "Brush Script MT", cursive',
};

/** The same font files the PDF embeds, so what is placed here matches the certificate. */
const FONT_FACES = [
    ['CertSerif', 'PlayfairDisplay-Regular.ttf', 'normal', 'normal'],
    ['CertSerif', 'PlayfairDisplay-Bold.ttf', '700', 'normal'],
    ['CertSerif', 'PlayfairDisplay-Italic.ttf', 'normal', 'italic'],
    ['CertSans', 'Inter-Regular.ttf', 'normal', 'normal'],
    ['CertSans', 'Inter-SemiBold.ttf', '600 700', 'normal'],
    ['CertScript', 'GreatVibes-Regular.ttf', 'normal', 'normal'],
]
    .map(
        ([family, file, weight, style]) =>
            `@font-face{font-family:${family};src:url(/certificados/fuentes/${file});font-weight:${weight};font-style:${style};font-display:swap}`,
    )
    .join('');

const round = (v: number) => Math.round(v * 2) / 2;

/**
 * The uploaded design with each block of the certificate on top. Drag a block to move it (or select it and use the
 * arrow keys); its look is set on the right. Positions are millimetres on the A4 landscape page, as the PDF uses them.
 */
export default function LayoutEditor({ layout, onChange, background, blocks, fonts, texts, signers }: Props) {
    const canvas = useRef<HTMLDivElement>(null);
    const [scale, setScale] = useState(2);
    const [selected, setSelected] = useState<string>('name');
    const drag = useRef<{ key: string; startX: number; startY: number; x: number; y: number } | null>(null);

    useEffect(() => {
        const el = canvas.current;
        if (!el) return;
        const observer = new ResizeObserver(() => setScale(el.clientWidth / PAGE.w));
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    const update = (key: string, patch: Partial<Block>) => onChange({ ...layout, [key]: { ...layout[key], ...patch } });

    const start = (key: string, e: PointerEvent<HTMLDivElement>) => {
        e.preventDefault();
        setSelected(key);
        (e.target as HTMLElement).setPointerCapture?.(e.pointerId);
        drag.current = { key, startX: e.clientX, startY: e.clientY, x: layout[key].x, y: layout[key].y };
    };

    const move = (e: PointerEvent<HTMLDivElement>) => {
        const d = drag.current;
        if (!d) return;
        update(d.key, { x: round(d.x + (e.clientX - d.startX) / scale), y: round(d.y + (e.clientY - d.startY) / scale) });
    };

    const nudge = (key: string, e: KeyboardEvent<HTMLDivElement>) => {
        const step = e.shiftKey ? 5 : 1;
        const delta = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
        if (!delta) return;
        e.preventDefault();
        update(key, { x: layout[key].x + delta[0], y: layout[key].y + delta[1] });
    };

    const block = layout[selected];

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_16rem]">
            <style>{FONT_FACES}</style>
            <div className="grid gap-2">
                <div
                    ref={canvas}
                    className="relative aspect-[297/210] w-full touch-none overflow-hidden rounded-lg border bg-white select-none"
                    onPointerMove={move}
                    onPointerUp={() => (drag.current = null)}
                    onPointerCancel={() => (drag.current = null)}
                >
                    {background ? (
                        <img src={background} alt="" className="pointer-events-none absolute inset-0 h-full w-full" draggable={false} />
                    ) : (
                        <p className="text-muted-foreground absolute inset-0 grid place-items-center p-6 text-center text-sm">
                            Sube tu diseño en «Diseño de fondo» y aparecerá aquí.
                        </p>
                    )}
                    {Object.keys(blocks).map((key) => {
                        const b = layout[key];
                        if (!b) return null;
                        const isQr = key === 'qr';
                        return (
                            <div
                                key={key}
                                role="button"
                                tabIndex={0}
                                aria-label={`${blocks[key]}: arrastra para moverlo`}
                                onPointerDown={(e) => start(key, e)}
                                onKeyDown={(e) => nudge(key, e)}
                                onFocus={() => setSelected(key)}
                                className={cn(
                                    'absolute cursor-move leading-tight outline-1 outline-offset-1',
                                    selected === key ? 'outline-primary outline-2' : 'outline-transparent outline-dashed hover:outline-sky-500',
                                    !b.visible && 'opacity-30',
                                )}
                                style={{
                                    left: b.x * scale,
                                    top: b.y * scale,
                                    width: (isQr ? b.size : (b.w ?? 100)) * scale,
                                    height: isQr ? b.size * scale : undefined,
                                    fontSize: isQr ? undefined : b.size * PT_TO_MM * scale,
                                    color: b.color,
                                    textAlign: b.align,
                                    fontFamily: FAMILY[b.font ?? 'sans'],
                                    fontWeight: key === 'name' && b.font !== 'script' ? 700 : key === 'heading' ? 600 : undefined,
                                    letterSpacing: key === 'heading' ? '0.2em' : undefined,
                                    textTransform: key === 'heading' ? 'uppercase' : undefined,
                                    fontStyle: key === 'intro' && b.font === 'serif' ? 'italic' : undefined,
                                }}
                            >
                                {isQr ? (
                                    <div className="grid h-full w-full place-items-center bg-white text-[10px] text-neutral-500 ring-1 ring-neutral-300">
                                        QR
                                    </div>
                                ) : key === 'signers' ? (
                                    <div className="flex justify-around gap-2">
                                        {(signers.length ? signers : [{ name: 'Sin firmantes elegidos', role: null, image: null }]).map((s, i) => (
                                            <div key={i} className="min-w-0 flex-1">
                                                <div style={{ height: 16 * scale }} className="flex items-end justify-center">
                                                    {s.image && <img src={s.image} alt="" className="max-h-full" draggable={false} />}
                                                </div>
                                                <div style={{ borderTop: `1px solid ${b.color}` }} className="pt-0.5 font-semibold">
                                                    {s.name}
                                                </div>
                                                {s.role && <div className="opacity-80">{s.role}</div>}
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    texts[key]
                                )}
                            </div>
                        );
                    })}
                </div>
                <p className="text-muted-foreground text-xs">
                    Arrastra cada bloque a su lugar, o selecciónalo y muévelo con las flechas (Mayús para pasos de 5 mm). La vista previa en PDF de
                    abajo muestra el resultado exacto.
                </p>
            </div>

            <div className="grid content-start gap-3 rounded-lg border p-3">
                <div className="grid gap-1.5">
                    <Label htmlFor="block">Bloque</Label>
                    <select id="block" className={selectClass} value={selected} onChange={(e) => setSelected(e.target.value)}>
                        {Object.entries(blocks).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>
                {block && (
                    <>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={block.visible} onCheckedChange={(v) => update(selected, { visible: v === true })} />
                            Mostrar en el certificado
                        </label>
                        <div className="grid grid-cols-2 gap-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="bx">X (mm)</Label>
                                <Input
                                    id="bx"
                                    type="number"
                                    step="0.5"
                                    value={block.x}
                                    onChange={(e) => update(selected, { x: Number(e.target.value) })}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="by">Y (mm)</Label>
                                <Input
                                    id="by"
                                    type="number"
                                    step="0.5"
                                    value={block.y}
                                    onChange={(e) => update(selected, { y: Number(e.target.value) })}
                                />
                            </div>
                            {selected !== 'qr' && (
                                <div className="grid gap-1.5">
                                    <Label htmlFor="bw">Ancho (mm)</Label>
                                    <Input
                                        id="bw"
                                        type="number"
                                        min="10"
                                        step="1"
                                        value={block.w ?? 100}
                                        onChange={(e) => update(selected, { w: Number(e.target.value) })}
                                    />
                                </div>
                            )}
                            <div className="grid gap-1.5">
                                <Label htmlFor="bs">{selected === 'qr' ? 'Lado (mm)' : 'Tamaño (pt)'}</Label>
                                <Input
                                    id="bs"
                                    type="number"
                                    min="5"
                                    step="0.5"
                                    value={block.size}
                                    onChange={(e) => update(selected, { size: Number(e.target.value) })}
                                />
                            </div>
                        </div>
                        {selected !== 'qr' && (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="bf">Letra</Label>
                                    <select
                                        id="bf"
                                        className={selectClass}
                                        value={block.font}
                                        onChange={(e) => update(selected, { font: e.target.value })}
                                    >
                                        {Object.entries(fonts).map(([key, label]) => (
                                            <option key={key} value={key}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="bc">Color</Label>
                                    <div className="flex items-center gap-2">
                                        <input
                                            type="color"
                                            aria-label="Color: selector"
                                            value={block.color}
                                            onChange={(e) => update(selected, { color: e.target.value.toUpperCase() })}
                                            className="h-10 w-12 cursor-pointer rounded-md border bg-transparent p-1"
                                        />
                                        <Input
                                            id="bc"
                                            value={block.color}
                                            maxLength={7}
                                            onChange={(e) => update(selected, { color: e.target.value })}
                                            className="font-mono uppercase"
                                        />
                                    </div>
                                </div>
                                <div className="grid gap-1.5">
                                    <span className="text-sm font-medium">Alineación</span>
                                    <div className="grid grid-cols-3 gap-1" role="radiogroup" aria-label="Alineación">
                                        {(['left', 'center', 'right'] as const).map((a) => (
                                            <button
                                                key={a}
                                                type="button"
                                                role="radio"
                                                aria-checked={block.align === a}
                                                onClick={() => update(selected, { align: a })}
                                                className={cn(
                                                    'rounded-md border px-2 py-1.5 text-xs',
                                                    block.align === a ? 'border-primary bg-primary/10' : 'hover:bg-muted/40',
                                                )}
                                            >
                                                {{ left: 'Izquierda', center: 'Centro', right: 'Derecha' }[a]}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            </>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
