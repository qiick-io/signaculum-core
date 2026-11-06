import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import InstanceLocalizationSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceLocalizationSettingsController';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editLocalization } from '@/routes/instance/localization';
import type { BreadcrumbItem, InstanceSettingsForm } from '@/types';

type LocalizationInstanceSettings = Pick<
    InstanceSettingsForm,
    'default_language'
>;

type Props = {
    instance: LocalizationInstanceSettings;
    availableLocales: Record<string, string>;
};

/**
 * Settings → Localization: the default UI language, the login page included.
 */
export default function InstanceLocalizationSettingsPage({
    instance,
    availableLocales,
}: Props) {
    const { t } = useTranslation();
    const [defaultLanguage, setDefaultLanguage] = useState(
        instance.default_language,
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('settings.instance.localization.breadcrumb'),
            href: editLocalization(),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.instance.localization.head')} />

            <h1 className="sr-only">
                {t('settings.instance.localization.head')}
            </h1>

            <SettingsLayout>
                <Form
                    {...InstanceLocalizationSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-12"
                    noValidate
                >
                    {({ processing, recentlySuccessful, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="default_language"
                                value={defaultLanguage}
                            />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.instance.localization.title',
                                    )}
                                    description={t(
                                        'settings.instance.localization.description',
                                    )}
                                />

                                <FieldGroup>
                                    <Field
                                        data-invalid={
                                            errors.default_language
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="default_language">
                                            {t(
                                                'settings.instance.defaultLanguage',
                                            )}
                                        </FieldLabel>
                                        <Select
                                            value={defaultLanguage}
                                            onValueChange={setDefaultLanguage}
                                        >
                                            <SelectTrigger
                                                id="default_language"
                                                className="w-full"
                                                aria-invalid={
                                                    errors.default_language
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.default_language
                                                        ? 'default-language-error'
                                                        : undefined
                                                }
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(
                                                    availableLocales,
                                                ).map(([code, label]) => (
                                                    <SelectItem
                                                        key={code}
                                                        value={code}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FieldError id="default-language-error">
                                            {errors.default_language}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                            </div>

                            <SettingsFormActions
                                processing={processing}
                                recentlySuccessful={recentlySuccessful}
                                data-test="instance-localization-settings-save"
                            />
                        </>
                    )}
                </Form>
            </SettingsLayout>
        </AppLayout>
    );
}
