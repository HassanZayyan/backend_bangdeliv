<?php

namespace App\Services\Driver\Dispatch;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatusHistory;
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
            : OrderStatusHistory::query()
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
}
