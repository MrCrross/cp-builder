<?php

namespace App\Modules\Imports\Http\Requests;

use App\Modules\Imports\Domain\ImportLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('imports.run') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'profile' => ['required', 'string', Rule::in(['commerceml', 'yml'])],
            'files' => ['required', 'array', 'min:1', 'max:5'],
            'files.*' => [
                'file',
                'mimes:xml',
                'max:'.(int) (ImportLimits::MAX_FILE_BYTES / 1024),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.required' => 'Добавьте хотя бы один XML-файл выгрузки.',
            'files.*.mimes' => 'Файл должен быть XML-выгрузкой.',
            'files.*.max' => 'Файл превышает допустимый размер (512 МБ).',
        ];
    }
}
