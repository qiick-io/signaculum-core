import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import Heading from '@/components/heading';
import { PageLayout } from '@/components/layout/page-layout';
import {
    OrganizationViewTabs,
    type OrganizationView,
} from '@/components/organizations/organization-view-tabs';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import {
    index as organizationsIndex,
    show as organizationsShow,
} from '@/routes/organizations';
import type { BreadcrumbItem } from '@/types';

type OrganizationSettingsProps = {
    organization: {
        id: string;
        name: string;
        slug: string;
    };
    can: {
        update: boolean;
        delete: boolean;
    };
    view: OrganizationView;
};

/**
 * Organisation → Settings view: name/slug and delete.
 */
export default function OrganizationSettings({
    organization,
    can,
    view,
}: OrganizationSettingsProps) {
    const { t } = useTranslation();
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('organizations.breadcrumb'),
            href: organizationsIndex(),
        },
        {
            title: organization.name,
            href: organizationsShow(organization.slug),
        },
        {
            title: t('organizations.views.settings'),
            href: organizationsShow(organization.slug),
        },
    ];

    const confirmDelete = () => {
        setIsDeleting(true);
        router.delete(
            OrganizationController.destroy.url({
                organization: organization.slug,
            }),
            {
                onFinish: () => {
                    setIsDeleting(false);
                    setIsDeleteOpen(false);
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head
                title={t('organizations.settings.head', {
                    name: organization.name,
                })}
            />

            <h1 className="sr-only">
                {t('organizations.settings.head', { name: organization.name })}
            </h1>

            <OrganizationViewTabs slug={organization.slug} view={view} />

            <PageLayout scrollContent>
                <div className="space-y-10">
                    <Heading
                        title={t('organizations.settings.title')}
                        description={t('organizations.settings.description')}
                    />

                    {can.update ? (
                        <Form
                            {...OrganizationController.update.form({
                                organization: organization.slug,
                            })}
                            options={{ preserveScroll: true }}
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
                                            defaultValue={organization.name}
                                            required
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
                                            defaultValue={organization.slug}
                                            required
                                        />
                                        <FieldError>{errors.slug}</FieldError>
                                    </Field>

                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="w-fit"
                                    >
                                        {t('common.save')}
                                    </Button>
                                </FieldGroup>
                            )}
                        </Form>
                    ) : null}

                    {can.delete ? (
                        <div className="space-y-3">
                            <Heading
                                variant="small"
                                title={t('organizations.danger.title')}
                                description={t(
                                    'organizations.danger.description',
                                )}
                            />
                            <Button
                                type="button"
                                variant="destructive"
                                className="w-fit"
                                onClick={() => setIsDeleteOpen(true)}
                            >
                                {t('common.delete')}
                            </Button>
                        </div>
                    ) : null}
                </div>
            </PageLayout>

            <ConfirmDestructiveDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                title={t('organizations.danger.confirmTitle')}
                description={t('organizations.danger.confirmDescription')}
                confirmLabel={t('common.delete')}
                typedConfirmation={organization.name}
                confirming={isDeleting}
                onConfirm={confirmDelete}
            />
        </AppLayout>
    );
}
