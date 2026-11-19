/** Normalized Laravel paginator shape used across admin list views. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
};

/** One row of `App\Http\Resources\UserResource`. */
export type AdminUserRow = {
    id: number;
    first_name: string;
    last_name: string | null;
    /** First and last name. */
    name: string;
    email: string;
    is_active: boolean;
    is_locked: boolean;
    username?: string | null;
    /** Derived from the super-admin role (#206). */
    is_instance_admin: boolean;
    has_two_factor: boolean;
    email_verified_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    can_update: boolean;
    can_impersonate: boolean;
};

/** One row of `App\Http\Resources\ActivityLogResource`. */
export type AdminActivityLogRow = {
    id: number;
    event: string | null;
    description: string;
    log_name: string | null;
    created_at: string;
    causer: { id: number; name: string; email: string } | null;
    subject: { type: string; id: number | string; label: string } | null;
    changes: Record<string, unknown>;
    properties: {
        ip: string | null;
        user_agent: string | null;
        meta: Record<string, unknown>;
    };
};

/** Compact user option for activity-log filters. */
export type AdminUserRef = {
    id: number;
    name: string;
    email: string;
};

/** One row of `App\Http\Resources\ApplicationResource`. */
export type AdminApplicationRow = {
    id: string;
    name: string;
    redirect_uris: string[];
    is_confidential: boolean;
    is_first_party: boolean;
    is_client_credentials_enabled: boolean;
    is_device_authorization_enabled: boolean;
    is_pushed_authorization_required: boolean;
    frontchannel_logout_uri: string | null;
    is_frontchannel_logout_session_required: boolean;
    discovery_url: string;
    configure_command: string;
    created_at: string | null;
    updated_at: string | null;
    can_update: boolean;
    can_delete: boolean;
};
