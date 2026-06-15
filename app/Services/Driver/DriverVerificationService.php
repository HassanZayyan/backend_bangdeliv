<?php

namespace App\Services\Driver;

use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverVerificationService
{
    /**
     * @var array<int, string>
     */
    private const REQUIRED_DOCUMENT_TYPES = ['ktp', 'sim', 'selfie'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submitDocuments(User $actor, array $payload): array
    {
        if ($actor->role !== 'driver') {
            throw new ApiException('Hanya akun driver yang dapat mengunggah dokumen.', 403);
        }

        $driver = Driver::query()->where('user_id', $actor->id)->first();
        if (! $driver) {
            throw new ApiException('Profil driver tidak ditemukan.', 404);
        }

        $uploadedTypes = [];

        DB::transaction(function () use ($driver, $payload, &$uploadedTypes): void {
            $lockedDriver = Driver::query()->lockForUpdate()->find($driver->id);
            if (! $lockedDriver) {
                throw new ApiException('Profil driver tidak ditemukan.', 404);
            }

            foreach (self::REQUIRED_DOCUMENT_TYPES as $documentType) {
                $file = $payload[$documentType] ?? null;
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $storedPath = $this->storeDocumentFile($lockedDriver->id, $documentType, $file);

                DriverDocument::query()->updateOrCreate(
                    [
                        'driver_id' => $lockedDriver->id,
                        'document_type' => $documentType,
                    ],
                    [
                        'file_path' => $storedPath,
                        'verification_status' => 'pending',
                        'rejection_reason' => null,
                        'verified_at' => null,
                        'verified_by' => null,
                    ]
                );

                $uploadedTypes[] = $documentType;
            }

            if (empty($uploadedTypes)) {
                throw new ApiException('Minimal satu dokumen harus diunggah.', 422);
            }

            // Every re-submission should return the account to pending review state.
            $lockedDriver->update([
                'registration_status' => 'pending',
                'status' => 'offline',
            ]);
        });

        return $this->driverDetail($driver->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function myStatus(User $actor): array
    {
        if ($actor->role !== 'driver') {
            throw new ApiException('Endpoint ini hanya untuk driver.', 403);
        }

        $driver = Driver::query()->where('user_id', $actor->id)->value('id');
        if (! $driver) {
            throw new ApiException('Profil driver tidak ditemukan.', 404);
        }

        return $this->driverDetail((int) $driver);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function adminQueue(array $filters): LengthAwarePaginator
    {
        $query = Driver::query()
            ->with(['user:id,name,email,phone', 'driverDocuments'])
            ->latest('created_at');

        $queueFilter = strtolower(trim((string) ($filters['queue_filter'] ?? '')));

        if ($queueFilter === 'needs_revision') {
            $query->whereHas('driverDocuments', function (Builder $builder): void {
                $builder->where('verification_status', 'rejected');
            });
        }

        $statuses = $this->resolveStatuses($filters);
        if (! empty($statuses)) {
            $query->whereIn('registration_status', $statuses);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('vehicle_plate', 'like', "%{$search}%")
                    ->orWhere('license_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                        $userQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 10);
        $paginator = $query->paginate(max(1, min($perPage, 50)));

        $paginator->setCollection(
            $paginator->getCollection()->map(function (Driver $driver): array {
                return $this->buildVerificationPayload($driver, false);
            })
        );

        return $paginator;
    }

    /**
     * @return array<string, int>
     */
    public function queueSummaryCounts(): array
    {
        return [
            'pending' => Driver::query()->where('registration_status', 'pending')->count(),
            'needs_revision' => DriverDocument::query()
                ->where('verification_status', 'rejected')
                ->distinct('driver_id')
                ->count('driver_id'),
            'rejected' => Driver::query()->where('registration_status', 'rejected')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminShow(int $driverId): array
    {
        return $this->driverDetail($driverId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function reviewDocuments(User $admin, int $driverId, array $payload): array
    {
        if ($admin->role !== 'admin') {
            throw new ApiException('Hanya admin yang dapat melakukan verifikasi.', 403);
        }

        /** @var array<int, array<string, mixed>> $decisions */
        $decisions = $payload['documents'];

        DB::transaction(function () use ($driverId, $admin, $decisions): void {
            $driver = Driver::query()
                ->with('driverDocuments')
                ->lockForUpdate()
                ->find($driverId);

            if (! $driver) {
                throw new ApiException('Driver tidak ditemukan.', 404);
            }

            foreach ($decisions as $decision) {
                $type = (string) ($decision['document_type'] ?? '');
                $status = (string) ($decision['verification_status'] ?? '');
                $reason = isset($decision['rejection_reason']) ? trim((string) $decision['rejection_reason']) : null;

                $document = $driver->driverDocuments->firstWhere('document_type', $type);
                if (! $document) {
                    throw new ApiException("Dokumen {$type} belum diunggah oleh driver.", 422);
                }

                $document->update([
                    'verification_status' => $status,
                    'rejection_reason' => $status === 'rejected' ? $reason : null,
                    'verified_at' => now(),
                    'verified_by' => $admin->id,
                ]);
            }

            $driver->load('driverDocuments');
            $nextRegistrationStatus = $this->determineRegistrationStatus($driver->driverDocuments);

            $updates = ['registration_status' => $nextRegistrationStatus];
            if ($nextRegistrationStatus !== 'active') {
                $updates['status'] = 'offline';
            }

            $driver->update($updates);
        });

        return $this->driverDetail($driverId);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteDocument(User $admin, int $driverId, string $documentType): array
    {
        if ($admin->role !== 'admin') {
            throw new ApiException('Hanya admin yang dapat menghapus dokumen verifikasi.', 403);
        }

        $normalizedDocumentType = strtolower(trim($documentType));
        if (! in_array($normalizedDocumentType, self::REQUIRED_DOCUMENT_TYPES, true)) {
            throw new ApiException('Tipe dokumen tidak valid.', 422);
        }

        DB::transaction(function () use ($driverId, $normalizedDocumentType): void {
            $driver = Driver::query()
                ->with('driverDocuments')
                ->lockForUpdate()
                ->find($driverId);

            if (! $driver) {
                throw new ApiException('Driver tidak ditemukan.', 404);
            }

            /** @var DriverDocument|null $document */
            $document = $driver->driverDocuments->firstWhere('document_type', $normalizedDocumentType);
            if (! $document) {
                throw new ApiException('Dokumen tidak ditemukan.', 404);
            }

            if (! empty($document->file_path)) {
                Storage::disk('public')->delete($document->file_path);
            }

            $document->delete();

            $driver->load('driverDocuments');
            $nextRegistrationStatus = $this->determineRegistrationStatus($driver->driverDocuments);

            $updates = ['registration_status' => $nextRegistrationStatus];
            if ($nextRegistrationStatus !== 'active') {
                $updates['status'] = 'offline';
            }

            $driver->update($updates);
        });

        return $this->driverDetail($driverId);
    }

    public function adminPreviewDocument(
        User $admin,
        int $driverId,
        string $documentType
    ): StreamedResponse {
        if ($admin->role !== 'admin') {
            throw new ApiException('Hanya admin yang dapat melihat dokumen verifikasi.', 403);
        }

        $normalizedDocumentType = strtolower(trim($documentType));
        if (! in_array($normalizedDocumentType, self::REQUIRED_DOCUMENT_TYPES, true)) {
            throw new ApiException('Tipe dokumen tidak valid.', 422);
        }

        $driver = Driver::query()
            ->with('driverDocuments')
            ->find($driverId);

        if (! $driver) {
            throw new ApiException('Driver tidak ditemukan.', 404);
        }

        /** @var DriverDocument|null $document */
        $document = $driver->driverDocuments->firstWhere('document_type', $normalizedDocumentType);
        if (! $document || empty($document->file_path)) {
            throw new ApiException('Dokumen belum tersedia.', 404);
        }

        $filePath = (string) $document->file_path;
        if (! Storage::disk('public')->exists($filePath)) {
            throw new ApiException('File dokumen tidak ditemukan di storage.', 404);
        }

        return Storage::disk('public')->response($filePath);
    }

    /**
     * @return array<string, mixed>
     */
    private function driverDetail(int $driverId): array
    {
        $driver = Driver::query()
            ->with(['user:id,name,email,phone', 'driverDocuments.verifier:id,name'])
            ->find($driverId);

        if (! $driver) {
            throw new ApiException('Driver tidak ditemukan.', 404);
        }

        return $this->buildVerificationPayload($driver, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVerificationPayload(Driver $driver, bool $includeVerifier): array
    {
        $documentsByType = $driver->driverDocuments
            ->keyBy('document_type');

        $documents = collect(self::REQUIRED_DOCUMENT_TYPES)
            ->map(function (string $documentType) use ($documentsByType, $includeVerifier): array {
                /** @var DriverDocument|null $doc */
                $doc = $documentsByType->get($documentType);

                $fileExists = ! empty($doc?->file_path)
                    ? Storage::disk('public')->exists((string) $doc->file_path)
                    : false;

                $fileUrl = null;
                if ($doc?->file_path && $fileExists) {
                    $fileUrl = Storage::disk('public')->url($doc->file_path);
                }

                $payload = [
                    'document_type' => $documentType,
                    'is_uploaded' => (bool) $doc,
                    'file_path' => $doc?->file_path,
                    'file_url' => $fileUrl,
                    'file_exists' => $fileExists,
                    'verification_status' => $doc?->verification_status ?? 'pending',
                    'rejection_reason' => $doc?->rejection_reason,
                    'verified_at' => $doc?->verified_at,
                ];

                if ($includeVerifier) {
                    $payload['verified_by'] = $doc?->verifier?->name;
                }

                return $payload;
            })
            ->values()
            ->all();

        return [
            'driver' => [
                'id' => $driver->id,
                'name' => $driver->user?->name,
                'email' => $driver->user?->email,
                'phone' => $driver->user?->phone,
                'vehicle_plate' => $driver->vehicle_plate,
                'vehicle_type' => $driver->vehicle_type,
                'vehicle_brand' => $driver->vehicle_brand,
                'vehicle_model' => $driver->vehicle_model,
                'license_number' => $driver->license_number,
                'registration_status' => $driver->registration_status,
                'status' => $driver->status,
                'submitted_at' => $driver->created_at,
                'updated_at' => $driver->updated_at,
            ],
            'documents' => $documents,
        ];
    }

    /**
     * @param  Collection<int, DriverDocument>  $documents
     */
    private function determineRegistrationStatus(Collection $documents): string
    {
        $requiredStatuses = [];

        foreach (self::REQUIRED_DOCUMENT_TYPES as $documentType) {
            /** @var DriverDocument|null $document */
            $document = $documents->firstWhere('document_type', $documentType);
            if (! $document) {
                return 'pending';
            }

            $requiredStatuses[] = $document->verification_status;
        }

        if (in_array('pending', $requiredStatuses, true)) {
            return 'pending';
        }

        if (in_array('rejected', $requiredStatuses, true)) {
            return 'rejected';
        }

        return 'active';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    private function resolveStatuses(array $filters): array
    {
        $allowed = ['pending', 'active', 'rejected', 'suspended'];

        $queueFilter = strtolower(trim((string) ($filters['queue_filter'] ?? '')));
        if (in_array($queueFilter, ['pending', 'active', 'rejected', 'suspended'], true)) {
            return [$queueFilter];
        }

        if ($queueFilter === 'all') {
            return [];
        }

        $rawStatuses = [];
        if (isset($filters['statuses']) && is_array($filters['statuses'])) {
            $rawStatuses = $filters['statuses'];
        } elseif (! empty($filters['status']) && is_string($filters['status'])) {
            $rawStatuses = explode(',', $filters['status']);
        }

        $statuses = collect($rawStatuses)
            ->map(fn ($status): string => strtolower(trim((string) $status)))
            ->filter(fn (string $status): bool => in_array($status, $allowed, true))
            ->unique()
            ->values()
            ->all();

        if (empty($statuses)) {
            return ['pending', 'rejected'];
        }

        return $statuses;
    }

    private function storeDocumentFile(int $driverId, string $documentType, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = Str::uuid()->toString().'.'.$extension;

        return $file->storeAs(
            'driver-documents/'.$driverId.'/'.$documentType,
            $filename,
            'public'
        );
    }
}
