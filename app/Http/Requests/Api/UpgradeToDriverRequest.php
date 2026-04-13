<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpgradeToDriverRequest extends FormRequest
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
            'vehicle_plate' => [
                'required',
                'string',
                'max:20',
                Rule::unique('drivers', 'vehicle_plate')->whereNull('deleted_at'),
            ],
            'license_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('drivers', 'license_number')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vehicle_plate.unique' => 'Plat kendaraan sudah digunakan oleh driver lain.',
            'license_number.unique' => 'Nomor SIM sudah terdaftar pada driver lain.',
        ];
    }
}
