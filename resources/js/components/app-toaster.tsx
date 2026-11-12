import { Toaster } from '@/components/ui/sonner';
import { useAppearance } from '@/hooks/use-appearance';
import { useFlashToast } from '@/hooks/use-flash-toast';

/**
 * The app's one Toaster (app-ui `docs/notifications.md`): position, offset, colours and
 * durations come from the `@qiick/sonner` item; the app only passes its appearance and
 * wires session flash toasts.
 */
export function AppToaster() {
    const { resolvedAppearance } = useAppearance();

    useFlashToast();

    return <Toaster theme={resolvedAppearance} />;
}
