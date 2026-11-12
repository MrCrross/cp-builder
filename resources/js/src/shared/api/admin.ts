/**
 * Transport-DTO административных read API (план §9.6). Только форматы данных;
 * бизнес-правила и подписи живут в pages/model, не здесь.
 */

export type AdminUser = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    is_active: boolean;
    password_set_at: string | null;
    created_at: string;
};

export type AuditActor = {
    id: number;
    name: string;
    email: string;
} | null;

export type AuditEntry = {
    id: number;
    action: string;
    actor: AuditActor;
    subject_type: string | null;
    subject_id: number | null;
    request_id: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    created_at: string;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
    links: PaginationLink[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
