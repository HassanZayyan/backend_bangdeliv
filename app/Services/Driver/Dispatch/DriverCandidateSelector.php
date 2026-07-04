<?php

namespace App\Services\Driver\Dispatch;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DriverCandidateSelector
{
    public function __construct(private readonly DriverDispatchMetadataFactory $metadataFactory) {}

    /**
     * @return array<int, array{driver: Driver, dispatch: array<string, mixed>}>
     */
    public function candidatesForOrder(Order $order): array
    {
        $drivers = $this->availableDriverQuery($order)->get();
        $candidates = [];

        foreach ($drivers as $driver) {
            $candidates[] = [
                'driver' => $driver,
                'dispatch' => $this->metadataFactory->forDriver($order, $driver),
            ];
        }

        usort($candidates, function (array $left, array $right): int {
            return $this->compareCandidates($left, $right);
        });

        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['dispatch'] = $this->metadataFactory->forDriver(
                $order,
                $candidate['driver'],
                $index + 1,
            );
        }

        return $candidates;
    }

    /**
     * @return array<string, mixed>
     */
    public function dispatchForDriver(Order $order, Driver $driver): array
    {
        foreach ($this->candidatesForOrder($order) as $candidate) {
            if ((int) $candidate['driver']->id === (int) $driver->id) {
                return $candidate['dispatch'];
            }
        }

        return $this->metadataFactory->forDriver($order, $driver);
    }

    /**
     * @return Builder<Driver>
     */
    public function availableDriverQuery(?Order $order = null): Builder
    {
        $rejectedDriverUserIds = $order === null
            ? []
            : OrderLog::query()
                ->where('order_id', $order->id)
                ->where('event_type', 'DRIVER_REJECT')
                ->whereNotNull('changed_by_user_id')
                ->pluck('changed_by_user_id')
                ->map(fn ($userId): int => (int) $userId)
                ->filter(fn (int $userId): bool => $userId > 0)
                ->values()
                ->all();

        return Driver::query()
            ->where('registration_status', 'active')
            ->where('status', 'available')
            ->when($rejectedDriverUserIds !== [], function ($query) use ($rejectedDriverUserIds): void {
                $query->whereNotIn('user_id', $rejectedDriverUserIds);
            })
            ->whereHas('user', function ($query): void {
                $query
                    ->where('role', 'driver')
                    ->where('is_active', true)
                    ->where('is_blacklisted', false);
            })
            ->whereNotNull('user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnosticsForOrder(Order $order): array
    {
        $drivers = Driver::query()
            ->with('user')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get();

        $candidateDriverIds = $this->availableDriverQuery($order)
            ->pluck('id')
            ->map(fn ($driverId): int => (int) $driverId)
            ->values()
            ->all();
        $candidateDriverIdSet = array_flip($candidateDriverIds);

        $driversSummary = [];
        $reasonCounts = [];
        $candidateCount = 0;

        foreach ($drivers as $driver) {
            $explanation = $this->explainDriverForOrder($order, $driver);
            $isCandidate = isset($candidateDriverIdSet[(int) $driver->id]);
            $explanation['is_candidate'] = $isCandidate;

            if ($isCandidate) {
                $candidateCount++;
            } else {
                foreach ($explanation['reasons'] as $reason) {
                    $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                }
            }

            $driversSummary[] = $explanation;
        }

        return [
            'order_id' => (int) $order->id,
            'driver_count' => $drivers->count(),
            'candidate_count' => $candidateCount,
            'skipped_count' => max(0, $drivers->count() - $candidateCount),
            'skipped_reason_counts' => $reasonCounts,
            'drivers' => $driversSummary,
        ];
    }

    /**
     * @return array{
     *     driver_id:int,
     *     driver_user_id:int|null,
     *     driver_status:string,
     *     registration_status:string,
     *     user_role:string|null,
     *     user_active:bool|null,
     *     user_blacklisted:bool|null,
     *     is_candidate:bool,
     *     reasons:list<string>
     * }
     */
    public function explainDriverForOrder(Order $order, Driver $driver): array
    {
        $driver->loadMissing('user');
        $user = $driver->user;
        $reasons = [];

        if ((int) ($driver->user_id ?? 0) <= 0 || ! $user instanceof User) {
            $reasons[] = 'driver_user_missing';
        }

        if (strtolower((string) $driver->registration_status) !== 'active') {
            $reasons[] = 'registration_not_active';
        }

        if (strtolower((string) $driver->status) !== 'available') {
            $reasons[] = 'driver_not_available_'.$this->reasonSuffix((string) $driver->status);
        }

        if ($user instanceof User) {
            if ((string) $user->role !== 'driver') {
                $reasons[] = 'user_role_not_driver';
            }
            if (! (bool) $user->is_active) {
                $reasons[] = 'user_inactive';
            }
            if ((bool) $user->is_blacklisted) {
                $reasons[] = 'user_blacklisted';
            }
            if ($this->hasRejectedOrder($order, (int) $user->id)) {
                $reasons[] = 'driver_rejected_order';
            }
        }

        return [
            'driver_id' => (int) $driver->id,
            'driver_user_id' => $driver->user_id !== null ? (int) $driver->user_id : null,
            'driver_status' => (string) $driver->status,
            'registration_status' => (string) $driver->registration_status,
            'user_role' => $user instanceof User ? (string) $user->role : null,
            'user_active' => $user instanceof User ? (bool) $user->is_active : null,
            'user_blacklisted' => $user instanceof User ? (bool) $user->is_blacklisted : null,
            'is_candidate' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array{driver: Driver, dispatch: array<string, mixed>}  $left
     * @param  array{driver: Driver, dispatch: array<string, mixed>}  $right
     */
    private function compareCandidates(array $left, array $right): int
    {
        $leftDistance = $left['dispatch']['distance_to_pickup_meters'] ?? null;
        $rightDistance = $right['dispatch']['distance_to_pickup_meters'] ?? null;
        $leftKnown = is_numeric($leftDistance);
        $rightKnown = is_numeric($rightDistance);

        if ($leftKnown !== $rightKnown) {
            return $leftKnown ? -1 : 1;
        }

        if ($leftKnown && $rightKnown && (int) $leftDistance !== (int) $rightDistance) {
            return (int) $leftDistance <=> (int) $rightDistance;
        }

        $leftUpdatedAt = $left['driver']->location_updated_at?->getTimestamp() ?? 0;
        $rightUpdatedAt = $right['driver']->location_updated_at?->getTimestamp() ?? 0;
        if ($leftUpdatedAt !== $rightUpdatedAt) {
            return $rightUpdatedAt <=> $leftUpdatedAt;
        }

        return (int) $left['driver']->id <=> (int) $right['driver']->id;
    }

    private function hasRejectedOrder(Order $order, int $driverUserId): bool
    {
        if ($driverUserId <= 0) {
            return false;
        }

        return OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'DRIVER_REJECT')
            ->where('changed_by_user_id', $driverUserId)
            ->exists();
    }

    private function reasonSuffix(string $value): string
    {
        $suffix = strtolower(trim($value));
        $suffix = preg_replace('/[^a-z0-9]+/', '_', $suffix) ?: 'unknown';

        return trim($suffix, '_') ?: 'unknown';
    }
}
