<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminReviewDriverDocumentsRequest extends FormRequest
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
            'documents' => ['required', 'array', 'min:1'],
            'documents.*.document_type' => ['required', 'string', 'in:ktp,sim,selfie', 'distinct'],
            'documents.*.verification_status' => ['required', 'string', 'in:approved,rejected'],
            'documents.*.rejection_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $documents = $this->input('documents', []);
            if (! is_array($documents)) {
                return;
            }

            foreach ($documents as $index => $document) {
                $status = strtolower(trim((string) ($document['verification_status'] ?? '')));
                $reason = trim((string) ($document['rejection_reason'] ?? ''));

                if ($status === 'rejected' && $reason === '') {
                    $validator->errors()->add(
                        "documents.{$index}.rejection_reason",
                        'Alasan penolakan wajib diisi jika status dokumen rejected.'
                    );
                }
            }
        });
    }
}
