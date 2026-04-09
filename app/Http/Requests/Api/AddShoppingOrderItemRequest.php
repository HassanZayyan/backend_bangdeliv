<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AddShoppingOrderItemRequest extends FormRequest
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
            'item_source' => ['required', 'in:MENU_DB,MANUAL'],
            'menu_id' => ['nullable', 'integer', 'required_if:item_source,MENU_DB', 'exists:menus,id'],
            'menu_name' => ['nullable', 'string', 'required_if:item_source,MANUAL', 'max:255'],
            'unit_price' => ['nullable', 'numeric', 'required_if:item_source,MANUAL', 'min:0', 'max:99999999.99'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_heavy' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}