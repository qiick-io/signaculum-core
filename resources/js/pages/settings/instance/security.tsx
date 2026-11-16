import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import InstanceSecuritySettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSecuritySettingsController';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editSecurity } from '@/routes/instance/security';
import type { BreadcrumbItem, InstanceSettingsForm } from '@/types';

type SecurityInstanceSettings = Pick<
    InstanceSettingsForm,
    | 'password_min_length'
    | 'password_require_mixed_case'
    | 'password_require_numbers'
    | 'password_require_symbols'
    | 'password_reject_compromised'
    | 'login_max_attempts'
    | 'login_lockout_threshold'
    | 'login_lockout_minutes'
    | 'registration_enabled'
    | 'two_factor_required'
    | 'email_code_login_enabled'
>;

type Props = {
    instance: SecurityInstanceSettings;
};

function buildFormState(instance: SecurityInstanceSettings) {
    return {
        password_min_length: instance.password_min_length,
        password_require_mixed_case: instance.password_require_mixed_case,
        password_require_numbers: instance.password_require_numbers,
        password_require_symbols: instance.password_require_symbols,
        password_reject_compromised: instance.password_reject_compromised,
        login_max_attempts: instance.login_max_attempts,
        login_lockout_threshold: instance.login_lockout_threshold,
        login_lockout_minutes: instance.login_lockout_minutes,
        registration_enabled: instance.registration_enabled,
        two_factor_required: instance.two_factor_required,
        email_code_login_enabled: instance.email_code_login_enabled,
    };
}

/**
 * Settings → Authentication & security: granular password policy, login attempts,
 * self-registration and required two-factor authentication. Pattern from app-ui
 * `settings.md` / Excubia instance security, without Excubia's SSO section.
 */
