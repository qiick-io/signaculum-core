import { Link } from '@inertiajs/react';
import { Mail, Settings, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { index as invitationsIndex } from '@/routes/organizations/invitations';
import {
    settings as organizationsSettings,
    show as organizationsShow,
} from '@/routes/organizations';

export type OrganizationView = 'members' | 'invitations' | 'settings';

type OrganizationViewTabsProps = {
    organizationSlug: string;
    view: OrganizationView;
};

/**
 * Organisation view tabs (Members / Invitations / Settings) per app-ui page.md.
 */
export function OrganizationViewTabs({
    organizationSlug,
    view,
}: OrganizationViewTabsProps) {
    const { t } = useTranslation();

    return (
        <Tabs
            value={view}
            activationMode="manual"
            className="overflow-x-auto border-b px-4"
        >
            <TabsList variant="line" aria-label={t('organizations.viewsLabel')}>
                <TabsTrigger value="members" asChild aria-controls={undefined}>
                    <Link href={organizationsShow(organizationSlug)}>
                        <Users />
                        {t('organizations.views.members')}
                    </Link>
                </TabsTrigger>
                <TabsTrigger
                    value="invitations"
                    asChild
                    aria-controls={undefined}
                >
                    <Link href={invitationsIndex(organizationSlug)}>
                        <Mail />
                        {t('organizations.views.invitations')}
                    </Link>
                </TabsTrigger>
                <TabsTrigger value="settings" asChild aria-controls={undefined}>
                    <Link href={organizationsSettings(organizationSlug)}>
                        <Settings />
                        {t('organizations.views.settings')}
                    </Link>
                </TabsTrigger>
            </TabsList>
        </Tabs>
    );
}
