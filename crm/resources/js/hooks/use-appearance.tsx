import { router } from '@inertiajs/react';
import { useCallback, useSyncExternalStore } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

export type Theme = 'selva' | 'grafito' | 'papel' | 'salvia' | 'marea' | 'ciruela' | 'arcilla' | 'glicina';

export const DEFAULT_THEME: Theme = 'selva';

/** Same order and names as App\Support\Themes. Colours live in resources/css/themes.css. */
export const THEMES: { value: Theme; label: string; description: string }[] = [
    { value: 'selva', label: 'Selva', description: 'Verde bosque de la feria' },
    { value: 'grafito', label: 'Grafito', description: 'Gris neutro' },
    { value: 'papel', label: 'Papel', description: 'Crema cálido' },
    { value: 'salvia', label: 'Salvia', description: 'Verde gris' },
    { value: 'marea', label: 'Marea', description: 'Azul de agua' },
    { value: 'ciruela', label: 'Ciruela', description: 'Malva suave' },
    { value: 'arcilla', label: 'Arcilla', description: 'Terracota tostada' },
    { value: 'glicina', label: 'Glicina', description: 'Violeta azulado' },
];

const APPEARANCES: Appearance[] = ['light', 'dark', 'system'];

const isTheme = (value: unknown): value is Theme => THEMES.some((t) => t.value === value);
const isAppearance = (value: unknown): value is Appearance => APPEARANCES.includes(value as Appearance);

const read = (key: string): string | null => {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
};

const write = (key: string, value: string) => {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Private windows can refuse storage; the account still keeps the choice.
    }
};

let state: { theme: Theme; appearance: Appearance } = { theme: DEFAULT_THEME, appearance: 'system' };
const listeners = new Set<() => void>();

const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

const apply = () => {
    const isDark = state.appearance === 'dark' || (state.appearance === 'system' && mediaQuery.matches);

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.setAttribute('data-theme', state.theme);
};

const setState = (next: Partial<typeof state>) => {
    state = { ...state, ...next };
    write('theme', state.theme);
    write('appearance', state.appearance);
    apply();
    listeners.forEach((listener) => listener());
};

/**
 * Adopts the theme saved on the signed-in account. Runs on every Inertia page, so it also picks
 * up the account's choice right after logging in on a new device.
 */
const adoptAccount = (props: Record<string, unknown> | undefined) => {
    const user = (props?.auth as { user?: { theme?: unknown; appearance?: unknown } | null } | undefined)?.user;

    // While a save is in flight the page may still carry the previous choice.
    if (!user || saving > 0) {
        return;
    }

    const next: Partial<typeof state> = {};
    if (isTheme(user.theme) && user.theme !== state.theme) {
        next.theme = user.theme;
    }
    if (isAppearance(user.appearance) && user.appearance !== state.appearance) {
        next.appearance = user.appearance;
    }
    if (Object.keys(next).length > 0) {
        setState(next);
    }
};

export function initializeTheme() {
    const theme = read('theme');
    const appearance = read('appearance');

    state = {
        theme: isTheme(theme) ? theme : DEFAULT_THEME,
        appearance: isAppearance(appearance) ? appearance : 'system',
    };
    apply();

    mediaQuery.addEventListener('change', apply);
    router.on('navigate', (event) => adoptAccount(event.detail.page.props));
}

const subscribe = (listener: () => void) => {
    listeners.add(listener);

    return () => listeners.delete(listener);
};

let saving = 0;

/** Saves on the account, so it follows the user to the phone, tablet and computer. */
const persist = (data: Partial<typeof state>) => {
    saving++;
    router.patch('/settings/appearance', data, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => saving--,
    });
};

export function useAppearance() {
    const appearance = useSyncExternalStore(subscribe, () => state.appearance);
    const theme = useSyncExternalStore(subscribe, () => state.theme);

    const updateAppearance = useCallback((mode: Appearance) => {
        setState({ appearance: mode });
        persist({ appearance: mode });
    }, []);

    const updateTheme = useCallback((value: Theme) => {
        setState({ theme: value });
        persist({ theme: value });
    }, []);

    return { appearance, updateAppearance, theme, updateTheme };
}
