@extends('layouts.admin')

@section('title', 'Detail Verifikasi Driver - Admin BangDeliv')
@section('page-title', 'Detail Verifikasi Driver')

@section('content')
@php
    $driver = $detail['driver'] ?? [];
    $documents = $detail['documents'] ?? [];
    $uploadedDocuments = collect($documents)->where('is_uploaded', true)->values();
@endphp

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
@if($errors->any())
    <div class="panel" style="margin-bottom: 12px; padding: 12px 16px; color: var(--color-danger);">
        <div style="font-weight: 700; margin-bottom: 6px;">Validasi gagal:</div>
        <ul style="margin:0; padding-left:18px;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="panel" style="margin-bottom: 12px;">
    <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
        <div>
            <div class="panel-title">{{ $driver['name'] ?? '-' }}</div>
            <div class="td-sub" style="margin-top:4px;">{{ $driver['email'] ?? '-' }} • {{ $driver['phone'] ?? '-' }}</div>
        </div>
        <a href="{{ route('admin.verification') }}" class="btn" style="text-decoration:none; background: var(--bg-hover); border: 1px solid var(--border-color); color: var(--text-main);">
            <i class='bx bx-arrow-back'></i> Kembali ke Antrean
        </a>
    </div>
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; padding: 20px; border-top: 1px solid var(--border-color);">
        <div>
            <div class="td-sub">Plat Kendaraan</div>
            <div class="td-strong">{{ $driver['vehicle_plate'] ?? '-' }}</div>
        </div>
        <div>
            <div class="td-sub">Status Registrasi</div>
            @php
                $registrationStatus = $driver['registration_status'] ?? 'pending';
                $registrationBadge = $registrationStatus === 'active' ? 'badge-success' : ($registrationStatus === 'rejected' ? 'badge-danger' : 'badge-warning');
            @endphp
            <span class="badge {{ $registrationBadge }}">{{ ucfirst($registrationStatus) }}</span>
        </div>
        <div>
            <div class="td-sub">Status Operasional</div>
            <div class="td-strong">{{ ucfirst($driver['status'] ?? 'offline') }}</div>
        </div>
    </div>
</div>

<form id="review-form" action="{{ route('admin.verification.review', ['driverId' => $driver['id']]) }}" method="POST">
    @csrf
</form>

<div class="panel">
        <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
            <div class="panel-title">Review Dokumen</div>
            <button type="submit" form="review-form" class="btn btn-primary" {{ $uploadedDocuments->isEmpty() ? 'disabled' : '' }}>
                <i class='bx bx-save'></i> Simpan Keputusan
            </button>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; padding: 20px; border-top: 1px solid var(--border-color);">
            @foreach($documents as $index => $document)
                @php
                    $docType = $document['document_type'] ?? '';
                    $docLabel = strtoupper($docType);
                    $status = old("documents.$index.verification_status", $document['verification_status'] ?? 'pending');
                    $reason = old("documents.$index.rejection_reason", $document['rejection_reason'] ?? '');
                    $badgeClass = $status === 'approved' ? 'badge-success' : ($status === 'rejected' ? 'badge-danger' : 'badge-warning');
                    $isUploaded = (bool) ($document['is_uploaded'] ?? false);
                    $fileExists = (bool) ($document['file_exists'] ?? false);
                @endphp
                <div class="panel" style="margin:0; border: 1px solid var(--border-color); overflow:hidden;">
                    <div style="padding: 12px 14px; border-bottom: 1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                        <div style="font-weight:700;">{{ $docLabel }}</div>
                        <span class="badge {{ $badgeClass }}">{{ ucfirst($status) }}</span>
                    </div>

                    <div style="padding: 12px 14px; display:flex; flex-direction:column; gap:10px;">
                        @if($isUploaded && $fileExists)
                            <a href="{{ route('admin.verification.documents.preview', ['driverId' => $driver['id'], 'documentType' => $docType]) }}" target="_blank" class="btn" style="text-decoration:none; text-align:center; border: 1px solid var(--border-color); background: var(--bg-hover); color: var(--text-main);">
                                <i class='bx bx-link-external'></i> Lihat Dokumen
                            </a>
                        @elseif($isUploaded)
                            <div class="td-sub" style="color: var(--color-warning);">Dokumen ada di database, tetapi file fisik tidak ditemukan.</div>
                        @else
                            <div class="td-sub" style="color: var(--color-danger);">Dokumen belum diunggah oleh driver.</div>
                        @endif

                        @if($isUploaded)
                            <input type="hidden" form="review-form" name="documents[{{ $index }}][document_type]" value="{{ $docType }}">

                            <label class="td-sub" style="font-weight:600;">Keputusan Verifikasi</label>
                            <select form="review-form" name="documents[{{ $index }}][verification_status]" class="form-control" style="width:100%; padding:10px; border-radius:10px; border:1px solid var(--border-color); background:var(--bg-main); color:var(--text-main);">
                                <option value="approved" {{ $status === 'approved' ? 'selected' : '' }}>Approved</option>
                                <option value="rejected" {{ $status === 'rejected' ? 'selected' : '' }}>Rejected</option>
                            </select>

                            <label class="td-sub" style="font-weight:600;">Alasan Penolakan (wajib jika rejected)</label>
                            <textarea form="review-form" name="documents[{{ $index }}][rejection_reason]" rows="3" class="form-control" style="width:100%; padding:10px; border-radius:10px; border:1px solid var(--border-color); background:var(--bg-main); color:var(--text-main);" placeholder="Contoh: Dokumen buram, data tidak terbaca.">{{ $reason }}</textarea>

                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top: 6px;">
                                <div class="td-sub" style="font-size:12px;">Verified at: {{ !empty($document['verified_at']) ? \Illuminate\Support\Carbon::parse($document['verified_at'])->format('d M Y, H:i') : '-' }}</div>

                                <form action="{{ route('admin.verification.documents.destroy', ['driverId' => $driver['id'], 'documentType' => $docType]) }}" method="POST" onsubmit="return confirm('Hapus dokumen {{ $docLabel }} ini?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-action danger" title="Hapus Dokumen">
                                        <i class='bx bx-trash'></i>
                                    </button>
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endsection
