import { Form, Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import InstanceEmailTemplatesController from '@/actions/App/Http/Controllers/Settings/InstanceEmailTemplatesController';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editEmailTemplates } from '@/routes/instance/email-templates';
import type { BreadcrumbItem } from '@/types';

type TemplateFields = {
    subject: string;
    body: string;
    action_label: string;
};

type TemplateType = 'verification' | 'reset' | 'invitation';

type TemplatesByLocale = Record<string, TemplateFields>;

type TemplatesMap = Record<TemplateType, TemplatesByLocale>;

type PlaceholdersMap = Record<TemplateType, string[]>;

type Props = {
    templates: TemplatesMap;
    defaults: TemplatesMap;
    placeholders: PlaceholdersMap;
    availableLocales: Record<string, string>;
};

const TEMPLATE_TYPES: TemplateType[] = [
    'verification',
    'reset',
    'invitation',
];

const PREVIEW_SAMPLES: Record<string, string> = {
    app_name: 'Signaculum',
    user_name: 'Alex Example',
    organization_name: 'Acme',
    url: 'https://signaculum.example/action',
    expire_minutes: '60',
};

function replacePlaceholders(text: string, samples: Record<string, string>) {
    return Object.entries(samples).reduce(
        (result, [name, value]) =>
            result.replaceAll(`{{${name}}}`, value),
        text,
    );
}

function cloneTemplates(templates: TemplatesMap): TemplatesMap {
    return structuredClone(templates);
}

/**
 * Settings → Email templates: edit verification, password-reset and invitation
 * copy per locale, with a live preview and restore-to-default.
 */
