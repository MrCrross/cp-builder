<?php

namespace App\Modules\Catalog\Requests;

use App\Modules\Catalog\Models\CatalogCategory;
use Illuminate\Foundation\Http\FormRequest;

class StoreCatalogCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CatalogCategory::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