export default function InstanceSecuritySettingsPage({ instance }: Props) {
    const { t } = useTranslation();
    const [form, setForm] = useState(() => buildFormState(instance));

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('settings.instance.securityNav.breadcrumb'),
            href: editSecurity(),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.instance.securityNav.head')} />

            <h1 className="sr-only">
                {t('settings.instance.securityNav.head')}
            </h1>

            <SettingsLayout>
                <Form
                    {...InstanceSecuritySettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-12"
                    noValidate
                >
                    {({ processing, recentlySuccessful, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="registration_enabled"
                                value={form.registration_enabled ? '1' : '0'}
                            />
                            <input
                                type="hidden"
                                name="two_factor_required"
                                value={form.two_factor_required ? '1' : '0'}
                            />
                            <input
                                type="hidden"
                                name="email_code_login_enabled"
                                value={
                                    form.email_code_login_enabled ? '1' : '0'
                                }
                            />
                            <input
                                type="hidden"
                                name="password_require_mixed_case"
                                value={
                                    form.password_require_mixed_case ? '1' : '0'
                                }
                            />
                            <input
                                type="hidden"
                                name="password_require_numbers"
                                value={
                                    form.password_require_numbers ? '1' : '0'
                                }
                            />
                            <input
                                type="hidden"
                                name="password_require_symbols"
                                value={
                                    form.password_require_symbols ? '1' : '0'
                                }
                            />
                            <input
                                type="hidden"
                                name="password_reject_compromised"
                                value={
                                    form.password_reject_compromised ? '1' : '0'
                                }
                            />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t('settings.instance.securityTitle')}
                                    description={t(
                                        'settings.instance.securityDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    <Field
                                        data-invalid={
                                            errors.password_min_length
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="password_min_length">
                                            {t(
                                                'settings.instance.passwordMinLength',
                                            )}
                                        </FieldLabel>
                                        <Input
                                            id="password_min_length"
                                            name="password_min_length"
                                            type="number"
                                            min={6}
                                            max={128}
                                            value={form.password_min_length}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    password_min_length: Number(
                                                        event.target.value,
                                                    ),
                                                }))
                                            }
                                            aria-invalid={
                                                errors.password_min_length
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.password_min_length
                                                    ? 'password-min-length-error'
                                                    : undefined
                                            }
                                        />
                                        <FieldError id="password-min-length-error">
                                            {errors.password_min_length}
                                        </FieldError>
                                    </Field>

                                    <Field>
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id="password_require_mixed_case"
                                                checked={
                                                    form.password_require_mixed_case
                                                }
                                                onCheckedChange={(checked) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        password_require_mixed_case:
                                                            checked === true,
                                                    }))
                                                }
                                            />
                                            <FieldLabel htmlFor="password_require_mixed_case">
                                                {t(
                                                    'settings.instance.passwordRequireMixedCase',
                                                )}
                                            </FieldLabel>
                                        </div>
                                    </Field>

                                    <Field>
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id="password_require_numbers"
                                                checked={
                                                    form.password_require_numbers
                                                }
                                                onCheckedChange={(checked) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        password_require_numbers:
                                                            checked === true,
                                                    }))
                                                }
                                            />
                                            <FieldLabel htmlFor="password_require_numbers">
                                                {t(
                                                    'settings.instance.passwordRequireNumbers',
                                                )}
                                            </FieldLabel>
                                        </div>
                                    </Field>

                                    <Field>
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id="password_require_symbols"
                                                checked={
                                                    form.password_require_symbols
                                                }
                                                onCheckedChange={(checked) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        password_require_symbols:
                                                            checked === true,
                                                    }))
                                                }
                                            />
                                            <FieldLabel htmlFor="password_require_symbols">
                                                {t(
                                                    'settings.instance.passwordRequireSymbols',
                                                )}
                                            </FieldLabel>
                                        </div>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.password_reject_compromised
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id="password_reject_compromised"
                                                checked={
                                                    form.password_reject_compromised
                                                }
                                                onCheckedChange={(checked) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        password_reject_compromised:
                                                            checked === true,
                                                    }))
                                                }
                                                aria-describedby="password-reject-compromised-hint"
                                            />
                                            <FieldLabel htmlFor="password_reject_compromised">
                                                {t(
                                                    'settings.instance.passwordRejectCompromised',
                                                )}
                                            </FieldLabel>
                                        </div>
                                        <FieldDescription
                                            id="password-reject-compromised-hint"
                                            className="pl-8"
                                        >
                                            {t(
                                                'settings.instance.passwordRejectCompromisedHint',
                                            )}
                                        </FieldDescription>
                                        <FieldError>
                                            {errors.password_reject_compromised}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.login_max_attempts
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="login_max_attempts">
                                            {t(
                                                'settings.instance.loginMaxAttempts',
                                            )}
                                        </FieldLabel>
                                        <Input
                                            id="login_max_attempts"
                                            name="login_max_attempts"
                                            type="number"
                                            min={1}
                                            max={100}
                                            value={form.login_max_attempts}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    login_max_attempts: Number(
                                                        event.target.value,
                                                    ),
                                                }))
                                            }
                                            aria-invalid={
                                                errors.login_max_attempts
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.login_max_attempts
                                                    ? 'login-max-attempts-error'
                                                    : undefined
                                            }
                                        />
                                        <FieldError id="login-max-attempts-error">
                                            {errors.login_max_attempts}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.login_lockout_threshold
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="login_lockout_threshold">
                                            {t(
                                                'settings.instance.loginLockoutThreshold',
                                            )}
                                        </FieldLabel>
                                        <FieldDescription>
                                            {t(
                                                'settings.instance.loginLockoutThresholdDescription',
                                            )}
                                        </FieldDescription>
                                        <Input
                                            id="login_lockout_threshold"
                                            name="login_lockout_threshold"
                                            type="number"
                                            min={1}
                                            max={100}
                                            value={form.login_lockout_threshold}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    login_lockout_threshold:
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                }))
                                            }
                                            aria-invalid={
                                                errors.login_lockout_threshold
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <FieldError>
                                            {errors.login_lockout_threshold}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.login_lockout_minutes
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="login_lockout_minutes">
                                            {t(
                                                'settings.instance.loginLockoutMinutes',
                                            )}
                                        </FieldLabel>
                                        <FieldDescription>
                                            {t(
                                                'settings.instance.loginLockoutMinutesDescription',
                                            )}
                                        </FieldDescription>
                                        <Input
                                            id="login_lockout_minutes"
                                            name="login_lockout_minutes"
                                            type="number"
                                            min={1}
                                            max={1440}
                                            value={form.login_lockout_minutes}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    login_lockout_minutes:
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                }))
                                            }
                                            aria-invalid={
                                                errors.login_lockout_minutes
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <FieldError>
                                            {errors.login_lockout_minutes}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.two_factor_required
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id="two_factor_required"
                                                checked={
                                                    form.two_factor_required
                                                }
                                                onCheckedChange={(checked) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        two_factor_required:
                                                            checked === true,
                                                    }))
                                                }
                                                aria-invalid={
                                                    errors.two_factor_required
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.two_factor_required
                                                        ? 'two-factor-required-error'
                                                        : 'two-factor-required-hint'
                                                }
                                            />
                                            <FieldLabel htmlFor="two_factor_required">
                                                {t(
                                                    'settings.instance.twoFactorRequired',
                                                )}
                                            </FieldLabel>
                                        </div>
                                        <FieldDescription
                                            id="two-factor-required-hint"
                                            className="pl-8"
                                        >
                                            {t(
                                                'settings.instance.twoFactorRequiredHint',
                                            )}
                                        </FieldDescription>
                                        <FieldError id="two-factor-required-error">
                                            {errors.two_factor_required}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.email_code_login_enabled
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id="email_code_login_enabled"
                                                checked={
                                                    form.email_code_login_enabled
                                                }
                                                onCheckedChange={(checked) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        email_code_login_enabled:
                                                            checked === true,
                                                    }))
                                                }
                                                aria-describedby="email-code-login-hint"
                                            />
                                            <FieldLabel htmlFor="email_code_login_enabled">
                                                {t(
                                                    'settings.instance.emailCodeLoginEnabled',
                                                )}
                                            </FieldLabel>
                                        </div>
                                        <FieldDescription
                                            id="email-code-login-hint"
                                            className="pl-8"
                                        >
                                            {t(
                                                'settings.instance.emailCodeLoginEnabledHint',
                                            )}
                                        </FieldDescription>
                                        <FieldError>
                                            {errors.email_code_login_enabled}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                            </div>

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.instance.registrationTitle',
                                    )}
                                    description={t(
                                        'settings.instance.registrationDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    <Field
                                        data-invalid={
                                            errors.registration_enabled
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id="registration_enabled"
                                                checked={
                                                    form.registration_enabled
                                                }
                                                onCheckedChange={(checked) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        registration_enabled:
                                                            checked === true,
                                                    }))
                                                }
                                                aria-invalid={
                                                    errors.registration_enabled
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.registration_enabled
                                                        ? 'registration-enabled-error'
                                                        : 'registration-enabled-hint'
                                                }
                                            />
                                            <FieldLabel htmlFor="registration_enabled">
                                                {t(
                                                    'settings.instance.registrationEnabled',
                                                )}
                                            </FieldLabel>
                                        </div>
                                        <FieldDescription
                                            id="registration-enabled-hint"
                                            className="pl-8"
                                        >
                                            {t(
                                                'settings.instance.registrationEnabledHint',
                                            )}
                                        </FieldDescription>
                                        <FieldError id="registration-enabled-error">
                                            {errors.registration_enabled}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                            </div>

                            <SettingsFormActions
                                processing={processing}
                                recentlySuccessful={recentlySuccessful}
                                data-test="instance-security-settings-save"
                            />
                        </>
                    )}
                </Form>
            </SettingsLayout>
        </AppLayout>
    );
}
