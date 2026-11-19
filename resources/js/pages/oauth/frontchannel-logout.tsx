import { Head } from '@inertiajs/react';
import i18n from 'i18next';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

type FrontchannelLogoutProps = {
    iframeUrls: string[];
    continueUrl: string;
};

/**
 * OP logout interstitial with one hidden iframe per RP that registered
 * `frontchannel_logout_uri` (OIDC Front-Channel Logout 1.0 §2), then continues
 * to the post-logout redirect (or Signaculum login).
 */
export default function FrontchannelLogout({
    iframeUrls,
    continueUrl,
}: FrontchannelLogoutProps) {
    const { t } = useTranslation();

    useEffect(() => {
        const timer = window.setTimeout(() => {
            window.location.assign(continueUrl);
        }, 750);

        return () => {
            window.clearTimeout(timer);
        };
    }, [continueUrl]);

    return (
        <>
            <Head title={t('oauth.frontchannelLogout.title')} />

            <div className="space-y-6">
                <p className="text-center text-sm text-muted-foreground">
                    {t('oauth.frontchannelLogout.description')}
                </p>

                <div aria-hidden="true" className="hidden">
                    {iframeUrls.map((iframeUrl) => (
                        <iframe
                            key={iframeUrl}
                            src={iframeUrl}
                            title={t('oauth.frontchannelLogout.iframeTitle')}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}

FrontchannelLogout.layout = {
    get title(): string {
        return i18n.t('oauth.frontchannelLogout.title');
    },
    get description(): string {
        return i18n.t('oauth.frontchannelLogout.description');
    },
};
