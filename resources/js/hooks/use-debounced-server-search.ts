import { useEffect } from 'react';

const DEFAULT_DEBOUNCE_MS = 350;

/**
 * Debounces a server search visit when the local query differs from the last
 * server filter value. Skips the visit when they already match (mount /
 * pagination remounts) so page resets are avoided.
 */
export function useDebouncedServerSearch(
    search: string,
    serverSearch: string,
    onSearch: (value: string) => void,
    delayMs = DEFAULT_DEBOUNCE_MS,
): void {
    useEffect(() => {
        if (search === serverSearch) {
            return;
        }

        const timer = window.setTimeout(() => {
            onSearch(search);
        }, delayMs);

        return () => {
            window.clearTimeout(timer);
        };
    }, [search, serverSearch, onSearch, delayMs]);
}
