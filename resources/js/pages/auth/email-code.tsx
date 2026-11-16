import { Form, Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import {
    store as sendEmailCode,
    verify as verifyEmailCode,
} from '@/routes/login/email-code';

type Props = {
    email?: string;
    status?: string;
};

/**
 * Guest passwordless sign-in: enter the 6-digit code emailed after
 * `login.email-code.store`. Pattern from app-ui auth pages / forgot-password.
 */
export default function EmailCodeLogin({ email = '', status }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('auth.emailCode.head')} />

            {status ? (
                <div className="mb-4 text-center text-sm font-medium text-success">
                    {status}
                </div>
            ) : null}

            <Form
                {...verifyEmailCode.form()}
                className="flex flex-col gap-6"
                noValidate
            >
                {({ processing, errors }) => (
                    <>
                        <input type="hidden" name="email" value={email} />

                        <div className="grid gap-2">
                            <Label htmlFor="code">
                                {t('auth.emailCode.code')}
                            </Label>
                            <Input
                                id="code"
                                name="code"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                required
                                autoFocus
                                maxLength={6}
                                placeholder="000000"
                            />
                            <InputError message={errors.code ?? errors.email} />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="email-code-verify"
                        >
                            {processing ? <Spinner /> : null}
                            {t('auth.emailCode.verify')}
                        </Button>
                    </>
                )}
            </Form>

            <Form {...sendEmailCode.form()} className="mt-4" noValidate>
                {({ processing }) => (
                    <>
                        <input type="hidden" name="email" value={email} />
                        <Button
                            type="submit"
                            variant="ghost"
                            className="w-full"
                            disabled={processing || email === ''}
                            data-test="email-code-resend"
                        >
                            {t('auth.emailCode.resend')}
                        </Button>
                    </>
                )}
            </Form>

            <div className="mt-4 text-center text-sm text-muted-foreground">
                <TextLink href={login()}>
                    {t('auth.emailCode.backToLogin')}
                </TextLink>
            </div>
        </>
    );
}

EmailCodeLogin.layout = {
    title: 'Sign in with email code',
    description: 'Enter the six-digit code we sent you',
};
