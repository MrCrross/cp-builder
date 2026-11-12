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

export interface ProposalIssuedData {
    version: number;
    title: string;
    issued_at: string | null;
    total_min: string;
    total_max: string;
}

export interface ProposalEditPayload {
    id: number;
    number: string | null;
    status: ProposalStatus;
    template_id: number | null;
    project_id: number | null;
    has_public_link: boolean;
    preview_url: string;
    draft: ProposalDraftData | null;
    issued: ProposalIssuedData | null;
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

export interface IssuedFlash {
    url: string;
    token: string;
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
