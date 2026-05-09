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

    protected function prepareForValidation(): void
    {
        $this->merge([
            'vehicle_type' => trim((string) $this->input('vehicle_type', '')),
            'vehicle_brand' => trim((string) $this->input('vehicle_brand', '')),
            'vehicle_model' => trim((string) $this->input('vehicle_model', '')),
            'vehicle_plate' => trim((string) $this->input('vehicle_plate', '')),
            'license_number' => trim((string) $this->input('license_number', '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vehicle_type' => [
                'required',
                'string',
                'max:50',
            ],
            'vehicle_brand' => [
                'required',
                'string',
                'max:50',
            ],
            'vehicle_model' => [
                'required',
                'string',
                'max:100',
            ],
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
            'vehicle_type.required' => 'Jenis kendaraan wajib dipilih.',
            'vehicle_brand.required' => 'Merk kendaraan wajib dipilih.',
            'vehicle_model.required' => 'Model kendaraan wajib diisi.',
            'vehicle_plate.unique' => 'Plat kendaraan sudah digunakan oleh driver lain.',
            'license_number.unique' => 'Nomor SIM sudah terdaftar pada driver lain.',
        ];
    }
}
