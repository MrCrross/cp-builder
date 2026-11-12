<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.manage');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['administrator', 'manager', 'viewer'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Укажите имя пользователя.',
            'email.required' => 'Укажите email.',
            'email.email' => 'Введите корректный email.',
            'email.unique' => 'Пользователь с таким email уже существует.',
            'role.required' => 'Выберите роль.',
            'role.in' => 'Неизвестная роль.',
        ];
    }
}
