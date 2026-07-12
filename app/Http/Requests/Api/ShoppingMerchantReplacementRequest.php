<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ShoppingMerchantReplacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:0'],
            'merchant_id' => ['nullable', 'integer', 'exists:restaurants,id', 'required_without:merchant_place'],
            'merchant_place' => ['nullable', 'array', 'required_without:merchant_id'],
            'merchant_place.place_id' => ['required_with:merchant_place', 'string', 'max:255'],
            'merchant_place.name' => ['nullable', 'string', 'max:255'],
            'merchant_place.address' => ['nullable', 'string', 'max:1000'],
            'merchant_place.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'merchant_place.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'merchant_place.types' => ['nullable', 'array', 'max:12'],
            'merchant_place.types.*' => ['string', 'max:80'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.item_source' => ['required', 'in:MANUAL,MENU_DB'],
            'items.*.menu_id' => ['nullable', 'integer', 'exists:menus,id'],
            'items.*.menu_name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
