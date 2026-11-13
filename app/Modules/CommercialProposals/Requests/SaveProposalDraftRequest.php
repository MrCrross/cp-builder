<?php

namespace App\Modules\CommercialProposals\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProposalDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('proposal'));
    }

    /**
     * Грубая структурная проверка; доменная валидация (деньги, quantity min≤max,
     * payload-секции) — в SaveDraftAction и SectionPayloadValidator.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $decimal = 'required|string|regex:/^\d+(\.\d{1,2})?$/';

        return [
            'title' => ['required', 'string', 'max:500'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'client_contact' => ['nullable', 'string', 'max:255'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'manager_email' => ['nullable', 'string', 'max:255'],
            'manager_phone' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:10000'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'branch_id' => ['nullable', 'integer', 'exists:proposal_branches,id'],
            'items' => ['array', 'max:500'],
            'items.*.name' => ['required', 'string', 'max:500'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.quantity_min' => $decimal,
            'items.*.quantity_max' => $decimal,
            'items.*.unit_price' => $decimal,
            'items.*.catalog_item_id' => ['nullable', 'integer', 'exists:catalog_items,id'],
            'sections' => ['array', 'max:100'],
            'sections.*.type' => ['required', Rule::in(['items', 'stages', 'custom', 'table', 'license', 'price_badge'])],
            'sections.*.enabled' => ['boolean'],
            'sections.*.payload' => ['required', 'array'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
