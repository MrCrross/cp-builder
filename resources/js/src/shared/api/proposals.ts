/**
 * DTO КП — transport-типы из backend (план §11.2: shared/api — DTO,
 * не доменные правила). Деньги/количества приходят decimal strings.
 */

export type ProposalStatus = 'draft' | 'issued' | 'archived';

export interface ProposalListItem {
    id: number;
    number: string | null;
    status: ProposalStatus;
    title: string | null;
    total_min: string | null;
    total_max: string | null;
    has_draft: boolean;
    versions_count: number;
    updated_at: string | null;
}

export interface ProposalItemInput {
    catalog_item_id: number | null;
    name: string;
    description: string | null;
    unit: string | null;
    quantity_min: string;
    quantity_max: string;
    unit_price: string;
}

export type SectionType =
    | 'items'
    | 'stages'
    | 'custom'
    | 'table'
    | 'license'
    | 'price_badge';

export interface ProposalSectionInput {
    type: SectionType;
    enabled: boolean;
    payload: Record<string, unknown>;
}

export interface ProposalDraftData {
    id: number;
    branch_id: number;
    branch_name: string;
    lock_version: number;
    title: string;
    subtitle: string | null;
    client_name: string | null;
    client_contact: string | null;
    manager_name: string | null;
    manager_email: string | null;
    manager_phone: string | null;
    note: string | null;
    total_min: string;
    total_max: string;
    items: ProposalItemInput[];
    sections: ProposalSectionInput[];
}

export interface ProposalVersionData {
    id: number;
    version: number;
    state: 'draft' | 'issued' | 'superseded';
    title: string;
    issued_at: string | null;
    total_min: string;
    total_max: string;
}

export interface ProposalBranchData {
    id: number;
    name: string;
    is_main: boolean;
    has_public_link: boolean;
    /** Публичный URL ветки; null — ссылки нет или токен старой ссылки не хранился (нужен rotate). */
    public_url: string | null;
    current_version: ProposalVersionData | null;
    draft_version: { id: number } | null;
}

export interface ProposalEditPayload {
    id: number;
    number: string | null;
    status: ProposalStatus;
    template_id: number | null;
    project_id: number | null;
    has_public_link: boolean;
    /** Публичный URL основной ссылки; null — ссылки нет или токен старой ссылки не хранился (нужен rotate). */
    public_url: string | null;
    preview_url: string | null;
    active_branch: string | null;
    branches: ProposalBranchData[];
    versions: ProposalVersionListItem[];
    draft: ProposalDraftData | null;
    current_version: ProposalVersionData | null;
}

/** Версия в списке для форков: id + номер + ветка-владелец. */
export interface ProposalVersionListItem {
    id: number;
    version: number;
    state: 'draft' | 'issued' | 'superseded';
    branch_name: string;
}

export interface ProposalTemplateOption {
    id: number;
    name: string;
    default_title: string;
}

export interface ProposalTemplateListItem {
    id: number;
    name: string;
    description: string | null;
    default_title: string;
    active: boolean;
}

/** Отличие snapshot-строки черновика от актуального справочника. */
export interface CatalogDiffEntry {
    position: number;
    catalog_item_id: number;
    name: { current: string; catalog: string };
    unit: { current: string | null; catalog: string | null };
    unit_price: { current: string; catalog: string };
    changed: boolean;
}

/** Десятичная строка → локальный формат сумм; только отображение. */
export function formatMoney(value: string | null | undefined): string {
    if (value == null) return '—';
    const [rubles, kopecks = '00'] = value.split('.');
    const grouped = rubles.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return `${grouped},${kopecks} ₽`;
}

export function formatQuantityRange(min: string, max: string): string {
    return min === max ? min : `${min}–${max}`;
}
