import { useEffect } from 'react';

export function useEscapeKey(active, onEscape) {
    useEffect(() => {
        if (!active) {
            return undefined;
        }

        const handler = (event) => {
            if (event.key === 'Escape') {
                onEscape?.();
            }
        };

        window.addEventListener('keydown', handler);

        return () => window.removeEventListener('keydown', handler);
    }, [active, onEscape]);
}
