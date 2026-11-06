import { createInertiaApp } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AppErrorBoundary } from '@/components/app-error-boundary';
import { FocusAfterNavigation } from '@/components/focus-after-navigation';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeAccessibilityPreferences } from '@/hooks/use-accessibility-preferences';
import { initializeTheme, useAppearance } from '@/hooks/use-appearance';
import AuthLayout from '@/layouts/auth-layout';
import { initI18n } from '@/lib/i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * The toast host (app-ui `notifications.md`): one Toaster, with the resolved appearance.
 */
function AppToaster() {
    const { resolvedAppearance } = useAppearance();

    return <Toaster theme={resolvedAppearance} />;
}

// Signed-in pages wrap themselves in AppLayout, as in externa-core; the guest pages
// (login, consent screen) keep the starter kit's layout resolver.
void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) =>
        name.startsWith('auth/') || name.startsWith('oauth/')
            ? AuthLayout
            : null,
    strictMode: true,
    withApp(app: ReactNode, { page }) {
        initI18n(
            typeof page.props.locale === 'string' ? page.props.locale : 'en',
        );

        return (
            <AppErrorBoundary>
                <TooltipProvider delayDuration={0}>
                    <FocusAfterNavigation />
                    {app}
                    <AppToaster />
                </TooltipProvider>
            </AppErrorBoundary>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();
initializeAccessibilityPreferences();
