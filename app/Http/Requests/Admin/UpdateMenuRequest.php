<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_available' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'menu_category_id' => ['nullable', 'integer', 'exists:menu_categories,id'],
            'new_category_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
