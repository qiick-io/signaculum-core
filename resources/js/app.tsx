import { createInertiaApp, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AppErrorBoundary } from '@/components/app-error-boundary';
import { AppToaster } from '@/components/app-toaster';
import { FocusAfterNavigation } from '@/components/focus-after-navigation';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeAccessibilityPreferences } from '@/hooks/use-accessibility-preferences';
import { initializeTheme } from '@/hooks/use-appearance';
import AuthLayout from '@/layouts/auth-layout';
import { initI18n } from '@/lib/i18n';
import { applyThemeCss } from '@/lib/theme-css';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

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

// The server renders the theme CSS once per full page load. Organisation brand colours
// apply only on sign-in and consent, so re-apply the shared theme on every Inertia visit:
// otherwise the organisation colour would stay on the dashboard after signing in.
router.on('navigate', (event) => {
    const themeCss = event.detail.page.props.projectAppearance?.themeCss;

    if (themeCss) {
        applyThemeCss(themeCss);
    }
});
