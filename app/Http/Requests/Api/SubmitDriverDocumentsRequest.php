<?php

namespace App\Http\Requests\Api;

use App\Models\Driver;
use App\Models\DriverDocument;
use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class SubmitDriverDocumentsRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const REQUIRED_DOCUMENT_TYPES = ['ktp', 'sim', 'selfie'];

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
            if ($validator->errors()->any()) {
                return;
            }

            if (! $this->hasAnyDocumentFile()) {
                $validator->errors()->add('documents', 'Pilih dokumen yang perlu diunggah.');

                return;
            }

            $driver = Driver::query()
                ->with('driverDocuments')
                ->where('user_id', $this->user()?->id)
                ->first();

            if (! $driver) {
                return;
            }

            $documentsByType = $driver->driverDocuments->keyBy('document_type');
            $missingDocumentTypes = collect(self::REQUIRED_DOCUMENT_TYPES)
                ->reject(function (string $documentType) use ($documentsByType): bool {
                    if ($this->hasFile($documentType)) {
                        return true;
                    }

                    /** @var DriverDocument|null $document */
                    $document = $documentsByType->get($documentType);

                    return $document !== null
                        && ! empty($document->file_path)
                        && $document->verification_status !== 'rejected';
                })
                ->values()
                ->all();

            if (! empty($missingDocumentTypes)) {
                $validator->errors()->add(
                    'documents',
                    'Dokumen '.$this->formatDocumentTypeList($missingDocumentTypes).' wajib diunggah.'
                );
            }
        });
    }

    protected function failedValidation(ValidationContract $validator): void
    {
        $errors = $validator->errors();

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $errors->first() ?: 'Data dokumen tidak valid.',
            'errors' => $errors->toArray(),
        ], 422));
    }

    private function hasAnyDocumentFile(): bool
    {
        foreach (self::REQUIRED_DOCUMENT_TYPES as $documentType) {
            if ($this->hasFile($documentType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $documentTypes
     */
    private function formatDocumentTypeList(array $documentTypes): string
    {
        $labels = collect($documentTypes)
            ->map(fn (string $type): string => $this->documentTypeLabel($type))
            ->values()
            ->all();

        if (count($labels) <= 1) {
            return $labels[0] ?? 'verifikasi';
        }

        if (count($labels) === 2) {
            return $labels[0].' dan '.$labels[1];
        }

        $lastLabel = array_pop($labels);

        return implode(', ', $labels).', dan '.$lastLabel;
    }

    private function documentTypeLabel(string $documentType): string
    {
        return match ($documentType) {
            'ktp' => 'KTP',
            'sim' => 'SIM',
            'selfie' => 'Selfie',
            default => strtoupper($documentType),
        };
    }
}
