/** Authenticated user record from shared Inertia props. */
export type User = {
    id: number;
    first_name: string;
    last_name: string | null;
    /** First and last name. */
    name: string;
    email: string;
    username?: string | null;
    avatar?: string;
    email_verified_at: string | null;
    locale?: string;
    timezone?: string;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/** Active impersonation session shared for the stop banner. */
export type ImpersonationState = {
    actor_name: string;
    expires_at: string | null;
};

/** Shared auth context: the signed-in user, if any. */
export type Auth = {
    user: User | null;
    /** Effective permission names (user roles ∪ group roles). */
    permissions: string[];
    /** Effective Spatie role names. */
    roleNames: string[];
    /** True when the user holds super-admin (direct or via a group). */
    isSuperAdmin: boolean;
    /** Derived: shows the Instance settings group (prefer useCan). */
    canManageSettings: boolean;
    /** Derived: shows the Admin → Users nav (prefer useCan). */
    canManageUsers: boolean;
    /** Derived: shows the Admin → Applications nav (prefer useCan). */
    canManageApplications: boolean;
    /** Derived: shows the Admin → Activity log nav (prefer useCan). */
    canShowActivityLogs: boolean;
    /** Derived: shows the Admin → Groups nav (prefer useCan). */
    canManageGroups: boolean;
    /** Present while an instance admin is viewing as another user. */
    impersonation: ImpersonationState | null;
};

/** Passkey registered by the current user (Laravel passkeys). */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

/** QR code payload returned during two-factor enrollment. */
export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

/** Manual TOTP secret key for authenticator apps. */
export type TwoFactorSecretKey = {
    secretKey: string;
};
