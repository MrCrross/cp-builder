/**
 * DTO проектов — transport-типы из backend (план §11.2).
 */

export interface ProjectListItem {
    id: number;
    name: string;
    client_name: string | null;
    client_contact: string | null;
    proposals_count: number;
    updated_at: string | null;
}

export interface ProjectDetails {
    id: number;
    name: string;
    client_name: string | null;
    client_contact: string | null;
    updated_at: string | null;
}

export interface ProjectProposalRef {
    id: number;
    number: string | null;
    status: 'draft' | 'issued' | 'archived';
    has_draft: boolean;
    updated_at: string | null;
}

export interface ProjectOption {
    id: number;
    name: string;
}
