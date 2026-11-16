import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import OrganizationBrandingController from '@/actions/App/Http/Controllers/OrganizationBrandingController';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import Heading from '@/components/heading';
import { PageLayout } from '@/components/layout/page-layout';
import {
    OrganizationViewTabs,
    type OrganizationView,
} from '@/components/organizations/organization-view-tabs';
import { BrandingFileField } from '@/components/settings/branding-file-field';
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
        brand_color: string | null;
        brand_color_dark: string | null;
        logo_url: string | null;
        logo_dark_url: string | null;
    };
    can: {
        update: boolean;
        delete: boolean;
    };
    view: OrganizationView;
};

/**
 * Organisation → Settings view: name/slug, branding and delete.
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

                    {can.update ? (
                        <div className="space-y-4">
                            <Heading
                                variant="small"
                                title={t(
                                    'organizations.settings.brandingTitle',
                                )}
                                description={t(
                                    'organizations.settings.brandingDescription',
                                )}
                            />

                            <Form
                                {...OrganizationBrandingController.update.form({
                                    organization: organization.slug,
                                })}
                                encType="multipart/form-data"
                                options={{ preserveScroll: true }}
                                className="max-w-xl space-y-4"
                                noValidate
                            >
                                {({ processing, errors }) => (
                                    <FieldGroup className="gap-4">
                                        <Field
                                            data-invalid={
                                                errors.brand_color
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="brand_color">
                                                {t(
                                                    'organizations.settings.brandColor',
                                                )}
                                            </FieldLabel>
                                            <Input
                                                id="brand_color"
                                                name="brand_color"
                                                type="text"
                                                placeholder="#A24A7B"
                                                defaultValue={
                                                    organization.brand_color ??
                                                    ''
                                                }
                                            />
                                            <FieldError>
                                                {errors.brand_color}
                                            </FieldError>
                                        </Field>

                                        <Field
                                            data-invalid={
                                                errors.brand_color_dark
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="brand_color_dark">
                                                {t(
                                                    'organizations.settings.brandColorDark',
                                                )}
                                            </FieldLabel>
                                            <Input
                                                id="brand_color_dark"
                                                name="brand_color_dark"
                                                type="text"
                                                placeholder="#DC77AD"
                                                defaultValue={
                                                    organization.brand_color_dark ??
                                                    ''
                                                }
                                            />
                                            <FieldError>
                                                {errors.brand_color_dark}
                                            </FieldError>
                                        </Field>

                                        <BrandingFileField
                                            name="logo"
                                            label={t(
                                                'organizations.settings.logo',
                                            )}
                                            accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                            savedUrl={organization.logo_url}
                                            error={errors.logo}
                                        />

                                        <BrandingFileField
                                            name="logo_dark"
                                            label={t(
                                                'organizations.settings.logoDark',
                                            )}
                                            accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                            savedUrl={
                                                organization.logo_dark_url
                                            }
                                            error={errors.logo_dark}
                                        />

                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="w-fit"
                                        >
                                            {t(
                                                'organizations.settings.saveBranding',
                                            )}
                                        </Button>
                                    </FieldGroup>
                                )}
                            </Form>
                        </div>
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
