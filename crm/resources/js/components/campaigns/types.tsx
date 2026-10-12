export type CampaignStatus = 'draft' | 'scheduled' | 'sending' | 'paused' | 'sent' | 'cancelled';

export const CAMPAIGN_STATUS: Record<CampaignStatus, { label: string; className: string }> = {
    draft: { label: 'Borrador', className: 'bg-secondary text-secondary-foreground' },
    scheduled: { label: 'Programada', className: 'bg-warning/15 text-warning' },
    sending: { label: 'Enviando', className: 'bg-primary/15 text-primary' },
    paused: { label: 'En pausa', className: 'bg-warning/15 text-warning' },
    sent: { label: 'Enviada', className: 'bg-success/15 text-success' },
    cancelled: { label: 'Cancelada', className: 'bg-destructive/10 text-destructive' },
};

export interface CampaignStats {
    recipients: number;
    sent: number;
    failed: number;
    skipped: number;
    pending: number;
    opened: number;
    clicked: number;
    unsubscribed?: number;
}

export interface Segment {
    tipos?: string[];
    anios?: number[];
    min_visitas?: number;
    compro?: number;
    no_compro?: number;
    actividades?: number[];
    pais?: string;
}

export interface Content {
    name: string;
    subject: string;
    preheader: string;
    body: string;
}

/** Share of a total as a whole percentage, or a dash when there is nothing to divide. */
export function percent(part: number, total: number): string {
    return total > 0 ? `${Math.round((part / total) * 100)} %` : '—';
}

export function StatusBadge({ status }: { status: CampaignStatus }) {
    const s = CAMPAIGN_STATUS[status];
    return <span className={`rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ${s.className}`}>{s.label}</span>;
}
