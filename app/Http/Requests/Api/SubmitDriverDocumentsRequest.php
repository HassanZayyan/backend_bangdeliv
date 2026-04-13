<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubmitDriverDocumentsRequest extends FormRequest
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
        $fileRules = ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'];

        return [
            'ktp' => $fileRules,
            'sim' => $fileRules,
            'selfie' => $fileRules,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (
                !$this->hasFile('ktp')
                && !$this->hasFile('sim')
                && !$this->hasFile('selfie')
            ) {
                $validator->errors()->add('documents', 'Minimal satu dokumen harus diunggah.');
            }
        });
    }
}
