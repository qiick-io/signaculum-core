import { Head, Link, usePage } from '@inertiajs/react';
import { FileQuestion, Lock, ServerCrash, Wrench } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
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

type ErrorPageProps = {
    status: 403 | 404 | 500 | 503;
    homeUrl: string;
    /** A known 403 cause the user may know about (`RenderHttpErrorResponse`). */
    reason?: 'secondFactorUnavailable' | null;
};

type ErrorCopy = {
    icon: LucideIcon;
    key:
        | 'forbidden'
        | 'notFound'
        | 'serverError'
        | 'serviceUnavailable'
        | 'secondFactorUnavailable';
};

/**
 * Shared 403 / 404 / 500 / 503 page (app-ui `error-pages.md`).
 * App shell when signed in; auth layout when not.
 */
export default function ErrorPage({ status, homeUrl, reason }: ErrorPageProps) {
    const { t } = useTranslation();
    const page = usePage();
    const isSignedIn = Boolean(
        (page.props.auth as { user?: unknown } | undefined)?.user,
    );

    const copy = reason ? { icon: Lock, key: reason } : copyForStatus(status);
    const Icon = copy.icon;
    const translationRoot = `errors.${copy.key}`;

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t(`${translationRoot}.title`),
            href: homeUrl,
        },
    ];

    const body = (
        <>
            <Head title={t(`${translationRoot}.head`)} />
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
                            <Icon />
                        </EmptyMedia>
                        <EmptyTitle>
                            <h1>{t(`${translationRoot}.title`)}</h1>
                        </EmptyTitle>
                        <EmptyDescription>
                            {t(`${translationRoot}.description`)}
                        </EmptyDescription>
                    </EmptyHeader>
                    <EmptyContent>
                        <div className="flex flex-wrap justify-center gap-2">
                            <Button asChild variant="default">
                                <Link href={homeUrl}>
                                    {t(
                                        isSignedIn
                                            ? `${translationRoot}.goHome`
                                            : `${translationRoot}.signIn`,
                                    )}
                                </Link>
                            </Button>
                            {isSignedIn ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => window.history.back()}
                                >
                                    {t('common.back')}
                                </Button>
                            ) : null}
                        </div>
                    </EmptyContent>
                </Empty>
            </PageLayout>
        </>
    );

    if (isSignedIn) {
        return <AppLayout breadcrumbs={breadcrumbs}>{body}</AppLayout>;
    }

    return (
        <AuthLayout
            title={t(`${translationRoot}.title`)}
            description={t(`${translationRoot}.description`)}
        >
            {body}
        </AuthLayout>
    );
}

function copyForStatus(status: ErrorPageProps['status']): ErrorCopy {
    switch (status) {
        case 403:
            return { icon: Lock, key: 'forbidden' };
        case 500:
            return { icon: ServerCrash, key: 'serverError' };
        case 503:
            return { icon: Wrench, key: 'serviceUnavailable' };
        default:
            return { icon: FileQuestion, key: 'notFound' };
    }
}
