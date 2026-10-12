export const number = new Intl.NumberFormat('es-CO');

export const money = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });

export const dateTime = new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Bogota' });

/** Formats a plain Y-m-d date without shifting it across time zones. */
export function day(value: string | null): string {
    if (!value) return '—';
    const [y, m, d] = value.split('-').map(Number);
    return new Intl.DateTimeFormat('es-CO', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' }).format(
        new Date(Date.UTC(y, m - 1, d)),
    );
}

export const ACTIVITY_STATUS: Record<string, string> = {
    reserved: 'Reservada',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    removed: 'Retirada',
    released: 'Liberada',
};
