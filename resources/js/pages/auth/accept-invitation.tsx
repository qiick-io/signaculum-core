import { Form, Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import AcceptInvitationController from '@/actions/App/Http/Controllers/AcceptInvitationController';
import { Button } from '@/components/ui/button';
import TextLink from '@/components/text-link';
import { login } from '@/routes';

type AcceptInvitationProps = {
    status: 'guest' | 'ready' | 'expired';
    organizationName: string | null;
    email: string;
    token: string;
};

/**
 * Auth layout page to accept (or see why not) an organisation invitation.
 */
export default function AcceptInvitation({
    status,
    organizationName,
    email,
    token,
}: AcceptInvitationProps) {
    const { t } = useTranslation();
    const name = organizationName ?? t('organizations.title');

    return (
        <>
            <Head title={t('organizations.accept.head')} />

            <div className="space-y-4 text-center">
                {status === 'expired' ? (
                    <p className="text-muted-foreground">
                        {t('organizations.accept.expired', { name })}
                    </p>
                ) : null}

                {status === 'guest' ? (
                    <>
                        <p className="text-muted-foreground">
                            {t('organizations.accept.guest', {
                                name,
                                email,
                            })}
                        </p>
                        <TextLink href={login()}>
                            {t('organizations.accept.signIn')}
                        </TextLink>
                    </>
                ) : null}

                {status === 'ready' ? (
                    <>
                        <p className="text-muted-foreground">
                            {t('organizations.accept.ready', {
                                name,
                                email,
                            })}
                        </p>
                        <Form
                            {...AcceptInvitationController.store.form({
                                token,
                            })}
                            className="flex justify-center"
                        >
                            {({ processing }) => (
                                <Button type="submit" disabled={processing}>
                                    {t('organizations.accept.confirm')}
                                </Button>
                            )}
                        </Form>
                    </>
                ) : null}
            </div>
        </>
    );
}

AcceptInvitation.layout = {
    title: 'Organisation invitation',
    description: 'Accept an invitation to join an organisation on Signaculum',
};