export default function InstanceEmailTemplatesPage({
    templates: initialTemplates,
    defaults,
    placeholders,
    availableLocales,
}: Props) {
    const { t } = useTranslation();
    const localeCodes = Object.keys(availableLocales);
    const [templates, setTemplates] = useState(() =>
        cloneTemplates(initialTemplates),
    );
    const [activeType, setActiveType] = useState<TemplateType>('verification');
    const [activeLocale, setActiveLocale] = useState(
        localeCodes[0] ?? 'en',
    );

    const current = templates[activeType][activeLocale];
    const currentDefaults = defaults[activeType][activeLocale];
    const currentPlaceholders = placeholders[activeType] ?? [];

    const previewSubject = useMemo(
        () => replacePlaceholders(current.subject, PREVIEW_SAMPLES),
        [current.subject],
    );
    const previewBody = useMemo(
        () => replacePlaceholders(current.body, PREVIEW_SAMPLES),
        [current.body],
    );
    const previewAction = useMemo(
        () => replacePlaceholders(current.action_label, PREVIEW_SAMPLES),
        [current.action_label],
    );
    const previewParagraphs = useMemo(
        () =>
            previewBody
                .split(/\n\s*\n/)
                .map((paragraph) => paragraph.trim())
                .filter(Boolean),
        [previewBody],
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('settings.instance.emailTemplates.breadcrumb'),
            href: editEmailTemplates(),
        },
    ];

    const updateCurrent = (field: keyof TemplateFields, value: string) => {
        setTemplates((previous) => ({
            ...previous,
            [activeType]: {
                ...previous[activeType],
                [activeLocale]: {
                    ...previous[activeType][activeLocale],
                    [field]: value,
                },
            },
        }));
    };

    const restoreDefaults = () => {
        setTemplates((previous) => ({
            ...previous,
            [activeType]: {
                ...previous[activeType],
                [activeLocale]: { ...currentDefaults },
            },
        }));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.instance.emailTemplates.head')} />

            <h1 className="sr-only">
                {t('settings.instance.emailTemplates.head')}
            </h1>

            <SettingsLayout wide>
                <Form
                    {...InstanceEmailTemplatesController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-12"
                    noValidate
                >
                    {({ processing, recentlySuccessful, errors }) => (
                        <>
                            {TEMPLATE_TYPES.map((type) =>
                                localeCodes.map((locale) => {
                                    const fields = templates[type][locale];

                                    return (
                                        <div key={`${type}-${locale}`} className="hidden">
                                            <input
                                                type="hidden"
                                                name={`templates[${type}][${locale}][subject]`}
                                                value={fields.subject}
                                            />
                                            <input
                                                type="hidden"
                                                name={`templates[${type}][${locale}][body]`}
                                                value={fields.body}
                                            />
                                            <input
                                                type="hidden"
                                                name={`templates[${type}][${locale}][action_label]`}
                                                value={fields.action_label}
                                            />
                                        </div>
                                    );
                                }),
                            )}

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.instance.emailTemplates.title',
                                    )}
                                    description={t(
                                        'settings.instance.emailTemplates.description',
                                    )}
                                />

                                <Tabs
                                    value={activeType}
                                    onValueChange={(value) =>
                                        setActiveType(value as TemplateType)
                                    }
                                    activationMode="manual"
                                >
                                    <TabsList
                                        variant="line"
                                        aria-label={t(
                                            'settings.instance.emailTemplates.typeLabel',
                                        )}
                                    >
                                        {TEMPLATE_TYPES.map((type) => (
                                            <TabsTrigger
                                                key={type}
                                                value={type}
                                            >
                                                {t(
                                                    `settings.instance.emailTemplates.types.${type}`,
                                                )}
                                            </TabsTrigger>
                                        ))}
                                    </TabsList>
                                </Tabs>

                                <FieldGroup>
                                    <Field>
                                        <FieldLabel htmlFor="email-template-locale">
                                            {t(
                                                'settings.instance.emailTemplates.locale',
                                            )}
                                        </FieldLabel>
                                        <Select
                                            value={activeLocale}
                                            onValueChange={setActiveLocale}
                                        >
                                            <SelectTrigger
                                                id="email-template-locale"
                                                className="w-full"
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
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors[
                                                `templates.${activeType}.${activeLocale}.subject`
                                            ]
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="email-template-subject">
                                            {t(
                                                'settings.instance.emailTemplates.subject',
                                            )}
                                        </FieldLabel>
                                        <Input
                                            id="email-template-subject"
                                            value={current.subject}
                                            onChange={(event) =>
                                                updateCurrent(
                                                    'subject',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={
                                                errors[
                                                    `templates.${activeType}.${activeLocale}.subject`
                                                ]
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <FieldError>
                                            {
                                                errors[
                                                    `templates.${activeType}.${activeLocale}.subject`
                                                ]
                                            }
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors[
                                                `templates.${activeType}.${activeLocale}.body`
                                            ]
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="email-template-body">
                                            {t(
                                                'settings.instance.emailTemplates.body',
                                            )}
                                        </FieldLabel>
                                        <Textarea
                                            id="email-template-body"
                                            value={current.body}
                                            rows={8}
                                            onChange={(event) =>
                                                updateCurrent(
                                                    'body',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={
                                                errors[
                                                    `templates.${activeType}.${activeLocale}.body`
                                                ]
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <FieldDescription>
                                            {t(
                                                'settings.instance.emailTemplates.placeholdersHint',
                                                {
                                                    placeholders:
                                                        currentPlaceholders
                                                            .map(
                                                                (name) =>
                                                                    `{{${name}}}`,
                                                            )
                                                            .join(', '),
                                                },
                                            )}
                                        </FieldDescription>
                                        <FieldError>
                                            {
                                                errors[
                                                    `templates.${activeType}.${activeLocale}.body`
                                                ]
                                            }
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors[
                                                `templates.${activeType}.${activeLocale}.action_label`
                                            ]
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="email-template-action">
                                            {t(
                                                'settings.instance.emailTemplates.actionLabel',
                                            )}
                                        </FieldLabel>
                                        <Input
                                            id="email-template-action"
                                            value={current.action_label}
                                            onChange={(event) =>
                                                updateCurrent(
                                                    'action_label',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={
                                                errors[
                                                    `templates.${activeType}.${activeLocale}.action_label`
                                                ]
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <FieldError>
                                            {
                                                errors[
                                                    `templates.${activeType}.${activeLocale}.action_label`
                                                ]
                                            }
                                        </FieldError>
                                    </Field>

                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={restoreDefaults}
                                    >
                                        {t(
                                            'settings.instance.emailTemplates.restoreDefaults',
                                        )}
                                    </Button>
                                </FieldGroup>
                            </div>

                            <div className="space-y-4">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.instance.emailTemplates.previewTitle',
                                    )}
                                    description={t(
                                        'settings.instance.emailTemplates.previewDescription',
                                    )}
                                />

                                <div
                                    className="bg-muted/40 space-y-4 rounded-lg border p-4"
                                    aria-live="polite"
                                >
                                    <p className="text-sm font-medium">
                                        {previewSubject}
                                    </p>
                                    {previewParagraphs.map(
                                        (paragraph, index) => (
                                            <p
                                                key={`${index}-${paragraph.slice(0, 24)}`}
                                                className="text-muted-foreground text-sm whitespace-pre-wrap"
                                            >
                                                {paragraph}
                                            </p>
                                        ),
                                    )}
                                    {previewAction ? (
                                        <p>
                                            <span className="bg-primary text-primary-foreground inline-flex h-control items-center rounded-md px-4 text-sm font-medium">
                                                {previewAction}
                                            </span>
                                        </p>
                                    ) : null}
                                    <p className="text-muted-foreground text-xs break-all">
                                        {PREVIEW_SAMPLES.url}
                                    </p>
                                </div>
                            </div>

                            <SettingsFormActions
                                processing={processing}
                                recentlySuccessful={recentlySuccessful}
                                data-test="instance-email-templates-save"
                            />
                        </>
                    )}
                </Form>
            </SettingsLayout>
        </AppLayout>
    );
}
