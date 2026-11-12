/**
 * DTO номенклатуры — transport-типы из backend (план §11.2).
 * Деньги приходят decimal strings.
 */

export interface CatalogCategoryListItem {
    id: number;
    name: string;
    items_count: number;
}

export interface CatalogItemListItem {
    id: number;
    name: string;
    description: string | null;
    unit: string | null;
    price: string;
    active: boolean;
    category: { id: number; name: string } | null;
}

export interface CatalogItemOption {
    id: number;
    name: string;
    description: string | null;
    unit: string | null;
    price: string;
    category: string | null;
}
