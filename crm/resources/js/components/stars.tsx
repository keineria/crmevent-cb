import { Star } from 'lucide-react';

/** Engagement score of a contact (1 to 5); null when no campaign has been sent to them yet. */
export default function Stars({ value, className = '' }: { value: number | null; className?: string }) {
    if (value === null) {
        return <span className={`text-muted-foreground text-xs ${className}`}>Sin correos aún</span>;
    }

    return (
        <span className={`inline-flex items-center gap-0.5 ${className}`} role="img" aria-label={`${value} de 5 estrellas`} title={`${value} de 5`}>
            {[1, 2, 3, 4, 5].map((n) => (
                <Star key={n} aria-hidden className={`size-3.5 ${n <= value ? 'fill-warning text-warning' : 'text-muted-foreground/40'}`} />
            ))}
        </span>
    );
}
