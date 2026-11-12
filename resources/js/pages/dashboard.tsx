import { Head, Link, usePage } from '@inertiajs/react';
import { AppWindow } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { PageLayout } from '@/components/layout/page-layout';
import { StatCard } from '@/components/stat-card';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatRelativeTime } from '@/lib/relative-time';
import { dashboard } from '@/routes';
import { index as connectedApplicationsIndex } from '@/routes/connected-applications';
import { edit as editSecurity } from '@/routes/security';
import { index as sessionsIndex } from '@/routes/sessions';
import type { BreadcrumbItem, ConnectedApplication } from '@/types';

type HomeProps = {
    activeSessionCount: number;
    connectedApplications: ConnectedApplication[];
    hasTwoFactor: boolean;
    passkeyCount: number;
};

/**
 * Home: where a direct login lands. Key numbers of the account as StatCards and the
 * connected applications in a flat card (app-ui `dashboard.md`).
 */
export default function Dashboard({
    activeSessionCount,
    connectedApplications,
    hasTwoFactor,
    passkeyCount,
}: HomeProps) {
    const { t } = useTranslation();
    const { locale } = usePage().props;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('home.breadcrumb'), href: dashboard() },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('home.head')} />

            <h1 className="sr-only">{t('home.head')}</h1>

            <PageLayout scrollContent>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-12">
                    <StatCard
                        className="xl:col-span-3"
                        label={t('home.stats.sessions')}
                        value={String(activeSessionCount)}
                        headline={
                            <Link
                                href={sessionsIndex()}
                                className="underline-offset-4 hover:underline"
                            >
                                {t('home.stats.sessionsHeadline')}
                            </Link>
                        }
                    />
                    <StatCard
                        className="xl:col-span-3"
                        label={t('home.stats.applications')}
                        value={String(connectedApplications.length)}
                        headline={t('home.stats.applicationsHeadline')}
                    />
                    <StatCard
                        className="xl:col-span-3"
                        label={t('home.stats.twoFactor')}
                        value={
                            hasTwoFactor
                                ? t('home.stats.twoFactorOn')
                                : t('home.stats.twoFactorOff')
                        }
                        headline={
                            <Link
                                href={editSecurity()}
                                className="underline-offset-4 hover:underline"
                            >
                                {hasTwoFactor
                                    ? t('home.stats.twoFactorManage')
                                    : t('home.stats.twoFactorEnable')}
                            </Link>
                        }
                    />
                    <StatCard
                        className="xl:col-span-3"
                        label={t('home.stats.passkeys')}
                        value={String(passkeyCount)}
                        headline={t('home.stats.passkeysHeadline')}
                    />

                    <Card className="xl:col-span-12">
                        <CardHeader className="pb-3">
                            <CardTitle>
                                {t('home.applications.title')}
                            </CardTitle>
                            <CardDescription>
                                {t('home.applications.description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {connectedApplications.length === 0 ? (
                                <PageEmptyState
                                    compact
                                    icon={AppWindow}
                                    title={t(
                                        'settings.connectedApplications.emptyTitle',
                                    )}
                                    description={t(
                                        'settings.connectedApplications.emptyDescription',
                                    )}
                                />
                            ) : (
                                <ul className="max-h-64 divide-y overflow-auto">
                                    {connectedApplications.map(
                                        (application) => (
                                            <li
                                                key={application.clientId}
                                                className="flex items-center justify-between gap-4 py-2"
                                            >
                                                <span className="truncate font-medium">
                                                    {application.name}
                                                </span>
                                                <span className="shrink-0 text-sm text-muted-foreground">
                                                    {formatRelativeTime(
                                                        application.lastAuthorizedAt,
                                                        locale,
                                                    )}
                                                </span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            )}
                            {connectedApplications.length > 0 ? (
                                <Link
                                    href={connectedApplicationsIndex()}
                                    className="mt-3 inline-block text-sm underline-offset-4 hover:underline"
                                >
                                    {t('home.applications.manage')}
                                </Link>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
