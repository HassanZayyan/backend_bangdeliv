@extends('layouts.admin')

@section('title', 'Detail Driver - Admin BangDeliv')
@section('page-title', 'Detail Driver')

@section('content')
@php
    $formatCurrency = fn ($amount): string => 'Rp '.number_format((float) $amount, 0, ',', '.');
    $formatPercent = function ($percent): string {
        $formatted = number_format((float) $percent, 2, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',').'%';
    };
    $status = $driver->admin_status ?? ['label' => 'Offline', 'class' => 'badge-info'];
    $avatarUrl = $driver->admin_avatar_url;
@endphp

<div class="page-actions">
    <a href="{{ $backUrl }}" class="btn btn-muted">
        <i class="bx bx-arrow-back" aria-hidden="true"></i>
        Kembali
    </a>
    <div class="badge-row">
        <span class="badge {{ $status['class'] }}">{{ $status['label'] }}</span>
        <a href="{{ route('admin.verification.show', ['driverId' => $driver->id]) }}" class="btn btn-muted" style="text-decoration:none;">
            <i class="bx bx-shield-quarter" aria-hidden="true"></i>
            Verifikasi
        </a>
    </div>
</div>

<div class="detail-grid">
    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title">Profil Driver</div>
                <div class="panel-description">{{ $driver->created_at?->format('d M Y, H:i') ?? '-' }}</div>
            </div>
        </div>
        <div style="display:flex; align-items:center; gap:14px; padding:16px; border-bottom:1px solid var(--border-color);">
            <div class="driver-avatar {{ $avatarUrl ? 'has-image' : 'is-fallback' }}" style="width:54px; height:54px; flex-shrink:0;">
                @if($avatarUrl)
                    <img
                        src="{{ $avatarUrl }}"
                        alt="Avatar {{ $driver->user->name ?? 'Driver' }}"
                        class="driver-avatar-image"
                        loading="lazy"
                        onerror="this.parentElement.classList.remove('has-image'); this.parentElement.classList.add('is-fallback'); this.remove();"
                    >
                @endif
                <span class="driver-avatar-fallback">{{ $driver->admin_initial }}</span>
            </div>
            <div>
                <strong style="display:block; color:var(--text-main); font-size:16px;">{{ $driver->user->name ?? '-' }}</strong>
                <span class="td-sub"><i class="bx bx-phone" aria-hidden="true"></i> {{ $driver->user->phone ?? '-' }}</span>
            </div>
        </div>
        <div class="summary-grid">
            <div>
                <span>Plat Nomor</span>
                <strong>{{ $driver->vehicle_plate ?? '-' }}</strong>
            </div>
            <div>
                <span>Kendaraan</span>
                <strong>{{ trim(($driver->vehicle_brand ?? '').' '.($driver->vehicle_model ?? '')) ?: '-' }}</strong>
                <small>{{ $driver->vehicle_type ?? '-' }}</small>
            </div>
            <div>
                <span>Status Akun</span>
                <strong>{{ $status['label'] }}</strong>
            </div>
            <div>
                <span>Total Order Tercatat</span>
                <strong>{{ $driver->orders_count ?? $incomeSummary['order_count'] ?? 0 }}</strong>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header stack">
            <div class="toolbar-row">
                <div>
                    <div class="panel-title">Gaji Driver</div>
                    <div class="panel-description">Potongan aktif: {{ $formatPercent($incomeSummary['admin_fee_percent'] ?? 0) }}</div>
                </div>
                <form method="GET" action="{{ route('admin.drivers.show', ['driver' => $driver->id]) }}" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
                    <select name="income_mode" class="form-control" aria-label="Mode potongan driver" style="height:36px; width:142px; padding:6px 10px;">
                        <option value="system" @selected($selectedIncomeMode === 'system')>By Sistem</option>
                        <option value="manual" @selected($selectedIncomeMode === 'manual')>Manual</option>
                    </select>
                    <input
                        type="number"
                        name="admin_fee_percent"
                        class="form-control"
                        aria-label="Persentase potongan driver"
                        value="{{ $selectedIncomeMode === 'manual' ? $selectedAdminFeePercent : $systemAdminFeePercent }}"
                        min="0"
                        max="100"
                        step="0.01"
                        style="height:36px; width:86px; padding:6px 10px;"
                    >
                    <button type="submit" class="btn btn-primary" style="height:36px; padding:0 12px;">Terapkan</button>
                </form>
            </div>
        </div>
        <div class="summary-grid">
            <div>
                <span>Pendapatan Bersih</span>
                <strong class="text-primary">{{ $formatCurrency($incomeSummary['net_income'] ?? 0) }}</strong>
            </div>
            <div>
                <span>Potongan Admin</span>
                <strong>{{ $formatCurrency($incomeSummary['admin_fee'] ?? 0) }}</strong>
                <small>{{ $formatPercent($incomeSummary['admin_fee_percent'] ?? 0) }}</small>
            </div>
            <div>
                <span>Pendapatan Bruto</span>
                <strong>{{ $formatCurrency($incomeSummary['gross_income'] ?? 0) }}</strong>
            </div>
            <div>
                <span>Order Penghasilan</span>
                <strong>{{ $incomeSummary['order_count'] ?? 0 }}</strong>
            </div>
        </div>
    </section>
</div>

<section class="panel">
    <div class="panel-header">
        <div>
            <div class="panel-title">Rincian Gaji Per Order</div>
            <div class="panel-description">Nominal dihitung dari ongkir final driver.</div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="orders-table compact-table">
            <thead>
                <tr>
                    <th>Pesanan</th>
                    <th>Customer</th>
                    <th>Layanan</th>
                    <th>Bruto</th>
                    <th>Potongan</th>
                    <th>Bersih</th>
                </tr>
            </thead>
            <tbody>
                @forelse($incomeRows as $row)
                    <tr>
                        <td data-label="Pesanan" class="mobile-card-primary">
                            <span class="td-strong">#{{ $row['order_number'] }}</span>
                            <span class="td-sub">{{ $row['date']?->format('d M Y, H:i') ?? '-' }}</span>
                        </td>
                        <td data-label="Customer">
                            <span class="td-strong">{{ $row['customer_name'] }}</span>
                            <span class="td-sub">{{ $row['status_label'] }}</span>
                        </td>
                        <td data-label="Layanan">{{ $row['service_label'] }}</td>
                        <td data-label="Bruto">{{ $formatCurrency($row['gross_income']) }}</td>
                        <td data-label="Potongan">
                            <span class="td-strong">{{ $formatCurrency($row['admin_fee']) }}</span>
                            <span class="td-sub">{{ $formatPercent($row['admin_fee_percent']) }}</span>
                        </td>
                        <td data-label="Bersih"><span class="td-price">{{ $formatCurrency($row['net_income']) }}</span></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="empty-state">Belum ada order penghasilan untuk driver ini.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
