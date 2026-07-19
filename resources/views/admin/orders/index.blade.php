@extends('layouts.admin')

@section('title', 'Pesanan - Admin Pelanggan 15')
@section('page-title', 'Pemantauan Pesanan')

@inject('paymentLabels', 'App\Services\Admin\AdminPaymentProofStatusService')

@section('content')
@php
    $buildQuery = function (array $overrides): array {
        $query = array_merge(request()->except('page'), $overrides);
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            }
        }

        return $query;
    };
@endphp

<section class="panel">
    <div class="panel-header stack">
        <div class="toolbar-row">
            <div>
                <div class="panel-title">Daftar Pesanan</div>
                <div class="panel-description">Admin memantau status, driver, dan bukti QRIS tanpa mengubah jalannya pesanan.</div>
            </div>

            <form class="search-bar" method="GET" action="{{ route('admin.orders.index') }}">
                <input type="hidden" name="service" value="{{ $selectedService }}">
                <input type="hidden" name="status" value="{{ $selectedStatus }}">
                <i class="bx bx-search" aria-hidden="true"></i>
                <input
                    type="text"
                    name="q"
                    value="{{ $search }}"
                    placeholder="{{ $searchPlaceholder }}"
                    aria-label="Cari pesanan"
                >
            </form>
        </div>

        <div class="tabs compact-tabs">
            @foreach($statusFilters as $statusKey => $statusConfig)
                <a
                    href="{{ route('admin.orders.index', $buildQuery(['status' => $statusKey])) }}"
                    class="tab-btn {{ $selectedStatus === $statusKey ? 'active' : '' }}"
                >
                    {{ $statusConfig['label'] }}
                    @if($statusKey !== 'all')
                        <span class="badge {{ $statusConfig['badge_class'] }}">{{ $statusCounts[$statusKey] ?? 0 }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>

    <div class="table-responsive">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Pesanan</th>
                    <th>Pelanggan</th>
                    <th>Layanan</th>
                    <th>Total & Pembayaran</th>
                    <th>Status</th>
                    <th>Driver</th>
                    <th class="td-action">Detail</th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                    @php
                        $serviceCode = $order->serviceType?->code ?? 'UNKNOWN';
                        $serviceConfig = $serviceBadgeMap[$serviceCode] ?? ['label' => 'Layanan Lain', 'class' => 'badge-info'];
                        $statusCode = $order->statusRef?->code ?? 'UNKNOWN';
                        $statusConfig = $statusMap[$statusCode] ?? ['label' => 'Status Lain', 'class' => 'badge-info'];
                        $payment = $order->payment;
                        $paymentMethod = strtoupper((string) ($payment?->payment_method ?? $order->payment_method ?? 'COD'));
                        $paymentStatus = strtoupper((string) ($payment?->payment_status ?? $order->payment_status ?? 'UNPAID'));
                        $pendingProofCount = (int) ($order->pending_payment_proof_count ?? 0);

                        if ($serviceCode === 'SHOPPING') {
                            $serviceSummary = $order->restaurant?->name ?? 'Titip belanja';
                        } elseif ($serviceCode === 'COURIER') {
                            $serviceSummary = \Illuminate\Support\Str::limit($order->courierOrder?->package_description ?? 'Paket kurir', 42);
                        } else {
                            $serviceSummary = 'Antar jemput';
                        }
                    @endphp
                    <tr>
                        <td data-label="Pesanan" class="mobile-card-primary">
                            <span class="td-strong">#{{ $order->order_number }}</span>
                            <span class="td-sub">{{ $order->created_at?->format('d M Y, H:i') ?? '-' }}</span>
                        </td>
                        <td data-label="Pelanggan">
                            <span class="td-strong">{{ $order->user?->name ?? '-' }}</span>
                            <span class="td-sub">{{ $order->user?->phone ?? '-' }}</span>
                        </td>
                        <td data-label="Layanan">
                            <span class="badge {{ $serviceConfig['class'] }}">{{ $serviceConfig['label'] }}</span>
                            <span class="td-sub row-note">{{ $serviceSummary }}</span>
                        </td>
                        <td data-label="Total & Pembayaran">
                            <span class="td-price">Rp {{ number_format((float) $order->total_price, 0, ',', '.') }}</span>
                            <span class="td-sub">{{ $paymentLabels->paymentMethodLabel($paymentMethod) }} — {{ $paymentLabels->paymentStatusLabel($paymentStatus) }}</span>
                            @if($pendingProofCount > 0)
                                <span class="badge badge-warning row-note">{{ $pendingProofCount }} bukti menunggu verifikasi</span>
                            @endif
                        </td>
                        <td data-label="Status">
                            <span class="badge {{ $statusConfig['class'] }}">{{ $statusConfig['label'] }}</span>
                        </td>
                        <td data-label="Driver">
                            <span class="td-strong">{{ $order->driver?->user?->name ?? '-' }}</span>
                            <span class="td-sub">{{ $order->driver?->user?->phone ?? '' }}</span>
                        </td>
                        <td class="td-action" data-label="Detail">
                            <a href="{{ route('admin.orders.show', ['order' => $order->id, 'back' => url()->full()]) }}" class="btn-action detail" title="Lihat detail" aria-label="Lihat detail pesanan {{ $order->order_number }}">
                                <i class="bx bx-show" aria-hidden="true"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="empty-state">Belum ada data pesanan untuk filter ini.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-admin-pagination :paginator="$orders" label="pesanan" />
</section>
@endsection
