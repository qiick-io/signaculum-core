import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editInstance } from '@/routes/instance';
import type { BreadcrumbItem, InstanceSettingsForm } from '@/types';

type GeneralInstanceSettings = Pick<
    InstanceSettingsForm,
    'app_name' | 'description' | 'report_bug_url'
>;

type Props = {
    instance: GeneralInstanceSettings;
};

function buildFormState(instance: GeneralInstanceSettings) {
    return {
        app_name: instance.app_name ?? '',
        description: instance.description ?? '',
        report_bug_url: instance.report_bug_url ?? '',
    };
}

/**
 * Settings → General: the instance name and description, and the bug report link of the
 * settings nav. Copied from Excubia's project settings (`pages/settings/project.tsx`),
 * without its Checks and Data retention sections.
 */
export default function InstanceSettingsPage({ instance }: Props) {
    const { t } = useTranslation();
    const [form, setForm] = useState(() => buildFormState(instance));

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('settings.instance.breadcrumb'),
            href: editInstance(),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.instance.head')} />

            <h1 className="sr-only">{t('settings.instance.head')}</h1>

            <SettingsLayout>
                <Form
                    {...InstanceSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-12"
                    noValidate
                >
                    {({ processing, recentlySuccessful, errors }) => (
                        <>
                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t('settings.instance.generalTitle')}
                                    description={t(
                                        'settings.instance.generalDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    <Field
                                        data-invalid={
                                            errors.app_name ? true : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="app_name">
                                            {t('settings.instance.name')}
                                        </FieldLabel>
                                        <Input
                                            id="app_name"
                                            name="app_name"
                                            value={form.app_name}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    app_name:
                                                        event.target.value,
                                                }))
                                            }
                                            aria-invalid={
                                                errors.app_name
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.app_name
                                                    ? 'instance-name-error'
                                                    : undefined
                                            }
                                        />
                                        <FieldError id="instance-name-error">
                                            {errors.app_name}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.description
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="description">
                                            {t('settings.instance.description')}
                                        </FieldLabel>
                                        <Textarea
                                            id="description"
                                            name="description"
                                            value={form.description}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    description:
                                                        event.target.value,
                                                }))
                                            }
                                            aria-invalid={
                                                errors.description
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.description
                                                    ? 'instance-description-error'
                                                    : undefined
                                            }
                                        />
                                        <FieldError id="instance-description-error">
                                            {errors.description}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                            </div>

                            <Separator />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.instance.reportingTitle',
                                    )}
                                    description={t(
                                        'settings.instance.reportingDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    <Field
                                        data-invalid={
                                            errors.report_bug_url
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="report_bug_url">
                                            {t(
                                                'settings.instance.reportBugUrl',
                                            )}
                                        </FieldLabel>
                                        <Input
                                            id="report_bug_url"
                                            name="report_bug_url"
                                            type="url"
                                            value={form.report_bug_url}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    report_bug_url:
                                                        event.target.value,
                                                }))
                                            }
                                            aria-invalid={
                                                errors.report_bug_url
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.report_bug_url
                                                    ? 'instance-report-bug-url-error'
                                                    : 'instance-report-bug-url-hint'
                                            }
                                        />
                                        <FieldDescription id="instance-report-bug-url-hint">
                                            {t(
                                                'settings.instance.reportBugUrlHint',
                                            )}
                                        </FieldDescription>
                                        <FieldError id="instance-report-bug-url-error">
                                            {errors.report_bug_url}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                            </div>

                            <SettingsFormActions
                                processing={processing}
                                recentlySuccessful={recentlySuccessful}
                                data-test="instance-settings-save"
                            />
                        </>
                    )}
                </Form>
            </SettingsLayout>
        </AppLayout>
    );
}
