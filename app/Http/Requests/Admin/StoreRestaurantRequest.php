<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreRestaurantRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $latitude = $this->normalizeCoordinateInput($this->input('latitude'));
        $longitude = $this->normalizeCoordinateInput($this->input('longitude'));

        if (is_numeric($latitude) && is_numeric($longitude)) {
            $lat = (float) $latitude;
            $lng = (float) $longitude;

            $looksSwapped = ($lat < -90 || $lat > 90)
                && ($lng >= -90 && $lng <= 90)
                && ($lat >= -180 && $lat <= 180);

            if ($looksSwapped) {
                [$latitude, $longitude] = [$longitude, $latitude];
            }
        }

        $this->merge([
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

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
            'slug' => ['nullable', 'string', 'max:255', 'unique:restaurants,slug'],
            'description' => ['nullable', 'string'],
            'address' => ['required', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'phone' => ['required', 'string', 'max:20'],
            'estimated_prep_time' => ['required', 'integer', 'min:1', 'max:240'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.between' => 'Latitude harus berada di antara -90 sampai 90.',
            'longitude.between' => 'Longitude harus berada di antara -180 sampai 180.',
        ];
    }

    private function normalizeCoordinateInput(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $normalized = str_replace(',', '.', trim($value));

        return $normalized;
    }
}
