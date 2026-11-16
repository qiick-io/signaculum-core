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

export default function Login({
    status,
    canResetPassword,
    canRegister = false,
    canEmailCodeLogin = false,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title="Log in" />

            <PasskeyVerify />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
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
                                    <Label htmlFor="password">Password</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            Forgot your password?
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="Password"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">Remember me</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Log in
                            </Button>
                        </div>

                        {canRegister && (
                            <div className="text-center text-sm text-muted-foreground">
                                Don't have an account?{' '}
                                <TextLink href={register()} tabIndex={5}>
                                    Sign up
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
    title: 'Log in to your account',
    description: 'Enter your email and password below to log in',
};
