export interface ReportFilters {
    anio: number;
    desde: string | null;
    hasta: string | null;
}

export interface ReportCard {
    key: string;
    label: string;
    count: number;
    /** People in the whole report, for cards that show a share. */
    total: number | null;
    /** Only on activity cards. */
    date?: string | null;
    time?: string | null;
    destination?: string | null;
    capacity?: number;
    available?: number | null;
    checked_in?: number;
}

export interface ReportPerson {
    id: number;
    contact_id: number | null;
    name: string;
    document: string | null;
    country: string | null;
    city: string | null;
    phone: string | null;
    email: string | null;
    shirt_size: string | null;
    order: string;
    checked_in: boolean;
}

/** Query string for the report endpoints, with the page's filters and any extra params. */
export function reportQuery(filters: ReportFilters, extra: Record<string, string> = {}): string {
    const params = new URLSearchParams({ anio: String(filters.anio), ...extra });
    if (filters.desde) params.set('desde', filters.desde);
    if (filters.hasta) params.set('hasta', filters.hasta);
    return params.toString();
}

export async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    if (!response.ok) throw new Error('No se pudo cargar el reporte. Intenta de nuevo.');
    return (await response.json()) as T;
}

export const upperFirst = (s: string): string => s.charAt(0).toUpperCase() + s.slice(1);
