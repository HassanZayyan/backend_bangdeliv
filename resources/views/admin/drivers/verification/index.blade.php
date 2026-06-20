@extends('layouts.admin')

@section('title', 'Verifikasi Driver - Admin BangDeliv')
@section('page-title', 'Verifikasi Driver Baru')

@section('content')
@if(session('success'))
    <div class="panel" style="margin-bottom: 12px; padding: 12px 16px; color: var(--color-success); font-weight: 600;">
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="panel" style="margin-bottom: 12px; padding: 12px 16px; color: var(--color-danger); font-weight: 600;">
        {{ session('error') }}
    </div>
@endif
<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="panel-title">Antrean Verifikasi Calon Mitra</div>
            
            <div style="display: flex; gap: 10px;">
                <form class="search-bar" style="width: 280px;" method="GET" action="{{ route('admin.verification') }}">
                    <input type="hidden" name="status" value="{{ $statusFilter }}">
                    <i class='bx bx-search'></i>
                    <input type="text" name="search" value="{{ $search }}" placeholder="Cari Nama, NIK, atau Plat..." style="width: 100%;">
                </form>
            </div>
        </div>

        <div class="tabs">
            <a href="{{ route('admin.verification', ['status' => 'pending', 'search' => $search]) }}" class="tab-btn {{ $statusFilter === 'pending' ? 'active' : '' }}" style="text-decoration:none;">Menunggu Verifikasi <span class="badge badge-warning" style="margin-left:5px;">{{ $pendingCount }}</span></a>
            <a href="{{ route('admin.verification', ['status' => 'needs_revision', 'search' => $search]) }}" class="tab-btn {{ $statusFilter === 'needs_revision' ? 'active' : '' }}" style="text-decoration:none;">Butuh Revisi Dokumen <span class="badge badge-danger" style="margin-left:5px;">{{ $needsRevisionCount }}</span></a>
            <a href="{{ route('admin.verification', ['status' => 'rejected', 'search' => $search]) }}" class="tab-btn {{ $statusFilter === 'rejected' ? 'active' : '' }}" style="text-decoration:none;">Ditolak (Rejected) <span class="badge badge-danger" style="margin-left:5px;">{{ $rejectedCount }}</span></a>
            <a href="{{ route('admin.verification', ['status' => 'all', 'search' => $search]) }}" class="tab-btn {{ $statusFilter === 'all' ? 'active' : '' }}" style="text-decoration:none;">Semua</a>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Calon Mitra / KTP</th>
                    <th>Kendaraan</th>
                    <th>Status Dokumen</th>
                    <th>Waktu Pengajuan</th>
                    <th>Keputusan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($verificationDrivers as $row)
                    @php
                        $driver = $row['driver'];
                        $documents = $row['documents'];
                        $initial = strtoupper(substr($driver['name'] ?? 'D', 0, 2));
                    @endphp
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div class="driver-avatar" style="width: 45px; height: 45px; flex-shrink: 0;">{{ $initial }}</div>
                                <div class="td-user">
                                    <span class="td-strong">{{ $driver['name'] ?? '-' }}</span>
                                    <span class="td-sub"><i class='bx bx-id-card'></i> {{ $driver['license_number'] ?? '-' }}</span>
                                    <span class="td-sub"><i class='bx bx-phone'></i> {{ $driver['phone'] ?? '-' }}</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="td-strong">{{ $driver['vehicle_plate'] ?? '-' }}</span>
                            <span class="td-sub" style="display:block;">Status Registrasi: {{ ucfirst($driver['registration_status'] ?? 'pending') }}</span>
                        </td>
                        <td>
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                @foreach($documents as $doc)
                                    @php
                                        $status = $doc['verification_status'] ?? 'pending';
                                        $badgeClass = $status === 'approved' ? 'badge-success' : ($status === 'rejected' ? 'badge-danger' : 'badge-warning');
                                    @endphp
                                    <span class="badge {{ $badgeClass }}" style="font-size: 10px; width: fit-content;">
                                        {{ strtoupper($doc['document_type'] ?? '-') }}: {{ ucfirst($status) }}
                                    </span>
                                @endforeach
                            </div>
                        </td>
                        <td>
                            @php
                                $submittedAt = !empty($driver['submitted_at']) ? \Illuminate\Support\Carbon::parse($driver['submitted_at']) : null;
                            @endphp
                            <span class="td-strong">{{ $submittedAt?->diffForHumans() ?? '-' }}</span>
                            <span class="td-sub" style="display:block;">{{ $submittedAt?->format('d M Y, H:i') ?? '-' }}</span>
                        </td>
                        <td class="td-action">
                            <div style="display:flex; gap: 8px;">
                                <a href="{{ route('admin.verification.show', ['driverId' => $driver['id']]) }}" class="btn-action detail" style="width:auto; padding:0 12px; font-size:13px; font-weight:600; color:white; background:var(--color-primary); text-decoration:none; display:inline-flex; align-items:center;" title="Periksa Dokumen"><i class='bx bx-search-alt' style="margin-right:5px;"></i> Periksa</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="td-sub" style="text-align:center; padding:24px;">Belum ada antrean verifikasi driver.</td>
                    </tr>
                @endforelse

            </tbody>
        </table>
    </div>
    <x-admin-pagination
        :paginator="$verificationDrivers"
        label="antrean"
        :query="['status' => $statusFilter, 'search' => $search]"
    />
    
</div>
@endsection
