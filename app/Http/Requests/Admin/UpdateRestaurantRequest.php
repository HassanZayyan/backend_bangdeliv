<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantRequest extends FormRequest
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
            'phone' => $this->normalizeNullableStringInput($this->input('phone')),
            'merchant_type' => $this->normalizeMerchantTypeInput(
                $this->input('merchant_type'),
                (string) ($this->route('restaurant')?->merchant_type ?? 'restaurant')
            ),
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
        $restaurantId = $this->route('restaurant')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('restaurants', 'slug')->ignore($restaurantId)],
            'merchant_type' => ['required', 'in:restaurant,warung,convenience_store,other'],
            'address' => ['required', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:20'],
            'banner_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_banner_image' => ['nullable', 'boolean'],
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
        if (! is_string($value)) {
            return $value;
        }

        return str_replace(',', '.', trim($value));
    }

    private function normalizeMerchantTypeInput(mixed $value, string $default): string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? $default : $normalized;
    }

    private function normalizeNullableStringInput(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }
}
