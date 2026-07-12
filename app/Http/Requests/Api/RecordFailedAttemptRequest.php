<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RecordFailedAttemptRequest extends FormRequest
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
            'failure_type' => ['required', 'in:DRIVER_ASSIGNMENT,PICKUP,DELIVERY'],
            'reason' => ['required', 'string', 'max:500'],
            'pickup_location_id' => ['nullable', 'integer', 'exists:order_locations,id'],
            'merchant_closed_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
