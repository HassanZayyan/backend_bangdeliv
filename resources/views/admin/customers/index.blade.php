@extends('layouts.admin')

@section('title', 'Pelanggan - Admin BangDeliv')
@section('page-title', 'Manajemen Pelanggan')

@section('content')
@if(session('success'))
    <div class="panel" style="margin-bottom: 10px; padding: 12px 14px; color: var(--color-success); font-weight: 600;">
        {{ session('success') }}
    </div>
@endif

<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="panel-title">Daftar Pengguna App (Customer)</div>
            
            <div style="display: flex; gap: 10px;">
                <form class="search-bar" style="width: 280px;" method="GET" action="{{ route('admin.customers.index') }}">
                    <input type="hidden" name="status" value="{{ $selectedStatus }}">
                    <i class='bx bx-search'></i>
                    <input type="text" name="q" value="{{ $search }}" placeholder="Cari Nama, Email, atau HP..." style="width: 100%;">
                </form>
            </div>
        </div>

        <div class="tabs">
            @foreach($statusFilters as $statusKey => $statusConfig)
                <a href="{{ route('admin.customers.index', ['status' => $statusKey, 'q' => $search]) }}" class="tab-btn {{ $selectedStatus === $statusKey ? 'active' : '' }}" style="text-decoration:none;">
                    {{ $statusConfig['label'] }}
                    @if($statusKey !== 'semua')
                        <span class="badge {{ $statusConfig['badge_class'] }}" style="margin-left:5px;">{{ $statusCounts[$statusKey] ?? 0 }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Profil Pelanggan</th>
                    <th>Kontak & Lokasi Terakhir</th>
                    <th>Riwayat Pesanan</th>
                    <th>Status Akun</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $customer)
                    @php
                        $isBlacklisted = (bool) $customer->is_blacklisted;
                        $status = $customer->admin_status ?? ['label' => 'Aktif', 'class' => 'badge-success'];
                        $avatarUrl = $customer->admin_avatar_url;
                    @endphp
                    <tr @if($isBlacklisted) style="background-color: rgba(239, 68, 68, 0.02);" @endif>
                        <td data-label="Profil Pelanggan" class="mobile-card-primary">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div class="driver-avatar {{ $avatarUrl ? 'has-image' : 'is-fallback' }}" style="width: 45px; height: 45px; flex-shrink: 0; {{ $isBlacklisted ? 'background-color: var(--color-danger);' : '' }}">
                                    @if($avatarUrl)
                                        <img
                                            src="{{ $avatarUrl }}"
                                            alt="Avatar {{ $customer->name }}"
                                            class="driver-avatar-image"
                                            loading="lazy"
                                            onerror="this.parentElement.classList.remove('has-image'); this.parentElement.classList.add('is-fallback'); this.remove();"
                                        >
                                    @endif
                                    <span class="driver-avatar-fallback">{{ $customer->admin_initial }}</span>
                                </div>
                                <div class="td-user">
                                    <span class="td-strong">{{ $customer->name }}</span>
                                    <span class="td-sub">Bergabung: {{ $customer->created_at?->format('d M Y') }}</span>
                                </div>
                            </div>
                        </td>
                        <td data-label="Kontak">
                            <span class="td-strong"><i class='bx bx-envelope'></i> {{ $customer->email }}</span>
                            <span class="td-sub" style="display:block;"><i class='bx bx-phone'></i> {{ $customer->phone ?? '-' }}</span>
                        </td>
                        <td data-label="Riwayat Pesanan">
                            <span class="td-strong" style="color:var(--color-success);">{{ $customer->success_orders_count }} Sukses</span>
                            <span class="td-sub" style="display:block; {{ $customer->cancelled_orders_count > 0 ? 'color:var(--color-danger); font-weight:600;' : '' }}">{{ $customer->cancelled_orders_count }} Dibatalkan</span>
                        </td>
                        <td data-label="Status Akun">
                            <span class="badge {{ $status['class'] }}">{{ $status['label'] }}</span>
                        </td>
                        <td class="td-action" data-label="Aksi">
                            <div class="customer-actions">
                                <button
                                    type="button"
                                    class="btn-action detail js-customer-toggle"
                                    title="Lihat detail pelanggan"
                                    aria-label="Lihat detail pelanggan {{ $customer->name }}"
                                    aria-controls="customer-detail-{{ $customer->id }}"
                                    aria-expanded="false"
                                >
                                    <i class='bx bx-show' aria-hidden="true"></i>
                                </button>
                                <a
                                    href="{{ route('admin.orders.index', ['q' => $customer->phone ?: $customer->name]) }}"
                                    class="btn-action warning"
                                    title="Lihat pesanan pelanggan"
                                    aria-label="Lihat pesanan {{ $customer->name }}"
                                >
                                    <i class='bx bx-receipt' aria-hidden="true"></i>
                                </a>
                                <form
                                    method="POST"
                                    action="{{ route('admin.customers.blacklist', $customer) }}"
                                    onsubmit="return confirm('{{ $isBlacklisted ? 'Keluarkan pelanggan ini dari blacklist?' : 'Masukkan pelanggan ini ke blacklist?' }}');"
                                >
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="is_blacklisted" value="{{ $isBlacklisted ? 0 : 1 }}">
                                    <input type="hidden" name="status" value="{{ $selectedStatus }}">
                                    <input type="hidden" name="q" value="{{ $search }}">
                                    <button
                                        type="submit"
                                        class="btn-action {{ $isBlacklisted ? 'warning' : 'danger' }}"
                                        title="{{ $isBlacklisted ? 'Buka blacklist' : 'Blacklist pelanggan' }}"
                                        aria-label="{{ $isBlacklisted ? 'Buka blacklist ' : 'Blacklist ' }}{{ $customer->name }}"
                                    >
                                        <i class='bx {{ $isBlacklisted ? 'bx-user-check' : 'bx-block' }}' aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <tr id="customer-detail-{{ $customer->id }}" class="customer-detail-row" hidden>
                        <td colspan="5">
                            <div class="customer-detail-panel">
                                <div class="customer-detail-profile">
                                    <div class="driver-avatar {{ $avatarUrl ? 'has-image' : 'is-fallback' }}" style="width: 54px; height: 54px; flex-shrink: 0;">
                                        @if($avatarUrl)
                                            <img
                                                src="{{ $avatarUrl }}"
                                                alt="Avatar {{ $customer->name }}"
                                                class="driver-avatar-image"
                                                loading="lazy"
                                                onerror="this.parentElement.classList.remove('has-image'); this.parentElement.classList.add('is-fallback'); this.remove();"
                                            >
                                        @endif
                                        <span class="driver-avatar-fallback">{{ $customer->admin_initial }}</span>
                                    </div>
                                    <div>
                                        <span class="td-sub">Foto Profil</span>
                                        <strong>{{ $customer->name }}</strong>
                                    </div>
                                </div>
                                <div class="customer-detail-grid">
                                    <div>
                                        <span class="td-sub">Nama</span>
                                        <strong>{{ $customer->name }}</strong>
                                    </div>
                                    <div>
                                        <span class="td-sub">Email</span>
                                        <strong>{{ $customer->email }}</strong>
                                    </div>
                                    <div>
                                        <span class="td-sub">Nomor HP</span>
                                        <strong>{{ $customer->phone ?? '-' }}</strong>
                                    </div>
                                    <div>
                                        <span class="td-sub">Bergabung</span>
                                        <strong>{{ $customer->created_at?->format('d M Y, H:i') ?? '-' }}</strong>
                                    </div>
                                    <div>
                                        <span class="td-sub">Pesanan sukses</span>
                                        <strong>{{ $customer->success_orders_count }}</strong>
                                    </div>
                                    <div>
                                        <span class="td-sub">Pesanan dibatalkan</span>
                                        <strong>{{ $customer->cancelled_orders_count }}</strong>
                                    </div>
                                </div>
                                <div class="customer-detail-actions">
                                    <span class="badge {{ $status['class'] }}">{{ $status['label'] }}</span>
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="td-sub" style="text-align:center; padding:24px;">Belum ada data pelanggan.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    
    <x-admin-pagination :paginator="$customers" label="pelanggan" />
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('click', function (event) {
    const button = event.target.closest('.js-customer-toggle');
    if (!button) {
        return;
    }

    const targetId = button.getAttribute('aria-controls');
    const target = document.getElementById(targetId);
    if (!target) {
        return;
    }

    const shouldOpen = target.hidden;

    document.querySelectorAll('.customer-detail-row').forEach((row) => {
        if (row !== target) {
            row.hidden = true;
        }
    });

    document.querySelectorAll('.js-customer-toggle').forEach((toggle) => {
        if (toggle !== button) {
            toggle.setAttribute('aria-expanded', 'false');
        }
    });

    target.hidden = !shouldOpen;
    button.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
});
</script>
@endpush
