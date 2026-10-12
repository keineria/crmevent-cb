import { Appearance, useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { LucideIcon, Monitor, Moon, Sun } from 'lucide-react';
import { HTMLAttributes } from 'react';

export default function AppearanceToggleTab({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
    const { appearance, updateAppearance } = useAppearance();

    const tabs: { value: Appearance; icon: LucideIcon; label: string }[] = [
        { value: 'system', icon: Monitor, label: 'Automático' },
        { value: 'light', icon: Sun, label: 'Claro' },
        { value: 'dark', icon: Moon, label: 'Oscuro' },
    ];

    return (
        <div role="radiogroup" aria-label="Modo" className={cn('bg-muted inline-flex gap-1 rounded-lg p-1', className)} {...props}>
            {tabs.map(({ value, icon: Icon, label }) => (
                <button
                    key={value}
                    type="button"
                    role="radio"
                    aria-checked={appearance === value}
                    onClick={() => updateAppearance(value)}
                    className={cn(
                        'focus-visible:ring-ring flex items-center rounded-md px-3.5 py-1.5 transition-colors duration-150 focus-visible:ring-2 focus-visible:outline-none',
                        appearance === value
                            ? 'bg-card text-foreground font-medium shadow-xs'
                            : 'text-muted-foreground hover:bg-background/60 hover:text-foreground',
                    )}
                >
                    <Icon className="-ml-1 h-4 w-4" />
                    <span className="ml-1.5 text-sm">{label}</span>
                </button>
            ))}
        </div>
    );
}
