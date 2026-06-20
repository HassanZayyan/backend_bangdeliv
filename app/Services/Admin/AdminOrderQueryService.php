<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AdminOrderQueryService
{
    public function __construct(
        private readonly AdminPaymentProofStatusService $proofStatuses,
        private readonly AdminServiceTypePresenter $serviceTypes,
        private readonly AdminOrderStatusPresenter $statuses,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function indexData(Request $request): array
    {
        $serviceFilters = $this->serviceTypes->filters();
        $statusFilters = $this->statuses->filters();
        $serviceTypeCodeToId = ServiceType::query()->pluck('id', 'code');
        $statusCodeToId = OrderStatus::query()->pluck('id', 'code');

        $selectedService = $this->serviceTypes->normalizeFilter((string) $request->query('service', 'all'));
        $selectedStatus = $this->statuses->normalizeFilter((string) $request->query('status', 'all'));
        $selectedServiceTypeId = $this->serviceTypes->idForFilter($selectedService, $serviceTypeCodeToId);
        $statusIdsByFilter = $this->statuses->idsByFilter($statusCodeToId);
        $search = trim((string) $request->query('q', ''));

        $ordersQuery = Order::query()
            ->with([
                'user:id,name,phone',
                'restaurant',
                'driver.user:id,name,phone',
                'statusRef:id,code,display_name,is_terminal,sort_order',
                'serviceType:id,code,display_name',
                'courierOrder',
                'rideOrder',
                'orderLocations.restaurant:id,name',
                'payment',
                'evidences',
                'logs' => fn ($query) => $this->proofStatuses->constrainDecisionLogs($query),
            ]);

        $this->applyServiceFilter($ordersQuery, $selectedService, $selectedServiceTypeId);
        $this->applyStatusFilter($ordersQuery, $selectedStatus, $statusIdsByFilter);
        $this->applySearch($ordersQuery, $search);

        $orders = $ordersQuery
            ->latest('created_at')
            ->paginate(AdminPagination::PER_PAGE)
            ->withQueryString();

        $orders->getCollection()->each(function (Order $order): void {
            $order->setAttribute('pending_payment_proof_count', $this->proofStatuses->pendingCountForOrder($order));
        });

        return [
            'orders' => $orders,
            'statusFilters' => $statusFilters,
            'selectedService' => $selectedService,
            'selectedStatus' => $selectedStatus,
            'statusCounts' => $this->statusCounts($selectedService, $selectedServiceTypeId, $statusFilters, $statusIdsByFilter),
            'search' => $search,
            'searchPlaceholder' => $this->serviceTypes->searchPlaceholder($selectedService),
            'serviceBadgeMap' => $this->serviceTypes->badgeMap(),
            'statusMap' => $this->statuses->badgeMap(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailData(Order $order, Request $request): array
    {
        $order->load([
            'user:id,name,phone,email',
            'driver.user:id,name,phone',
            'restaurant',
            'serviceType:id,code,display_name',
            'statusRef:id,code,display_name,is_terminal,sort_order',
            'items',
            'shoppingReceipt',
            'courierOrder',
            'rideOrder',
            'orderLocations.restaurant:id,name',
            'evidences.user:id,name,phone',
            'payments.recordedBy:id,name',
            'payments.driver.user:id,name',
            'statusHistories.statusRef:id,code,display_name,is_terminal,sort_order',
            'statusHistories.changedBy:id,name',
            'logs' => fn ($query) => $this->proofStatuses->constrainDecisionLogs($query),
        ]);

        $backUrl = $request->query('back');
        if (! is_string($backUrl) || ! str_starts_with($backUrl, url('/admin/pesanan'))) {
            $backUrl = route('admin.orders.index');
        }

        $paymentProofs = $order->evidences
            ->filter(fn ($evidence): bool => strtoupper((string) $evidence->evidence_type) === AdminPaymentProofStatusService::PAYMENT_TRANSFER_EVIDENCE_TYPE)
            ->sortByDesc(fn ($evidence): int => $evidence->uploaded_at?->getTimestamp() ?? $evidence->created_at?->getTimestamp() ?? 0)
            ->values();

        $payments = $order->payments
            ->sortByDesc(fn ($payment): int => $payment->paid_at?->getTimestamp() ?? $payment->created_at?->getTimestamp() ?? 0)
            ->values();
        $proofDecisionLogs = $this->proofStatuses->decisionLogsForOrder($order);
        $latestPayment = $payments->first();
        $paymentProofDecisions = $paymentProofs
            ->mapWithKeys(fn ($proof): array => [
                $proof->id => $this->proofStatuses->decisionFor($proof, $latestPayment, $proofDecisionLogs),
            ])
            ->all();

        return [
            'order' => $order,
            'backUrl' => $backUrl,
            'serviceConfig' => $this->serviceTypes->badgeConfig((string) ($order->serviceType?->code ?? 'UNKNOWN')),
            'statusConfig' => $this->statuses->badgeConfig((string) ($order->statusRef?->code ?? 'UNKNOWN')),
            'locations' => $order->orderLocations->sortBy('sequence_no')->values(),
            'statusHistories' => $order->statusHistories->sortByDesc('created_at')->values(),
            'latestPayment' => $latestPayment,
            'paymentProofs' => $paymentProofs,
            'paymentProofDecisions' => $paymentProofDecisions,
            'statusMap' => $this->statuses->badgeMap(),
        ];
    }

    private function applyServiceFilter(Builder $query, string $selectedService, ?int $selectedServiceTypeId): void
    {
        if ($selectedService === 'all') {
            return;
        }

        $selectedServiceTypeId !== null
            ? $query->where('service_type_id', $selectedServiceTypeId)
            : $query->whereRaw('1 = 0');
    }

    /**
     * @param  array<string, array<int, int>>  $statusIdsByFilter
     */
    private function applyStatusFilter(Builder $query, string $selectedStatus, array $statusIdsByFilter): void
    {
        if ($selectedStatus === 'all') {
            return;
        }

        $statusIds = $statusIdsByFilter[$selectedStatus] ?? [];
        empty($statusIds)
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('status_id', $statusIds);
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $query) use ($search): void {
            $query->where('order_number', 'like', "%{$search}%")
                ->orWhereHas('orderLocations', function (Builder $locationQuery) use ($search): void {
                    $locationQuery
                        ->where('full_address', 'like', "%{$search}%")
                        ->orWhere('label', 'like', "%{$search}%");
                })
                ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                    $userQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                })
                ->orWhereHas('restaurant', function (Builder $restaurantQuery) use ($search): void {
                    $restaurantQuery->where('name', 'like', "%{$search}%");
                })
                ->orWhereHas('courierOrder', function (Builder $courierQuery) use ($search): void {
                    $courierQuery->where('package_description', 'like', "%{$search}%");
                });
        });
    }

    /**
     * @param  array<string, array<string, mixed>>  $statusFilters
     * @param  array<string, array<int, int>>  $statusIdsByFilter
     * @return array<string, int>
     */
    private function statusCounts(string $selectedService, ?int $selectedServiceTypeId, array $statusFilters, array $statusIdsByFilter): array
    {
        $baseQuery = Order::query();
        $this->applyServiceFilter($baseQuery, $selectedService, $selectedServiceTypeId);

        $counts = [];
        foreach ($statusFilters as $key => $config) {
            $query = clone $baseQuery;
            $this->applyStatusFilter($query, $key, $statusIdsByFilter);
            $counts[$key] = $query->count();
        }

        return $counts;
    }

}
