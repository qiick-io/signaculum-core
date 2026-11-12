import { Head, router, usePage } from '@inertiajs/react';
import { LogOut, Monitor } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import Heading from '@/components/heading';
import { TablePanel } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatRelativeTime } from '@/lib/relative-time';
import {
    destroy,
    destroyOthers,
    index as sessionsIndex,
} from '@/routes/sessions';
import type { BreadcrumbItem, BrowserSession } from '@/types';

/**
 * Settings → Sessions: every browser signed in to this account, with sign-out per
 * browser and for all other browsers. The table follows app-ui `list-page.md`
 * (TablePanel, row actions in the last column) inside the settings pane.
 */
export default function Sessions({ sessions }: { sessions: BrowserSession[] }) {
    const { t } = useTranslation();
    const { locale } = usePage().props;
    const [sessionToRevoke, setSessionToRevoke] =
        useState<BrowserSession | null>(null);
    const [isRevokingOthers, setIsRevokingOthers] = useState(false);
    const [isConfirming, setIsConfirming] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('settings.sessions.breadcrumb'), href: sessionsIndex() },
    ];

    const hasOtherSessions = sessions.some(
        (session) => !session.isCurrentDevice,
    );

    const confirmRevoke = () => {
        setIsConfirming(true);

        const visit = sessionToRevoke
            ? destroy(sessionToRevoke.publicId)
            : destroyOthers();

        router.visit(visit, {
            preserveScroll: true,
            onFinish: () => {
                setIsConfirming(false);
                setSessionToRevoke(null);
                setIsRevokingOthers(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.sessions.head')} />

            <h1 className="sr-only">{t('settings.sessions.head')}</h1>

            <SettingsLayout wide>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title={t('settings.sessions.title')}
                        description={t('settings.sessions.description')}
                    />

                    {sessions.length === 0 ? (
                        <PageEmptyState
                            compact
                            icon={Monitor}
                            title={t('settings.sessions.emptyTitle')}
                            description={t(
                                'settings.sessions.emptyDescription',
                            )}
                        />
                    ) : (
                        <TablePanel>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t(
                                                'settings.sessions.columns.browser',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'settings.sessions.columns.ipAddress',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'settings.sessions.columns.lastActive',
                                            )}
                                        </TableHead>
                                        <TableHead className="w-10">
                                            <span className="sr-only">
                                                {t('common.actions')}
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {sessions.map((session) => (
                                        <TableRow key={session.publicId}>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <Monitor
                                                        className="size-4 text-muted-foreground"
                                                        aria-hidden
                                                    />
                                                    <span className="font-medium">
                                                        {t(
                                                            'settings.sessions.browserOnPlatform',
                                                            {
                                                                browser:
                                                                    session.browser,
                                                                platform:
                                                                    session.platform,
                                                            },
                                                        )}
                                                    </span>
                                                    {session.isCurrentDevice ? (
                                                        <Badge variant="success">
                                                            {t(
                                                                'settings.sessions.thisDevice',
                                                            )}
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                            </TableCell>
                                            <TableCell className="font-mono text-xs text-muted-foreground">
                                                {session.ipAddress ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {formatRelativeTime(
                                                    session.lastActiveAt,
                                                    locale,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {session.isCurrentDevice ? null : (
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon-sm"
                                                                aria-label={t(
                                                                    'settings.sessions.signOut',
                                                                )}
                                                                onClick={() =>
                                                                    setSessionToRevoke(
                                                                        session,
                                                                    )
                                                                }
                                                            >
                                                                <LogOut />
                                                            </Button>
                                                        </TooltipTrigger>
                                                        <TooltipContent>
                                                            {t(
                                                                'settings.sessions.signOut',
                                                            )}
                                                        </TooltipContent>
                                                    </Tooltip>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </TablePanel>
                    )}

                    {hasOtherSessions ? (
                        <div className="flex justify-end">
                            <Button
                                variant="destructive-outline"
                                onClick={() => setIsRevokingOthers(true)}
                                data-test="sign-out-other-sessions"
                            >
                                <LogOut />
                                {t('settings.sessions.signOutOthers')}
                            </Button>
                        </div>
                    ) : null}
                </div>

                <ConfirmDestructiveDialog
                    open={sessionToRevoke !== null || isRevokingOthers}
                    onOpenChange={(isOpen) => {
                        if (!isOpen) {
                            setSessionToRevoke(null);
                            setIsRevokingOthers(false);
                        }
                    }}
                    title={
                        isRevokingOthers
                            ? t('settings.sessions.confirm.othersTitle')
                            : t('settings.sessions.confirm.title')
                    }
                    description={
                        isRevokingOthers
                            ? t('settings.sessions.confirm.othersDescription')
                            : t('settings.sessions.confirm.description')
                    }
                    confirmLabel={t('settings.sessions.signOut')}
                    confirming={isConfirming}
                    onConfirm={confirmRevoke}
                />
            </SettingsLayout>
        </AppLayout>
    );
}
