/** Password policies accepted by Settings → Authentication & security. */
export type PasswordPolicy = 'weak' | 'medium' | 'strong';

/** Light/dark/system theme. */
export type DefaultAppearance = 'system' | 'light' | 'dark';

/** Instance settings shared with every page (HandleInertiaRequests). */
export type SharedInstanceSettings = {
    reportBugUrl: string | null;
};

/**
 * Instance settings as edited on the settings pages (SettingsRepositoryService); each page
 * receives a subset. Copied from Excubia's `ProjectSettingsForm`, trimmed to Signaculum.
 */
export type InstanceSettingsForm = {
    app_name: string | null;
    description: string | null;
    report_bug_url: string | null;
    default_language: string;
    default_appearance: DefaultAppearance;
    password_policy: PasswordPolicy;
    login_max_attempts: number;
    registration_enabled: boolean;
    two_factor_required: boolean;
};
