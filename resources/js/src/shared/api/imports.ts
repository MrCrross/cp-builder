/**
 * DTO импортов номенклатуры — transport-типы из backend (план §13.1).
 * Деньги приходят decimal strings.
 */

export type ImportProfileValue = 'commerceml' | 'yml';

export interface ImportProfileOption {
    value: ImportProfileValue;
    label: string;
}

export interface ImportJobSummary {
    id: number;
    profile: ImportProfileValue;
    status: string;
    error_message: string | null;
    rows_total: number;
    rows_add: number;
    rows_change: number;
    rows_skip: number;
    rows_delete: number;
    rows_error: number;
    user: { id: number; name: string } | null;
    created_at: string | null;
    applied_at: string | null;
}

export interface ImportJobFile {
    id: number;
    original_name: string;
    size_bytes: number;
    detected_kind: string | null;
    parsed_count: number;
    error: string | null;
}

export interface ImportJobDetail extends ImportJobSummary {
    rows_processed: number;
    applied_created: number;
    applied_updated: number;
    applied_deactivated: number;
    files: ImportJobFile[];
}

export type ImportRowActionValue = 'add' | 'change' | 'skip' | 'delete';

export interface ImportPropertyPair {
    name: string;
    value: string;
}

export interface ImportRowItem {
    id: number;
    external_id: string;
    action: ImportRowActionValue;
    name: string | null;
    article: string | null;
    properties: ImportPropertyPair[];
    image: string | null;
    source_url: string | null;
    unit: string | null;
    price: string | null;
    currency: string | null;
    delete: boolean;
    errors: string[] | null;
}
