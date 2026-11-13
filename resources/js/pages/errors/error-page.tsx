import { Head, Link, usePage } from '@inertiajs/react';
import { FileQuestion, Lock, ServerCrash, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { PageLayout } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import type { BreadcrumbItem } from '@/types';

type Props = {
    status: 403 | 404 | 500 | 503;
    homeUrl: string;
};

const statusIcons: Record<Props['status'], ReactNode> = {
    403: <Lock />,
    404: <FileQuestion />,
    500: <ServerCrash />,
    503: <TriangleAlert />,
};

/**
 * Shared Inertia error page for 403 / 404 / 500 / 503 (app-ui error-pages.md).
 * App shell when signed in; auth layout for guests (404 guests redirect to login first).
 */
export default function ErrorPage({ status, homeUrl }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const isSignedIn = auth.user !== null;
    const keyPrefix = `errors.${status}` as const;

    const title = t(`${keyPrefix}.title`);
    const description = t(`${keyPrefix}.description`);

    const actions = (
        <div className="flex flex-wrap justify-center gap-2">
            {status === 500 || status === 503 ? (
                <Button
                    type="button"
                    variant="default"
                    onClick={() => window.location.reload()}
                >
                    {t(`${keyPrefix}.tryAgain`)}
                </Button>
            ) : (
                <Button asChild variant="default">
                    <Link href={homeUrl}>
                        {isSignedIn
                            ? t(`${keyPrefix}.goHome`)
                            : t(`${keyPrefix}.signIn`)}
                    </Link>
                </Button>
            )}
            {isSignedIn && status !== 500 && status !== 503 ? (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => window.history.back()}
                >
                    {t('common.back')}
                </Button>
            ) : null}
        </div>
    );

    if (!isSignedIn) {
        return (
            <AuthLayout title={title} description={description}>
                <Head title={t(`${keyPrefix}.head`)} />
                <Empty data-test={`error-${status}`}>
                    <EmptyHeader>
                        <p className="text-sm font-medium text-muted-foreground">
                            {status}
                        </p>
                        <EmptyMedia
                            variant="icon"
                            className="bg-primary/10 text-primary"
                        >
                            {statusIcons[status]}
                        </EmptyMedia>
                    </EmptyHeader>
                    <EmptyContent>{actions}</EmptyContent>
                </Empty>
            </AuthLayout>
        );
    }

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title,
            href: homeUrl,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t(`${keyPrefix}.head`)} />

            <PageLayout>
                <Empty data-test={`error-${status}`}>
                    <EmptyHeader>
                        <p className="text-sm font-medium text-muted-foreground">
                            {status}
                        </p>
                        <EmptyMedia
                            variant="icon"
                            className="bg-primary/10 text-primary"
                        >
                            {statusIcons[status]}
                        </EmptyMedia>
                        <EmptyTitle>
                            <h1>{title}</h1>
                        </EmptyTitle>
                        <EmptyDescription>{description}</EmptyDescription>
                    </EmptyHeader>
                    <EmptyContent>{actions}</EmptyContent>
                </Empty>
            </PageLayout>
        </AppLayout>
    );
}
