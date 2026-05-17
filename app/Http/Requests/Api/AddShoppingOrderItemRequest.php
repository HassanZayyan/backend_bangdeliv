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
            'merchant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'replacement_for_pickup_location_id' => ['nullable', 'integer', 'exists:order_locations,id'],
            'item_source' => ['required', 'in:MANUAL'],
            'menu_name' => ['nullable', 'string', 'required_if:item_source,MANUAL', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
