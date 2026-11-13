import { Form, Head } from '@inertiajs/react';
import i18n from 'i18next';
import { useTranslation } from 'react-i18next';
import AcceptInvitationController from '@/actions/App/Http/Controllers/AcceptInvitationController';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';

type AcceptInvitationProps = {
    token: string;
    email: string;
    roles: string[];
    organization: {
        name: string;
        slug: string;
    };
    expiresAt: string | null;
    canAccept: boolean;
    isSignedIn: boolean;
};

/**
 * Auth-layout page to accept an organisation invitation by opaque token.
 */
export default function AcceptInvitation({
    token,
    email,
    roles,
    organization,
    expiresAt,
    canAccept,
    isSignedIn,
}: AcceptInvitationProps) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('organizations.accept.head')} />

            <div className="space-y-6">
                <p className="text-center text-sm text-muted-foreground">
                    {t('organizations.accept.invitedTo', {
                        organization: organization.name,
                        email,
                    })}
                </p>

                <p className="text-center text-sm text-muted-foreground">
                    {t('organizations.accept.roles', {
                        roles: roles.join(', '),
                    })}
                </p>

                {expiresAt ? (
                    <p className="text-center text-xs text-muted-foreground">
                        {t('organizations.accept.expires', {
                            date: expiresAt,
                        })}
                    </p>
                ) : null}

                {canAccept ? (
                    <Form
                        {...AcceptInvitationController.form.post(token)}
                        className="flex flex-col gap-4"
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                                data-test="accept-invitation"
                            >
                                {t('organizations.accept.submit')}
                            </Button>
                        )}
                    </Form>
                ) : (
                    <div className="space-y-4 text-center text-sm">
                        <p className="text-muted-foreground">
                            {isSignedIn
                                ? t('organizations.accept.wrongAccount')
                                : t('organizations.accept.signInFirst')}
                        </p>
                        {!isSignedIn ? (
                            <div className="flex flex-col gap-2">
                                <TextLink href={login()}>
                                    {t('organizations.accept.login')}
                                </TextLink>
                                <TextLink href={register()}>
                                    {t('organizations.accept.register')}
                                </TextLink>
                            </div>
                        ) : null}
                    </div>
                )}
            </div>
        </>
    );
}

AcceptInvitation.layout = {
    get title(): string {
        return i18n.t('organizations.accept.title');
    },
    get description(): string {
        return i18n.t('organizations.accept.description');
    },
};
