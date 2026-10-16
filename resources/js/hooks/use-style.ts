import { useSyncExternalStore } from 'react';

/** Visual styles ("Gaya tampilan"). Tokens live in resources/css/app.css under [data-style]. */
export const STYLES = ['garage', 'showroom', 'neon'] as const;

export type Style = (typeof STYLES)[number];

const listeners = new Set<() => void>();

const readStyle = (): Style => {
    if (typeof document === 'undefined') {
        return STYLES[0];
    }

    const current = document.documentElement.dataset.style as Style | undefined;

    return current && STYLES.includes(current) ? current : STYLES[0];
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

/**
 * The server renders <html data-style> from the "style" cookie, so the first paint is already right;
 * this hook only reads it and switches it.
 */
export function useStyle() {
    const style = useSyncExternalStore(subscribe, readStyle, () => STYLES[0]);

    const updateStyle = (next: Style): void => {
        document.documentElement.dataset.style = next;
        document.cookie = `style=${next};path=/;max-age=${365 * 24 * 60 * 60};SameSite=Lax`;
        listeners.forEach((listener) => listener());
    };

    return { style, updateStyle } as const;
}
