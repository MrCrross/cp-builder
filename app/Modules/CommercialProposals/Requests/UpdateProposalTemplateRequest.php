<?php

namespace App\Modules\CommercialProposals\Requests;

use App\Modules\CommercialProposals\Models\ProposalTemplate;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProposalTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', ProposalTemplate::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'default_title' => ['required', 'string', 'max:500'],
            'default_subtitle' => ['nullable', 'string', 'max:500'],
            'default_content' => ['nullable', 'array'],
            'active' => ['boolean'],
        ];
    }
}
