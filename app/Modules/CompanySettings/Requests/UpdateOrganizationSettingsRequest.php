<?php

namespace App\Modules\CompanySettings\Requests;

use App\Modules\CompanySettings\Models\OrganizationSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', OrganizationSettings::current());
    }

    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'slogan' => ['nullable', 'string', 'max:255'],
        ];
    }
}
