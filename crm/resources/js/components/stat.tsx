import { number } from '@/lib/format';

export default function Stat({ label, value, display }: { label: string; value: number; display?: string }) {
    return (
        <div className="rounded-xl border p-4">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="mt-1 text-2xl font-semibold tabular-nums">{display ?? number.format(value)}</dd>
        </div>
    );
}
