@extends('layouts.admin')

@section('title', 'Detail Pesanan - Admin Pelanggan 15')
@section('page-title', 'Detail Pesanan')

@section('content')
@php
    $serviceCode = $order->serviceType?->code ?? 'UNKNOWN';
    $paymentMethod = strtoupper((string) ($latestPayment?->payment_method ?? $order->payment_method ?? 'COD'));
    $paymentStatus = strtoupper((string) ($latestPayment?->payment_status ?? $order->payment_status ?? 'UNPAID'));
@endphp

<div class="page-actions">
    <a href="{{ $backUrl }}" class="btn btn-muted">
        <i class="bx bx-arrow-back" aria-hidden="true"></i>
        Kembali
    </a>
    <div class="badge-row">
        <span class="badge {{ $serviceConfig['class'] }}">{{ $serviceConfig['label'] }}</span>
        <span class="badge {{ $statusConfig['class'] }}">{{ $statusConfig['label'] }}</span>
    </div>
</div>

<div class="detail-grid">
    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title">Ringkasan #{{ $order->order_number }}</div>
                <div class="panel-description">{{ $order->created_at?->format('d M Y, H:i') ?? '-' }}</div>
            </div>
        </div>
        <div class="summary-grid">
            <div>
                <span>Total</span>
                <strong class="text-primary">Rp {{ number_format((float) $order->total_price, 0, ',', '.') }}</strong>
            </div>
            <div>
                <span>Pembayaran</span>
                <strong>{{ $paymentMethod }} - {{ $paymentStatus }}</strong>
            </div>
            <div>
                <span>Customer</span>
                <strong>{{ $order->user?->name ?? '-' }}</strong>
                <small>{{ $order->user?->phone ?? '-' }}</small>
            </div>
            <div>
                <span>Driver</span>
                <strong>{{ $order->driver?->user?->name ?? '-' }}</strong>
                <small>{{ $order->driver?->user?->phone ?? '-' }}</small>
            </div>
        </div>
    </section>

    <section class="panel" id="payment-proof">
        <div class="panel-header">
            <div>
                <div class="panel-title">Bukti QRIS</div>
                <div class="panel-description">Admin hanya memverifikasi bukti yang dikirim customer.</div>
            </div>
            <span class="badge {{ $paymentStatus === 'PAID' ? 'badge-success' : 'badge-warning' }}">{{ $paymentStatus }}</span>
        </div>
        <div class="proof-list">
            @forelse($paymentProofs as $proof)
                @php
                    $proofDecision = $paymentProofDecisions[$proof->id] ?? [
                        'status' => 'pending',
                        'label' => 'Menunggu Verifikasi',
                        'class' => 'badge-warning',
                        'decided_by' => null,
                        'decided_at' => null,
                        'reason' => null,
                    ];
                    $proofStatus = $proofDecision['status'];
                @endphp
                <div class="proof-item {{ $proofStatus === 'pending' ? 'has-review' : '' }}">
                    <a href="{{ $proof->file_url }}" target="_blank" rel="noopener noreferrer" class="proof-thumb" aria-label="Buka bukti QRIS">
                        <img src="{{ $proof->file_url }}" alt="Bukti QRIS untuk pesanan {{ $order->order_number }}">
                    </a>
                    <div class="proof-content {{ $proofStatus === 'pending' ? 'has-actions' : '' }}">
                        <div class="proof-details">
                            <div class="proof-meta">
                                <span class="badge {{ $proofDecision['class'] }}">{{ $proofDecision['label'] }}</span>
                                <small>{{ $proof->uploaded_at?->format('d M Y, H:i') ?? '-' }}</small>
                            </div>
                            <strong class="proof-customer">{{ $proof->user?->name ?? 'Customer' }}</strong>
                            <p class="proof-note">{{ $proof->notes ?: 'Tidak ada catatan.' }}</p>
                            @if($proofDecision['decided_by'] || $proofDecision['decided_at'])
                                <small>
                                    Diverifikasi oleh {{ $proofDecision['decided_by'] ?? '-' }}
                                    pada {{ $proofDecision['decided_at']?->format('d M Y, H:i') ?? '-' }}
                                </small>
                            @endif
                            @if($proofDecision['reason'])
                                <small class="text-danger">Alasan: {{ $proofDecision['reason'] }}</small>
                            @endif
                        </div>
                        @if($proofStatus === 'pending')
                            <div class="proof-actions">
                                <form method="POST" action="{{ route('admin.orders.payment-proofs.approve', ['order' => $order->id, 'evidence' => $proof->id]) }}" class="approve-form">
                                    @csrf
                                    <button type="submit" class="btn btn-primary" onclick="return confirm('Setujui bukti QRIS ini dan tandai pembayaran lunas?')">
                                        <i class="bx bx-check" aria-hidden="true"></i>
                                        Setujui
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.orders.payment-proofs.reject', ['order' => $order->id, 'evidence' => $proof->id]) }}" class="reject-form">
                                    @csrf
                                    <label for="rejection_reason_{{ $proof->id }}">Alasan penolakan</label>
                                    <textarea id="rejection_reason_{{ $proof->id }}" name="rejection_reason" class="form-control" rows="2" required placeholder="Contoh: nominal tidak sesuai atau bukti tidak jelas.">{{ old('rejection_reason') }}</textarea>
                                    <button type="submit" class="btn btn-danger">
                                        <i class="bx bx-x" aria-hidden="true"></i>
                                        Tolak
                                    </button>
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="empty-state">Belum ada bukti QRIS dari customer.</div>
            @endforelse
        </div>
    </section>
