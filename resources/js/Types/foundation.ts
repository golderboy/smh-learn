export interface NamedRecord {
    id: number;
    name: string;
    code?: string;
}
export interface RoleRecord extends NamedRecord {
    code: string;
    permissions?: PermissionRecord[];
}
export interface PermissionRecord extends NamedRecord {
    module?: string;
}
export interface UserRecord {
    id: number;
    employee_code: string;
    username: string;
    first_name: string;
    last_name: string;
    display_name: string;
    email: string | null;
    phone: string | null;
    department_id: number | null;
    position_id: number | null;
    employment_status: string | null;
    is_active: boolean;
    is_root_admin?: boolean;
    roles: RoleRecord[];
    department?: NamedRecord | null;
    position?: NamedRecord | null;
}
export interface CategoryRecord extends NamedRecord {
    code: string;
    description: string | null;
    is_active: boolean;
    instructors: { id: number; display_name: string }[];
}
export interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}
export interface Paginated<T> {
    data: T[];
    links: PageLink[];
    total: number;
    from: number | null;
    to: number | null;
}
export interface AuditRecord {
    id: number;
    action: string;
    entity_type?: string;
    entity_id?: number | null;
    created_at: string;
    reason?: string | null;
    ip_address?: string | null;
    user: { display_name: string } | null;
    old_values?: Record<string, unknown> | null;
    new_values?: Record<string, unknown> | null;
}
export interface BrandingSettings {
    product_name: string;
    header_title: string;
    header_description: string;
    banner_text: string;
    login_heading: string;
    login_description: string;
    footer_text: string;
    support_email: string;
    support_phone: string;
    support_url: string;
    show_sidebar_art: boolean;
    logo_url: string | null;
    banner_url: string | null;
    favicon_url: string | null;
}
export interface SharedProps {
    [key: string]: unknown;
    app: { name: string; organization: string; timezone: string; branding: BrandingSettings };
    auth: {
        user: null | {
            id: number;
            display_name: string;
            department: string | null;
            position: string | null;
            roles: { code: string; name: string }[];
            permissions: string[];
            must_change_password: boolean;
        };
    };
    flash: { message: string | null };
}
