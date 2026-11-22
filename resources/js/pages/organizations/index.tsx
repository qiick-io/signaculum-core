import { Form, Head, Link } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import Heading from '@/components/heading';
import { PageLayout } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import {
    index as organizationsIndex,
    show as organizationsShow,
} from '@/routes/organizations';
import type { BreadcrumbItem } from '@/types';

type OrganizationRow = {
    id: string;
    name: string;
    slug: string;
};

/**
 * Organisations the signed-in user belongs to (instance admins see every organisation).
 */
export default function OrganizationsIndex({
    organizations,
}: {
    organizations: OrganizationRow[];
}) {
    const { t } = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('organizations.breadcrumb'),
            href: organizationsIndex(),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('organizations.head')} />

            <h1 className="sr-only">{t('organizations.head')}</h1>

            <PageLayout scrollContent>
                <div className="space-y-8">
                    <Heading
                        title={t('organizations.title')}
                        description={t('organizations.description')}
                    />

                    <Form
                        {...OrganizationController.store.form()}
                        className="max-w-xl space-y-4"
                        noValidate
                    >
                        {({ processing, errors }) => (
                            <FieldGroup className="gap-4">
                                <Field
                                    data-invalid={
                                        errors.name ? true : undefined
                                    }
                                >
                                    <FieldLabel htmlFor="name">
                                        {t('common.name')}
                                    </FieldLabel>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        aria-invalid={
                                            errors.name ? true : undefined
                                        }
                                    />
                                    <FieldError>{errors.name}</FieldError>
                                </Field>

                                <Field
                                    data-invalid={
                                        errors.slug ? true : undefined
                                    }
                                >
                                    <FieldLabel htmlFor="slug">
                                        {t('organizations.slug')}
                                    </FieldLabel>
                                    <Input
                                        id="slug"
                                        name="slug"
                                        aria-invalid={
                                            errors.slug ? true : undefined
                                        }
                                    />
                                    <FieldError>{errors.slug}</FieldError>
                                </Field>

                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="w-fit"
                                >
                                    <Plus />
                                    {t('organizations.create')}
                                </Button>
                            </FieldGroup>
                        )}
                    </Form>

                    {organizations.length === 0 ? (
                        <PageEmptyState
                            icon={Building2}
                            title={t('organizations.emptyTitle')}
                            description={t('organizations.emptyDescription')}
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('common.name')}</TableHead>
                                    <TableHead>
                                        {t('organizations.slug')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {organizations.map((organization) => (
                                    <TableRow key={organization.id}>
                                        <TableCell>
                                            <Link
                                                href={organizationsShow(
                                                    organization.slug,
                                                )}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {organization.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {organization.slug}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
