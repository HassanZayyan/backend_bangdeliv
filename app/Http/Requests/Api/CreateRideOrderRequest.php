<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CreateRideOrderRequest extends FormRequest
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
            'address_id' => ['required', 'integer', 'exists:addresses,id'],
            'destination_address' => ['required', 'string', 'max:1000'],
            'destination_latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
                'required_with:destination_longitude',
            ],
            'destination_longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
                'required_with:destination_latitude',
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
