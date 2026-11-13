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
    is_instance_admin: boolean;
    has_two_factor: boolean;
    email_verified_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    can_update: boolean;
};

/** One row of `App\Http\Resources\ApplicationResource`. */
export type AdminApplicationRow = {
    id: string;
    name: string;
    redirect_uris: string[];
    is_confidential: boolean;
    is_first_party: boolean;
    discovery_url: string;
    configure_command: string;
    created_at: string | null;
    updated_at: string | null;
    can_update: boolean;
    can_delete: boolean;
};
