/**
 * DTO модуля статистики — transport-типы из backend (план §13.5).
 * Все метрики — дневные агрегаты без PII (ADR-0011).
 */

export interface StatisticsTotals {
    visits: number;
    unique_visitors: number;
    bot_requests: number;
    proposals_with_visits: number;
}

export interface StatisticsSeriesPoint {
    date: string;
    visits: number;
    unique_visitors: number;
}

export interface StatisticsBreakdownItem {
    value: string;
    visits: number;
}

export interface StatisticsBreakdowns {
    device_type: StatisticsBreakdownItem[];
    os: StatisticsBreakdownItem[];
}

export interface StatisticsProposalRow {
    id: number;
    number: string | null;
    title: string | null;
    visits: number;
    unique_visitors: number;
    bot_requests: number;
}

export interface StatisticsProposalOption {
    id: number;
    number: string | null;
    title: string | null;
}

export interface StatisticsFilters {
    period: number;
    proposal_id: number | null;
}

export interface StatisticsFreshness {
    last_visit_at: string | null;
    generated_at: string;
}
