<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDriverShoppingItemsRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'items.*.is_available' => ['nullable', 'boolean'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.is_heavy' => ['nullable', 'boolean'],
            'receipt_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
