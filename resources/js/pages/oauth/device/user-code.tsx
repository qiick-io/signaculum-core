import { Head } from '@inertiajs/react';
import i18n from 'i18next';
import { useTranslation } from 'react-i18next';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type DeviceUserCodeProps = {
    userCode?: string | null;
    errors?: Record<string, string[]>;
};

/**
 * RFC 8628 verification: the user types the code shown on their device.
 * Plain HTML form — Passport redirects to the device authorize consent next.
 */
export default function DeviceUserCode({
    userCode = '',
    errors = {},
}: DeviceUserCodeProps) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('oauth.device.userCodeTitle')} />

            <form
                method="get"
                action="/oauth/device"
                className="flex flex-col gap-6"
            >
                <div className="grid gap-2">
                    <Label htmlFor="user_code">
                        {t('oauth.device.userCodeLabel')}
                    </Label>
                    <Input
                        id="user_code"
                        name="user_code"
                        required
                        autoFocus
                        autoComplete="one-time-code"
                        defaultValue={userCode ?? ''}
                        placeholder={t('oauth.device.userCodePlaceholder')}
                    />
                    <InputError message={errors.user_code?.[0]} />
                </div>

                <Button type="submit" className="w-full">
                    {t('oauth.device.continue')}
                </Button>
            </form>
        </>
    );
}

DeviceUserCode.layout = {
    get title(): string {
        return i18n.t('oauth.device.userCodeTitle');
    },
    get description(): string {
        return i18n.t('oauth.device.userCodeDescription');
    },
};
