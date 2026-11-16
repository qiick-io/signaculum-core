import { Form, Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import PasskeyVerify from '@/components/passkey-verify';
import i18n from 'i18next';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { store as sendEmailCode } from '@/routes/login/email-code';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
    canRegister?: boolean;
    canEmailCodeLogin?: boolean;
};

/**
 * Guest auth: email/password login with optional passkey, email code, and registration link.
 */
export default function Login({
    status,
    canResetPassword,
    canRegister = false,
    canEmailCodeLogin = false,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('auth.login.head')} />

            <PasskeyVerify
                label={t('auth.login.passkey')}
                loadingLabel={t('auth.login.authenticating')}
                separator={t('auth.login.orContinueWithEmail')}
            />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('common.emailAddress')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">
                                        {t('common.password')}
                                    </Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            {t('auth.login.forgotPassword')}
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder={t('common.password')}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">
                                    {t('auth.login.rememberMe')}
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {t('auth.login.submit')}
                            </Button>
                        </div>

                        {canRegister && (
                            <div className="text-center text-sm text-muted-foreground">
                                {t('auth.login.noAccount')}{' '}
                                <TextLink href={register()} tabIndex={5}>
                                    {t('auth.login.signUp')}
                                </TextLink>
                            </div>
                        )}
                    </>
                )}
            </Form>

            {canEmailCodeLogin ? (
                <Form
                    {...sendEmailCode.form()}
                    className="mt-6 flex flex-col gap-3"
                    noValidate
                >
                    {({ processing, errors }) => (
                        <>
                            <p className="text-center text-sm text-muted-foreground">
                                {t('auth.emailCode.orUseCode')}
                            </p>
                            <Input
                                type="email"
                                name="email"
                                autoComplete="email"
                                placeholder="email@example.com"
                                aria-label={t('auth.emailCode.email')}
                            />
                            <InputError message={errors.email} />
                            <Button
                                type="submit"
                                variant="secondary"
                                className="w-full"
                                disabled={processing}
                                data-test="email-code-request"
                            >
                                {processing ? <Spinner /> : null}
                                {t('auth.emailCode.sendCode')}
                            </Button>
                        </>
                    )}
                </Form>
            ) : null}

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-success">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    get title(): string {
        return i18n.t('auth.login.title');
    },
    get description(): string {
        return i18n.t('auth.login.description');
    },
};
