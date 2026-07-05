@extends('layouts.admin')

@section('title', 'Pengaturan - Admin Pelanggan 15')
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
            <div class="settings-section-header">
                <div>
                    <div class="settings-section-title">QRIS Pembayaran</div>
                    <p class="settings-section-note">Gambar ini dipakai customer saat memilih pembayaran QRIS di tracking order.</p>
                </div>
                <span class="badge {{ in_array(($qrisAsset['source'] ?? ''), ['uploaded', 'official'], true) ? 'badge-success' : 'badge-warning' }}">
                    {{ $qrisAsset['source_label'] ?? 'QRIS resmi' }}
                </span>
            </div>

            <div class="qris-settings-grid">
                <a href="{{ route('payments.qris.show') }}" target="_blank" rel="noopener noreferrer" class="qris-preview-frame" aria-label="Buka QRIS Pelanggan 15">
                    <img src="{{ $qrisAsset['url'] ?? route('payments.qris.show') }}" alt="QRIS pembayaran Pelanggan 15">
                </a>

                <div class="qris-settings-content">
                    <div class="settings-list qris-meta-list">
                        <div class="settings-row">
                            <span>URL publik</span>
                            <strong>{{ route('payments.qris.show') }}</strong>
                        </div>
                        <div class="settings-row">
                            <span>Lokasi aktif</span>
                            <strong>{{ $qrisAsset['location_label'] ?? 'public/images/payments/qris-bangdeliv.jpeg' }}</strong>
                        </div>
                        <div class="settings-row">
                            <span>Terakhir diperbarui</span>
                            <strong>{{ $qrisAsset['updated_at_label'] ?? '-' }}</strong>
                        </div>
                    </div>

                    <form action="{{ route('admin.settings.qris.update') }}" method="POST" enctype="multipart/form-data" class="qris-upload-form">
                        @csrf
                        <div class="form-group">
                            <label for="qris_image">Ganti gambar QRIS</label>
                            <input id="qris_image" type="file" name="qris_image" class="form-control qris-file-input" accept="image/jpeg,image/png,image/webp" required>
                            @error('qris_image')
                                <div class="form-error">{{ $message }}</div>
                            @enderror
                            <small class="form-help">Format JPG, PNG, atau WebP. Maksimal 5 MB. Upload baru akan menggantikan QRIS upload sebelumnya.</small>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bx bx-upload" aria-hidden="true"></i>
                            Simpan QRIS Baru
                        </button>
                    </form>
                </div>
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
