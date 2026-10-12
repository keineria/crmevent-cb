import { cn } from '@/lib/utils';

interface Props {
    template: string;
    primary: string;
    accent: string;
    className?: string;
}

/** A small drawing of a certificate layout, so the designs can be told apart at a glance. */
export default function TemplateThumb({ template, primary, accent, className }: Props) {
    const lines = (
        <>
            <rect x="0" y="0" width="34" height="2" rx="1" fill={template === 'selva' || template === 'geometrico' ? accent : primary} />
            <rect x="0" y="7" width="58" height="6" rx="1.5" fill="#1C1A17" />
            <rect x="0" y="17" width="10" height="1.5" fill={accent} />
            <rect x="0" y="22" width="70" height="2" rx="1" fill="#C9C3B8" />
            <rect x="0" y="26" width="52" height="2" rx="1" fill="#C9C3B8" />
        </>
    );

    return (
        <svg viewBox="0 0 148 105" className={cn('w-full rounded-md border bg-white', className)} role="img" aria-label={`Diseño ${template}`}>
            {template === 'selva' && (
                <>
                    <rect x="0" y="0" width="39" height="105" fill={primary} />
                    <rect x="0" y="0" width="39" height="2" fill={accent} />
                    <rect x="7" y="8" width="25" height="12" rx="1.5" fill="#FFFFFF" />
                    <rect x="7" y="78" width="12" height="12" fill="#FFFFFF" />
                    <g transform="translate(49 24)">{lines}</g>
                </>
            )}
            {template === 'minimal' && (
                <>
                    <rect x="0" y="101" width="148" height="4" fill={primary} />
                    <rect x="0" y="101" width="30" height="4" fill={accent} />
                    <rect x="12" y="10" width="22" height="8" rx="1.5" fill="#E4DED3" />
                    <g transform="translate(12 32)">{lines}</g>
                </>
            )}
            {template === 'clasico' && (
                <>
                    <rect x="4" y="4" width="140" height="97" fill="none" stroke={primary} strokeWidth="2" />
                    <rect x="7" y="7" width="134" height="91" fill="none" stroke={accent} strokeWidth="0.6" />
                    <rect x="62" y="12" width="24" height="8" rx="1.5" fill="#E4DED3" />
                    <g transform="translate(39 30)">{lines}</g>
                    <circle cx="125" cy="84" r="8" fill={accent} />
                </>
            )}
            {template === 'geometrico' && (
                <>
                    <circle cx="140" cy="0" r="42" fill={primary} />
                    <circle cx="146" cy="44" r="15" fill={accent} />
                    <circle cx="0" cy="105" r="20" fill={accent} opacity="0.18" />
                    <rect x="12" y="10" width="22" height="8" rx="1.5" fill="#E4DED3" />
                    <g transform="translate(12 32)">{lines}</g>
                </>
            )}
            {template === 'propio' && (
                <>
                    <rect x="6" y="6" width="136" height="93" rx="3" fill="none" stroke="#C9C3B8" strokeWidth="1.2" strokeDasharray="4 3" />
                    <path
                        d="M58 46 l10 -10 l10 10 M68 36 v22"
                        fill="none"
                        stroke={primary}
                        strokeWidth="2.5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    />
                    <rect x="44" y="68" width="60" height="5" rx="1.5" fill="#1C1A17" />
                    <rect x="54" y="78" width="40" height="2" rx="1" fill={accent} />
                </>
            )}
        </svg>
    );
}