</div>

<div class="detail-grid secondary">
    <section class="panel">
        <div class="panel-header">
            <div class="panel-title">Detail Layanan</div>
        </div>
        @if($serviceCode === 'SHOPPING')
            <div class="table-responsive">
                <table class="orders-table compact-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Harga</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($order->items as $item)
                            <tr>
                                <td data-label="Item" class="mobile-card-primary">
                                    <span class="td-strong">{{ $item->menu_name ?? '-' }}</span>
                                    <span class="td-sub">{{ $item->notes ?? '-' }}</span>
                                </td>
                                <td data-label="Qty">{{ $item->quantity }}</td>
                                <td data-label="Harga">Rp {{ number_format((float) $item->unit_price, 0, ',', '.') }}</td>
                                <td data-label="Subtotal">Rp {{ number_format((float) $item->subtotal, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="empty-state">Belum ada item belanja.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif($serviceCode === 'COURIER')
            <div class="info-list">
                <div>
                    <span>Deskripsi Paket</span>
                    <strong>{{ $order->courierOrder?->package_description ?? '-' }}</strong>
                </div>
            </div>
        @else
            <div class="info-list">
                <div>
                    <span>Waktu Jemput</span>
                    <strong>{{ $order->rideOrder?->picked_up_at?->format('d M Y, H:i') ?? '-' }}</strong>
                </div>
                <div>
                    <span>Waktu Tiba</span>
                    <strong>{{ $order->rideOrder?->arrived_at?->format('d M Y, H:i') ?? '-' }}</strong>
                </div>
            </div>
        @endif
    </section>

    <section class="panel">
        <div class="panel-header">
            <div class="panel-title">Lokasi</div>
        </div>
        <div class="timeline-list">
            @forelse($locations as $location)
                <div class="timeline-item">
                    <span class="timeline-dot"></span>
                    <div>
                        <strong>{{ strtoupper((string) $location->location_role) === 'PICKUP' ? 'Pickup' : 'Dropoff' }} {{ $location->label ? '- '.$location->label : '' }}</strong>
                        <p>{{ $location->full_address }}</p>
                    </div>
                </div>
            @empty
                <div class="empty-state">Belum ada data lokasi.</div>
            @endforelse
        </div>
    </section>
</div>

<section class="panel">
    <div class="panel-header">
        <div class="panel-title">Timeline Status</div>
    </div>
    <div class="timeline-list horizontal">
        @forelse($statusHistories as $history)
            @php
                $historyCode = $history->statusRef?->code ?? 'UNKNOWN';
                $historyConfig = $statusMap[$historyCode] ?? ['label' => $historyCode, 'class' => 'badge-info'];
            @endphp
            <div class="timeline-item">
                <span class="timeline-dot"></span>
                <div>
                    <span class="badge {{ $historyConfig['class'] }}">{{ $historyConfig['label'] }}</span>
                    <p>{{ $history->note ?? '-' }}</p>
                    <small>{{ $history->created_at?->format('d M Y, H:i') ?? '-' }}</small>
                </div>
            </div>
        @empty
            <div class="empty-state">Belum ada riwayat status.</div>
        @endforelse
    </div>
</section>
@endsection
