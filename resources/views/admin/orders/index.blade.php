@extends('layouts.admin')

@section('title', 'Pesanan - Admin BangDeliv')
@section('page-title', 'Manajemen Pesanan')

@section('content')
@php
    $serviceTypeCodeToId = \App\Models\ServiceType::query()->pluck('id', 'code');
    $serviceFilters = [
        'all' => ['label' => 'Semua Layanan', 'code' => null, 'badge_class' => 'badge-info'],
        'shopping' => ['label' => 'Titip Belanja', 'code' => 'SHOPPING', 'badge_class' => 'badge-warning'],
        'courier' => ['label' => 'Kurir', 'code' => 'COURIER', 'badge_class' => 'badge-info'],
        'ride' => ['label' => 'Antar Jemput', 'code' => 'RIDE', 'badge_class' => 'badge-success'],
    ];

    $selectedService = (string) request()->query('service', 'all');
    if (!array_key_exists($selectedService, $serviceFilters)) {
        $selectedService = 'all';
    }

    $selectedServiceCode = $serviceFilters[$selectedService]['code'];
    $selectedServiceTypeId = $selectedServiceCode ? ($serviceTypeCodeToId[$selectedServiceCode] ?? null) : null;
    $isCourierView = $selectedService === 'courier';
    $isRideView = $selectedService === 'ride';

    if ($isCourierView) {
        $searchPlaceholder = 'Cari ID, pelanggan, deskripsi paket, komplain...';
    } elseif ($isRideView) {
        $searchPlaceholder = 'Cari ID, pelanggan, catatan perjalanan...';
    } else {
        $searchPlaceholder = 'Cari ID, pelanggan, alamat, resto, paket...';
    }

    $serviceCounts = [
        'all' => \App\Models\Order::count(),
        'shopping' => isset($serviceTypeCodeToId['SHOPPING'])
            ? \App\Models\Order::where('service_type_id', $serviceTypeCodeToId['SHOPPING'])->count()
            : 0,
        'courier' => isset($serviceTypeCodeToId['COURIER'])
            ? \App\Models\Order::where('service_type_id', $serviceTypeCodeToId['COURIER'])->count()
            : 0,
        'ride' => isset($serviceTypeCodeToId['RIDE'])
            ? \App\Models\Order::where('service_type_id', $serviceTypeCodeToId['RIDE'])->count()
            : 0,
    ];

    $statusCodeToId = \App\Models\OrderStatus::query()->pluck('id', 'code');
    $statusFilters = [
        'all' => ['label' => 'Semua', 'codes' => null, 'badge_class' => null],
        'pending' => ['label' => 'Menunggu Driver', 'codes' => ['PENDING'], 'badge_class' => 'badge-warning'],
        'driver_assigned' => ['label' => 'Driver Ditugaskan', 'codes' => ['DRIVER_ASSIGNED', 'PICKED_UP'], 'badge_class' => 'badge-info'],
        'on_the_way' => ['label' => 'Diantar', 'codes' => ['ON_THE_WAY'], 'badge_class' => 'badge-info'],
        'completed' => ['label' => 'Selesai', 'codes' => ['DELIVERED', 'COMPLETED'], 'badge_class' => 'badge-success'],
        'cancelled' => ['label' => 'Batal', 'codes' => ['CANCELLED', 'CANCELLED_WITH_FEE'], 'badge_class' => 'badge-danger'],
    ];

    $selectedStatus = (string) request()->query('status', 'all');
    if (!array_key_exists($selectedStatus, $statusFilters)) {
        $selectedStatus = 'all';
    }

    $statusIdsByFilter = [];
    foreach ($statusFilters as $statusKey => $config) {
        $statusIdsByFilter[$statusKey] = collect($config['codes'] ?? [])
            ->map(fn ($code) => $statusCodeToId[$code] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    $statusCountBaseQuery = \App\Models\Order::query();
    if ($selectedService !== 'all') {
        if ($selectedServiceTypeId) {
            $statusCountBaseQuery->where('service_type_id', $selectedServiceTypeId);
        } else {
            $statusCountBaseQuery->whereRaw('1 = 0');
        }
    }

    $statusCounts = [];
    foreach ($statusFilters as $statusKey => $config) {
        $query = clone $statusCountBaseQuery;

        if ($statusKey !== 'all') {
            $statusIds = $statusIdsByFilter[$statusKey] ?? [];
            if (empty($statusIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('status_id', $statusIds);
            }
        }

        $statusCounts[$statusKey] = $query->count();
    }

    $search = trim((string) request()->query('q', ''));

    $ordersQuery = \App\Models\Order::query()
        ->with(['user', 'restaurant', 'driver.user', 'statusRef', 'serviceType', 'courierOrder', 'rideOrder']);

    if ($selectedService !== 'all') {
        if ($selectedServiceTypeId) {
            $ordersQuery->where('service_type_id', $selectedServiceTypeId);
        } else {
            $ordersQuery->whereRaw('1 = 0');
        }
    }

    if ($selectedStatus !== 'all') {
        $statusIds = $statusIdsByFilter[$selectedStatus] ?? [];
        if (empty($statusIds)) {
            $ordersQuery->whereRaw('1 = 0');
        } else {
            $ordersQuery->whereIn('status_id', $statusIds);
        }
    }

    if ($search !== '') {
        $ordersQuery->where(function ($query) use ($search) {
            $query->where('order_number', 'like', "%{$search}%")
                ->orWhere('delivery_address', 'like', "%{$search}%")
                ->orWhereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                })
                ->orWhereHas('restaurant', function ($restaurantQuery) use ($search) {
                    $restaurantQuery->where('name', 'like', "%{$search}%");
                })
                ->orWhereHas('courierOrder', function ($courierQuery) use ($search) {
                    $courierQuery->where('package_description', 'like', "%{$search}%")
                        ->orWhere('complaint_reason', 'like', "%{$search}%");
                })
                ->orWhereHas('rideOrder', function ($rideQuery) use ($search) {
                    $rideQuery->where('notes', 'like', "%{$search}%");
                });
        });
    }

    $orders = $ordersQuery
        ->latest('created_at')
        ->paginate(20)
        ->withQueryString();

    $buildQuery = function (array $overrides): array {
        $query = array_merge(request()->except('page'), $overrides);
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            }
        }

        return $query;
    };

    $serviceBadgeMap = [
        'SHOPPING' => ['label' => 'Titip Belanja', 'class' => 'badge-warning'],
        'COURIER' => ['label' => 'Kurir', 'class' => 'badge-info'],
        'RIDE' => ['label' => 'Antar Jemput', 'class' => 'badge-success'],
    ];

    $statusMap = [
        'PENDING' => ['label' => 'Menunggu Driver', 'class' => 'badge-warning'],
        'DRIVER_ASSIGNED' => ['label' => 'Driver Ditugaskan', 'class' => 'badge-info'],
        'PICKED_UP' => ['label' => 'Pickup', 'class' => 'badge-info'],
        'ON_THE_WAY' => ['label' => 'Diantar', 'class' => 'badge-info'],
        'DELIVERED' => ['label' => 'Terkirim', 'class' => 'badge-success'],
        'COMPLETED' => ['label' => 'Selesai', 'class' => 'badge-success'],
        'CANCELLED' => ['label' => 'Dibatalkan', 'class' => 'badge-danger'],
        'CANCELLED_WITH_FEE' => ['label' => 'Batal Dengan Biaya', 'class' => 'badge-danger'],
        'COMPLAINT' => ['label' => 'Komplain', 'class' => 'badge-danger'],
    ];

    $resultStart = $orders->firstItem() ?? 0;
    $resultEnd = $orders->lastItem() ?? 0;
    $emptyColspan = $isCourierView ? 11 : ($isRideView ? 9 : 8);
