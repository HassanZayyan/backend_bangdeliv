@extends('layouts.admin')

@section('title', 'Verifikasi Driver - Admin BangDeliv')
@section('page-title', 'Verifikasi Driver Baru')

@section('content')
@php
    $verificationDrivers = \App\Models\Driver::query()
        ->with(['user', 'driverDocuments'])
        ->whereIn('registration_status', ['pending', 'rejected'])
        ->latest('created_at')
        ->get();

    $pendingCount = \App\Models\Driver::where('registration_status', 'pending')->count();
    $needsRevisionCount = \App\Models\DriverDocument::where('verification_status', 'rejected')->distinct('driver_id')->count('driver_id');
    $rejectedCount = \App\Models\Driver::where('registration_status', 'rejected')->count();
@endphp
<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="panel-title">Antrean Verifikasi Calon Mitra</div>
            
            <div style="display: flex; gap: 10px;">
                <div class="search-bar" style="width: 280px;">
                    <i class='bx bx-search'></i>
                    <input type="text" placeholder="Cari Nama, NIK, atau Plat..." style="width: 100%;">
                </div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active">Menunggu Verifikasi <span class="badge badge-warning" style="margin-left:5px;">{{ $pendingCount }}</span></button>
            <button class="tab-btn">Butuh Revisi Dokumen <span class="badge badge-danger" style="margin-left:5px;">{{ $needsRevisionCount }}</span></button>
            <button class="tab-btn">Ditolak (Rejected) <span class="badge badge-danger" style="margin-left:5px;">{{ $rejectedCount }}</span></button>
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
                @forelse($verificationDrivers as $driver)
                    @php
                        $initial = strtoupper(substr($driver->user->name ?? 'D', 0, 2));
                        $docKtp = $driver->driverDocuments->firstWhere('document_type', 'ktp');
                        $docSim = $driver->driverDocuments->firstWhere('document_type', 'sim');
                        $docSelfie = $driver->driverDocuments->firstWhere('document_type', 'selfie');
                        $docRows = [
                            ['label' => 'KTP', 'doc' => $docKtp],
                            ['label' => 'SIM', 'doc' => $docSim],
                            ['label' => 'Selfie', 'doc' => $docSelfie],
                        ];
                    @endphp
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div class="driver-avatar" style="width: 45px; height: 45px; flex-shrink: 0;">{{ $initial }}</div>
                                <div class="td-user">
                                    <span class="td-strong">{{ $driver->user->name ?? '-' }}</span>
                                    <span class="td-sub"><i class='bx bx-id-card'></i> {{ $driver->license_number }}</span>
                                    <span class="td-sub"><i class='bx bx-phone'></i> {{ $driver->user->phone ?? '-' }}</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="td-strong">{{ $driver->vehicle_plate }}</span>
                            <span class="td-sub" style="display:block;">Status Registrasi: {{ ucfirst($driver->registration_status) }}</span>
                        </td>
                        <td>
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                @foreach($docRows as $docRow)
                                    @php
                                        $status = $docRow['doc']->verification_status ?? 'pending';
                                        $badgeClass = $status === 'approved' ? 'badge-success' : ($status === 'rejected' ? 'badge-danger' : 'badge-warning');
                                    @endphp
                                    <span class="badge {{ $badgeClass }}" style="font-size: 10px; width: fit-content;">
                                        {{ strtoupper($docRow['label']) }}: {{ ucfirst($status) }}
                                    </span>
                                @endforeach
                            </div>
                        </td>
                        <td>
                            <span class="td-strong">{{ $driver->created_at?->diffForHumans() }}</span>
                            <span class="td-sub" style="display:block;">{{ $driver->created_at?->format('d M Y, H:i') }}</span>
                        </td>
                        <td class="td-action">
                            <div style="display:flex; gap: 8px;">
                                <button class="btn-action detail" style="width:auto; padding:0 12px; font-size:13px; font-weight:600; color:white; background:var(--color-primary);" title="Periksa Dokumen"><i class='bx bx-search-alt' style="margin-right:5px;"></i> Periksa</button>
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
    
</div>
@endsection
