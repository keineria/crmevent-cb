export interface SurveyTotals {
    sent: number;
    responded: number;
    rate: number | null;
    satisfaction: number | null;
    nps: number | null;
}

export const decimal = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 1, minimumFractionDigits: 1 });

export const percent = (value: number | null) =>
    value === null ? '—' : `${new Intl.NumberFormat('es-CO', { maximumFractionDigits: 1 }).format(value)}%`;

export const satisfaction = (value: number | null) => (value === null ? '—' : `${decimal.format(value)} / 5`);

export const nps = (value: number | null) => (value === null ? '—' : value > 0 ? `+${value}` : String(value));
