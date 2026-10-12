import { THEMES, useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';

/**
 * Grid of colour themes. Each preview sets data-theme on itself, so it is drawn with that
 * theme's own (light) colours from themes.css.
 */
export default function ThemePicker() {
    const { theme, updateTheme } = useAppearance();

    return (
        <div role="radiogroup" aria-label="Tema de color" className="grid grid-cols-2 gap-x-3 gap-y-4 sm:grid-cols-3">
            {THEMES.map((option) => {
                const selected = option.value === theme;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => updateTheme(option.value)}
                        className="group focus-visible:ring-ring rounded-xl text-left focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        {/* The frame uses the page's colours so the selection reads in light and dark mode. */}
                        <div
                            className={cn(
                                'relative rounded-xl border-2 p-0.5 transition-[border-color,transform] duration-150 ease-out group-active:scale-[0.98]',
                                selected ? 'border-foreground' : 'group-hover:border-muted-foreground border-transparent',
                            )}
                        >
                            <div data-theme={option.value} className="bg-muted flex h-[76px] gap-2 overflow-hidden rounded-[9px] border p-2">
                                <div className="flex flex-col gap-1.5 pt-0.5">
                                    <span className="bg-primary size-2.5 rounded-full" />
                                    <span className="bg-cta/40 size-2.5 rounded-full" />
                                    <span className="bg-muted-foreground/40 size-2.5 rounded-full" />
                                </div>
                                <div className="bg-card flex flex-1 flex-col gap-1.5 rounded-md p-1.5">
                                    <span className="bg-foreground h-1 w-2/3 rounded-full" />
                                    <div className="flex flex-1 gap-1.5">
                                        <span className="bg-primary w-1/2 rounded" />
                                        <span className="bg-background flex-1 rounded" />
                                    </div>
                                </div>
                            </div>
                            {selected && (
                                <span className="bg-foreground text-background absolute right-2 bottom-2 flex size-5 items-center justify-center rounded-full">
                                    <Check className="size-3" strokeWidth={3} />
                                </span>
                            )}
                        </div>
                        <p className="mt-1.5 text-sm font-medium">{option.label}</p>
                        <p className="text-muted-foreground text-xs">{option.description}</p>
                    </button>
                );
            })}
        </div>
    );
}
