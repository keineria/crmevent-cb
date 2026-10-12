export type Mode = 'entrada' | 'salidas';

export interface CheckInInfo {
    id: number;
    at: string;
    by: string | null;
    method: string;
}

export interface RecordActivity {
    id: number;
    name: string | null;
    type: string | null;
    date: string | null;
    start_time: string | null;
    status: string;
    active: boolean;
    price: number;
    discount: number;
    total: number;
    discount_label: string | null;
    on_date: boolean;
    here: boolean;
    check_in: CheckInInfo | null;
}

export interface RecordOrder {
    participant: number;
    number: string;
    status: string;
    paid: boolean;
    ordered_at: string | null;
    registered_by: string | null;
    activities: RecordActivity[];
}

export interface ParticipantRecord {
    id: number;
    linked: boolean;
    name: string;
    document_type: string | null;
    document_label: string | null;
    document_number: string | null;
    email: string | null;
    whatsapp: string | null;
    shirt_size: string | null;
    meal_type: string | null;
    allergies: string | null;
    entity: string | null;
    code: string;
    qr_sent_at: string | null;
    totals: { price: number; discount: number; total: number; confirmed: number };
    discounts: { label: string; amount: number }[];
    orders: RecordOrder[];
}

export interface Options {
    documents: Record<string, string>;
    shirts: string[];
    meals: Record<string, string>;
}

export interface Permissions {
    edit_details: boolean;
    edit_activities: boolean;
}

export interface DeskActivity {
    id: number;
    name: string;
    type: string;
    date: string | null;
    start_time: string | null;
    price: string;
    available: number;
}

/** POST/GET JSON to the CRM with Laravel's CSRF cookie. */
export async function crmJson<T>(url: string, body?: unknown): Promise<T> {
    const token = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const response = await fetch(url, {
        method: body === undefined ? 'GET' : 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        body: body === undefined ? undefined : JSON.stringify(body),
        credentials: 'same-origin',
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
        const errors = json.errors ? Object.values(json.errors as Record<string, string[]>).flat() : [];
        throw new Error(errors[0] ?? json.message ?? 'No se pudo completar la acción.');
    }
    return json as T;
}
