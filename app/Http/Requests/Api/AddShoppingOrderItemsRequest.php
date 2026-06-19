<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AddShoppingOrderItemsRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.merchant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'items.*.merchant_place' => ['nullable', 'array'],
            'items.*.merchant_place.place_id' => ['nullable', 'string', 'max:255'],
            'items.*.merchant_place.name' => ['required_with:items.*.merchant_place', 'string', 'max:255'],
            'items.*.merchant_place.address' => ['required_with:items.*.merchant_place', 'string', 'max:1000'],
            'items.*.merchant_place.latitude' => ['required_with:items.*.merchant_place', 'numeric', 'between:-90,90'],
            'items.*.merchant_place.longitude' => ['required_with:items.*.merchant_place', 'numeric', 'between:-180,180'],
            'items.*.merchant_place.types' => ['nullable', 'array', 'max:12'],
            'items.*.merchant_place.types.*' => ['string', 'max:80'],
            'items.*.item_source' => ['required', 'in:MANUAL,MENU_DB'],
            'items.*.menu_id' => ['nullable', 'integer', 'exists:menus,id'],
            'items.*.menu_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
