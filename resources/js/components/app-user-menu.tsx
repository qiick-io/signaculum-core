import { router, usePage } from '@inertiajs/react';
import { NavUser } from '@/components/nav-user';
import { UserMenuContent } from '@/components/user-menu-content';
import { useAppearance } from '@/hooks/use-appearance';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

/**
 * Signaculum's user menu on the registry `nav-user` item (app-ui): profile settings,
 * appearance and logout. The registry components are generic; this wires them to
 * Signaculum's routes and hooks.
 */
export function AppUserMenuContent({ user }: { user: User }) {
    const cleanup = useMobileNavigation();
    const { appearance, updateAppearance } = useAppearance();

    return (
        <UserMenuContent
            user={user}
            settingsHref={edit()}
            logoutHref={logout()}
            onNavigate={cleanup}
            // Drop the Inertia page cache so the next user never sees this one's pages.
            onLogout={() => router.flushAll()}
            appearance={{ value: appearance, onChange: updateAppearance }}
        />
    );
}

/**
 * The sidebar user menu for the signed-in user.
 */
export function AppNavUser() {
    const { auth } = usePage().props;

    if (!auth.user) {
        return null;
    }

    return (
        <NavUser user={auth.user}>
            <AppUserMenuContent user={auth.user} />
        </NavUser>
    );
}