@endphp
<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="panel-title">Daftar Pesanan - {{ $serviceFilters[$selectedService]['label'] }}</div>

            <form class="search-bar" style="width: 360px;" method="GET" action="{{ route('admin.orders.index') }}">
                <input type="hidden" name="service" value="{{ $selectedService }}">
                <input type="hidden" name="status" value="{{ $selectedStatus }}">
                <i class='bx bx-search'></i>
                <input
                    type="text"
                    name="q"
                    value="{{ $search }}"
                    placeholder="{{ $searchPlaceholder }}"
                    style="width: 100%;"
                >
            </form>
        </div>

        <div class="tabs" style="margin-top: 0;">
            @foreach($serviceFilters as $serviceKey => $serviceConfig)
                <a
                    href="{{ route('admin.orders.index', $buildQuery(['service' => $serviceKey, 'status' => 'all'])) }}"
                    class="tab-btn {{ $selectedService === $serviceKey ? 'active' : '' }}"
                    style="text-decoration:none;"
                >
                    {{ $serviceConfig['label'] }}
                    <span class="badge {{ $serviceConfig['badge_class'] }}" style="margin-left:5px;">{{ $serviceCounts[$serviceKey] ?? 0 }}</span>
                </a>
            @endforeach
        </div>

        <div class="tabs">
            @foreach($statusFilters as $statusKey => $statusConfig)
                <a
                    href="{{ route('admin.orders.index', $buildQuery(['status' => $statusKey])) }}"
                    class="tab-btn {{ $selectedStatus === $statusKey ? 'active' : '' }}"
                    style="text-decoration:none;"
                >
                    {{ $statusConfig['label'] }}
                    @if($statusKey !== 'all')
                        <span class="badge {{ $statusConfig['badge_class'] }}" style="margin-left:5px;">{{ $statusCounts[$statusKey] ?? 0 }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="orders-table">
            <thead>
                @if($isCourierView)
                    <tr>
                        <th>ID Pesanan</th>
                        <th>Waktu</th>
                        <th>Pelanggan</th>
                        <th>Deskripsi Paket</th>
                        <th>Bukti Foto</th>
                        <th>Deadline Konfirmasi</th>
                        <th>Auto Konfirmasi</th>
                        <th>Alasan Komplain</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                @elseif($isRideView)
                    <tr>
                        <th>ID Pesanan</th>
                        <th>Waktu</th>
                        <th>Pelanggan</th>
                        <th>Waktu Jemput</th>
                        <th>Waktu Tiba</th>
                        <th>Catatan Perjalanan</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                @else
                    <tr>
                        <th>ID Pesanan</th>
                        <th>Layanan</th>
                        <th>Waktu</th>
                        <th>Pelanggan</th>
                        <th>Detail Layanan</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                @endif
            </thead>
            <tbody>
                @forelse($orders as $order)
                    @php
                        $courierOrder = $order->courierOrder;
                        $rideOrder = $order->rideOrder;
                        $serviceCode = $order->serviceType?->code ?? 'UNKNOWN';
                        $serviceConfig = $serviceBadgeMap[$serviceCode] ?? ['label' => $serviceCode, 'class' => 'badge-info'];
                        $statusCode = $order->statusRef?->code ?? 'UNKNOWN';
                        $statusConfig = $statusMap[$statusCode] ?? ['label' => $statusCode, 'class' => 'badge-info'];

                        $requiresPhotoEvidence = $courierOrder?->requires_photo_evidence;
                        if ($requiresPhotoEvidence === true) {
                            $requiresPhotoLabel = 'Wajib';
                            $requiresPhotoClass = 'badge-success';
                        } elseif ($requiresPhotoEvidence === false) {
                            $requiresPhotoLabel = 'Tidak Wajib';
                            $requiresPhotoClass = 'badge-danger';
                        } else {
                            $requiresPhotoLabel = '-';
                            $requiresPhotoClass = 'badge-info';
                        }

                        if ($serviceCode === 'SHOPPING') {
                            $detailTitle = $order->restaurant->name ?? '-';
                            $detailSub = \Illuminate\Support\Str::limit($order->delivery_address ?? '-', 45);
                        } elseif ($serviceCode === 'COURIER') {
                            $detailTitle = $courierOrder?->package_description
                                ? \Illuminate\Support\Str::limit($courierOrder->package_description, 40)
                                : 'Paket Kurir';
                            $detailSub = \Illuminate\Support\Str::limit($order->delivery_address ?? '-', 45);
                        } elseif ($serviceCode === 'RIDE') {
                            $pickupAt = $rideOrder?->picked_up_at?->format('H:i');
                            $arrivedAt = $rideOrder?->arrived_at?->format('H:i');
                            $detailTitle = 'Antar Jemput Penumpang';
                            if ($pickupAt && $arrivedAt) {
                                $detailSub = 'Pickup '.$pickupAt.' | Tiba '.$arrivedAt;
                            } elseif ($pickupAt) {
                                $detailSub = 'Pickup '.$pickupAt;
                            } elseif ($arrivedAt) {
                                $detailSub = 'Tiba '.$arrivedAt;
                            } else {
                                $detailSub = 'Perjalanan belum dimulai';
                            }
                        } else {
                            $detailTitle = '-';
                            $detailSub = '-';
                        }
                    @endphp
                    @if($isCourierView)
                        <tr>
                            <td class="td-id">#{{ $order->order_number }}</td>
                            <td class="td-sub">{{ $order->created_at?->format('d M Y, H:i') ?? '-' }}</td>
                            <td class="td-user">
                                <span class="td-strong">{{ $order->user->name ?? '-' }}</span>
                                <span class="td-sub"><i class='bx bx-phone'></i> {{ $order->user->phone ?? '-' }}</span>
                            </td>
                            <td class="td-sub">{{ \Illuminate\Support\Str::limit($courierOrder?->package_description ?? '-', 55) }}</td>
                            <td>
                                <span class="badge {{ $requiresPhotoClass }}">{{ $requiresPhotoLabel }}</span>
                            </td>
                            <td class="td-sub">{{ $courierOrder?->confirmation_deadline_at?->format('d M Y, H:i') ?? '-' }}</td>
                            <td class="td-sub">{{ $courierOrder?->auto_confirmed_at?->format('d M Y, H:i') ?? '-' }}</td>
                            <td class="td-sub">{{ \Illuminate\Support\Str::limit($courierOrder?->complaint_reason ?? '-', 45) }}</td>
                            <td class="td-price">Rp {{ number_format((float) $order->total_amount, 0, ',', '.') }}</td>
                            <td>
                                <span class="badge {{ $statusConfig['class'] }}">
                                    {{ $statusConfig['label'] }}
                                </span>
                            </td>
                            <td class="td-action">
                                <div style="display:flex; gap: 8px;">
                                    <button class="btn-action detail" title="Lihat Detail Log"><i class='bx bx-show'></i></button>
                                </div>
                            </td>
                        </tr>
                    @elseif($isRideView)
                        <tr>
                            <td class="td-id">#{{ $order->order_number }}</td>
                            <td class="td-sub">{{ $order->created_at?->format('d M Y, H:i') ?? '-' }}</td>
                            <td class="td-user">
                                <span class="td-strong">{{ $order->user->name ?? '-' }}</span>
                                <span class="td-sub"><i class='bx bx-phone'></i> {{ $order->user->phone ?? '-' }}</span>
                            </td>
                            <td class="td-sub">{{ $rideOrder?->picked_up_at?->format('d M Y, H:i') ?? '-' }}</td>
                            <td class="td-sub">{{ $rideOrder?->arrived_at?->format('d M Y, H:i') ?? '-' }}</td>
                            <td class="td-sub">{{ \Illuminate\Support\Str::limit($rideOrder?->notes ?? '-', 55) }}</td>
                            <td class="td-price">Rp {{ number_format((float) $order->total_amount, 0, ',', '.') }}</td>
                            <td>
                                <span class="badge {{ $statusConfig['class'] }}">
                                    {{ $statusConfig['label'] }}
                                </span>
                            </td>
                            <td class="td-action">
                                <div style="display:flex; gap: 8px;">
                                    <button class="btn-action detail" title="Lihat Detail Log"><i class='bx bx-show'></i></button>
                                </div>
                            </td>
                        </tr>
                    @else
                        <tr>
                            <td class="td-id">#{{ $order->order_number }}</td>
                            <td>
                                <span class="badge {{ $serviceConfig['class'] }}">{{ $serviceConfig['label'] }}</span>
                            </td>
                            <td class="td-sub">{{ $order->created_at?->format('d M Y, H:i') }}</td>
                            <td class="td-user">
                                <span class="td-strong">{{ $order->user->name ?? '-' }}</span>
                                <span class="td-sub"><i class='bx bx-phone'></i> {{ $order->user->phone ?? '-' }}</span>
                            </td>
                            <td class="td-resto">
                                <span class="td-strong">{{ $detailTitle }}</span>
                                <span class="td-sub">{{ $detailSub }}</span>
                            </td>
                            <td class="td-price">Rp {{ number_format((float) $order->total_amount, 0, ',', '.') }}</td>
                            <td>
                                <span class="badge {{ $statusConfig['class'] }}">
                                    {{ $statusConfig['label'] }}
                                    @if($statusCode === 'ON_THE_WAY' && $order->driver?->user)
                                        ({{ $order->driver->user->name }})
                                    @endif
                                </span>
                            </td>
                            <td class="td-action">
                                <div style="display:flex; gap: 8px;">
                                    <button class="btn-action detail" title="Lihat Detail Log"><i class='bx bx-show'></i></button>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="{{ $emptyColspan }}" class="td-sub" style="text-align:center; padding:24px;">Belum ada data pesanan untuk filter ini.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    
    <div class="panel-pagination" style="padding: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">
            Menampilkan {{ $resultStart }}-{{ $resultEnd }} dari {{ $orders->total() }} pesanan
        </span>
        <div class="pagination-controls" style="display: flex; gap: 6px;">
            @if($orders->onFirstPage())
                <span class="btn-page" style="opacity:0.5; cursor:not-allowed;">&laquo;</span>
            @else
                <a href="{{ $orders->previousPageUrl() }}" class="btn-page">&laquo;</a>
            @endif

            <span class="btn-page active">{{ $orders->currentPage() }}</span>

            @if($orders->hasMorePages())
                <a href="{{ $orders->nextPageUrl() }}" class="btn-page">&raquo;</a>
            @else
                <span class="btn-page" style="opacity:0.5; cursor:not-allowed;">&raquo;</span>
            @endif
        </div>
    </div>
</div>
@endsection
