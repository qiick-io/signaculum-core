import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from '@/lib/toast';
import type { FlashToast } from '@/types/ui';

/**
 * Surfaces Inertia `flash.toast` payloads through the shared `@/lib/toast` channels.
 */
export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const flashToast = flash?.toast as FlashToast | undefined;

            if (!flashToast) {
                return;
            }

            toast[flashToast.type](flashToast.message);
        });
    }, []);
}
