@extends('layouts.admin')

@section('title', 'Pengaturan - Admin BangDeliv')
@section('page-title', 'Pengaturan Sistem')

@section('content')
<section class="panel settings-overview-panel">
    <div class="panel-header">
        <div class="panel-title">Pengaturan Sistem</div>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <span class="badge badge-info">Read-only</span>
            <span class="badge badge-warning">Dikelola sistem</span>
        </div>
    </div>

    <div class="settings-overview">
        <div class="settings-section">
            <div class="settings-section-title">Konfigurasi Tarif Ongkos Kirim</div>
            <div class="settings-metric-grid">
            @foreach ($deliveryPricingRows as $row)
                <div class="setting-metric">
                    <span>{{ $row['label'] }}</span>
                    <strong>{{ $row['value'] }}</strong>
                    <small>{{ $row['note'] }}</small>
                </div>
            @endforeach
            </div>
        </div>

        <div class="settings-section">
            <div class="settings-section-title">Notifikasi Admin</div>
            <div class="settings-list settings-list-grid">
                <div class="settings-row">
                    <span>Driver pending</span>
                    <strong>{{ $adminNotificationSummary['pending_drivers'] ?? 0 }}</strong>
                </div>
                <div class="settings-row">
                    <span>Bukti QRIS pending</span>
                    <strong>{{ $adminNotificationSummary['pending_payment_proofs'] ?? 0 }}</strong>
                </div>
                <div class="settings-row">
                    <span>Total antrean</span>
                    <strong>{{ $adminNotificationSummary['total_pending'] ?? 0 }}</strong>
                </div>
            </div>
        </div>

        <div class="settings-section">
            <div class="settings-section-title">Profil Admin</div>
            <div class="settings-form-grid">
                <div class="form-group">
                    <label for="admin_name">Nama</label>
                    <input id="admin_name" type="text" class="form-control" value="{{ Auth::user()->name ?? 'Super Admin' }}" readonly>
                </div>
                <div class="form-group">
                    <label for="admin_email">Email</label>
                    <input id="admin_email" type="email" class="form-control" value="{{ Auth::user()->email ?? '-' }}" readonly>
                </div>
                <div class="form-group">
                    <label for="admin_role">Role</label>
                    <input id="admin_role" type="text" class="form-control" value="{{ ucfirst(Auth::user()->role ?? 'admin') }}" readonly>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
