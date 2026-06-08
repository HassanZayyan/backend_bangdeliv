@extends('layouts.admin')

@section('title', 'Detail Pesanan - Admin BangDeliv')
@section('page-title', 'Detail Pesanan')

@section('content')
@php
    $serviceCode = $order->serviceType?->code ?? 'UNKNOWN';
    $statusCode = $order->statusRef?->code ?? 'UNKNOWN';

    $serviceMap = [
        'SHOPPING' => ['label' => 'Titip Belanja', 'class' => 'badge-warning'],
        'COURIER' => ['label' => 'Kurir', 'class' => 'badge-info'],
        'RIDE' => ['label' => 'Antar Jemput', 'class' => 'badge-success'],
    ];

    $statusMap = [
        'PENDING' => ['label' => 'Menunggu Driver', 'class' => 'badge-warning'],
        'DRIVER_ASSIGNED' => ['label' => 'Driver Ditugaskan', 'class' => 'badge-info'],
        'ARRIVED_PICKUP' => ['label' => 'Tiba Pickup', 'class' => 'badge-info'],
        'ARRIVED_MERCHANT' => ['label' => 'Tiba Merchant', 'class' => 'badge-info'],
        'PICKED_UP' => ['label' => 'Pickup', 'class' => 'badge-info'],
        'ON_THE_WAY' => ['label' => 'Diantar', 'class' => 'badge-info'],
        'ARRIVED_DROPOFF' => ['label' => 'Tiba Tujuan', 'class' => 'badge-info'],
        'DELIVERED' => ['label' => 'Terkirim', 'class' => 'badge-success'],
        'COMPLETED' => ['label' => 'Selesai', 'class' => 'badge-success'],
        'CANCELLED' => ['label' => 'Dibatalkan', 'class' => 'badge-danger'],
        'CANCELLED_WITH_FEE' => ['label' => 'Batal Dengan Biaya', 'class' => 'badge-danger'],
        'COMPLAINT' => ['label' => 'Komplain', 'class' => 'badge-danger'],
    ];

    $serviceConfig = $serviceMap[$serviceCode] ?? ['label' => $serviceCode, 'class' => 'badge-info'];
    $statusConfig = $statusMap[$statusCode] ?? ['label' => $statusCode, 'class' => 'badge-info'];

    $locations = $order->orderLocations->sortBy('sequence_no')->values();
    $statusHistories = $order->statusHistories->sortByDesc('created_at')->values();
    $logs = $order->logs->sortByDesc('created_at')->take(10)->values();
    $shoppingFailedAttemptCount = $locations
        ->filter(fn ($location) => strtoupper((string) $location->location_role) === 'PICKUP')
        ->sum(fn ($location) => (int) ($location->failed_attempt_count ?? 0));
    $shoppingRecalculationVersion = (int) $order->logs
        ->where('event_type', 'PRICE_RECALCULATION')
        ->map(fn ($log) => (int) data_get($log->metadata ?? [], 'recalculation_version', 0))
        ->max();
    $payments = $order->payments
        ->sortByDesc(fn ($payment) => $payment->paid_at?->getTimestamp() ?? $payment->created_at?->getTimestamp() ?? 0)
        ->values();
    $latestPayment = $payments->first();

    if ($statusCode === 'PENDING') {
        $actionHints = [
            'Validasi alamat dan kontak pelanggan sebelum order diproses.',
            'Pastikan driver tersedia untuk area tujuan.',
            'Pantau perpindahan status agar SLA tetap terjaga.',
        ];
    } elseif (in_array($statusCode, ['DRIVER_ASSIGNED', 'PICKED_UP', 'ON_THE_WAY'], true)) {
        $actionHints = [
            'Pantau progres driver dan pastikan titik pickup-dropoff sesuai.',
            'Koordinasikan jika ada hambatan operasional di lapangan.',
            'Catat insiden agar bisa ditindaklanjuti pada log order.',
        ];
    } elseif (in_array($statusCode, ['COMPLETED', 'DELIVERED'], true)) {
        $actionHints = [
            'Verifikasi kelengkapan data pembayaran dan biaya layanan.',
            'Tinjau log perubahan harga jika ada rekalkulasi.',
            'Tutup tiket operasional jika tidak ada komplain.',
        ];
    } else {
        $actionHints = [
            'Tinjau alasan pembatalan/komplain sebelum eskalasi.',
            'Pastikan bukti pendukung sudah terdokumentasi.',
            'Koordinasikan tindak lanjut dengan tim support/operasional.',
        ];
    }
@endphp

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; gap:10px; flex-wrap:wrap;">
    <a href="{{ $backUrl }}" class="btn" style="background:var(--bg-hover); color:var(--text-main); border:1px solid var(--border-color); text-decoration:none;">
        <i class='bx bx-arrow-back'></i>
        Kembali ke Daftar
    </a>

    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
        <span class="badge {{ $serviceConfig['class'] }}">{{ $serviceConfig['label'] }}</span>
        <span class="badge {{ $statusConfig['class'] }}">{{ $statusConfig['label'] }}</span>
    </div>
</div>

<div style="display:grid; grid-template-columns: 1.2fr 1fr; gap:16px; margin-bottom:16px;">
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title">Ringkasan Pesanan #{{ $order->order_number }}</div>
        </div>
        <div style="padding:18px 20px; display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:12px 16px;">
            <div>
                <div class="td-sub">Waktu Order</div>
                <div class="td-strong">{{ $order->created_at?->format('d M Y, H:i') ?? '-' }}</div>
            </div>
            <div>
                <div class="td-sub">Total</div>
                <div class="td-strong" style="color:var(--color-primary);">Rp {{ number_format((float) $order->total_price, 0, ',', '.') }}</div>
            </div>
            <div>
                <div class="td-sub">Status Pembayaran</div>
                <div class="td-strong">{{ strtoupper((string) ($order->payment_status ?? '-')) }}</div>
            </div>
            <div>
                <div class="td-sub">Metode Pembayaran</div>
                <div class="td-strong">{{ strtoupper((string) ($order->payment_method ?? '-')) }}</div>
            </div>
            <div>
                <div class="td-sub">Pelanggan</div>
                <div class="td-strong">{{ $order->user?->name ?? '-' }}</div>
                <div class="td-sub">{{ $order->user?->phone ?? '-' }}</div>
            </div>
            <div>
                <div class="td-sub">Driver</div>
                <div class="td-strong">{{ $order->driver?->user?->name ?? '-' }}</div>
                <div class="td-sub">{{ $order->driver?->user?->phone ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div class="panel-title">Aksi Operasional</div>
        </div>
        <div style="padding:18px 20px; display:flex; flex-direction:column; gap:10px;">
            @foreach($actionHints as $hint)
                <div class="td-sub" style="display:flex; gap:8px; align-items:flex-start;">
                    <i class='bx bx-check-circle' style="color:var(--color-success); font-size:16px; margin-top:1px;"></i>
                    <span>{{ $hint }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>

@if($serviceCode === 'SHOPPING')
    <div class="panel" style="margin-bottom:16px;">
        <div class="panel-header">
            <div class="panel-title">Detail Titip Belanja</div>
        </div>
        <div style="padding:18px 20px; display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:12px 16px;">
            <div>
                <div class="td-sub">Restoran/Warung</div>
                <div class="td-strong">{{ $order->restaurant?->name ?? '-' }}</div>
            </div>
            <div>
                <div class="td-sub">Alamat Antar</div>
                <div class="td-strong">{{ $order->delivery_address ?? '-' }}</div>
            </div>
            <div>
                <div class="td-sub">Failed Attempt</div>
                <div class="td-strong">{{ $shoppingFailedAttemptCount }}</div>
            </div>
            <div>
                <div class="td-sub">Version Rekalkulasi</div>
                <div class="td-strong">{{ $shoppingRecalculationVersion }}</div>
            </div>
        </div>

        <div class="table-responsive" style="border-top:1px solid var(--border-color);">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Harga Satuan</th>
                        <th>Subtotal</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($order->items as $item)
                        <tr>
                            <td class="td-strong">{{ $item->menu_name ?? '-' }}</td>
                            <td class="td-sub">{{ $item->quantity }}</td>
                            <td class="td-sub">Rp {{ number_format((float) $item->unit_price, 0, ',', '.') }}</td>
                            <td class="td-sub">Rp {{ number_format((float) $item->subtotal, 0, ',', '.') }}</td>
                            <td class="td-sub">{{ $item->notes ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="td-sub" style="text-align:center; padding:20px;">Belum ada item belanja.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

@if($serviceCode === 'COURIER')
    @php
        $courierOrder = $order->courierOrder;
        $courierEvidenceCount = $order->evidences
            ->filter(fn ($evidence) => in_array(strtoupper((string) $evidence->evidence_type), ['PICKUP_PHOTO', 'DELIVERY_PHOTO', 'COURIER_DELIVERY_PHOTO', 'COURIER_RECEIVER_PHOTO'], true))
            ->count();
    @endphp
    <div class="panel" style="margin-bottom:16px;">
        <div class="panel-header">
            <div class="panel-title">Detail Kurir</div>
        </div>
        <div style="padding:18px 20px; display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:12px 16px;">
            <div>
                <div class="td-sub">Deskripsi Paket</div>
                <div class="td-strong">{{ $courierOrder?->package_description ?? '-' }}</div>
            </div>
            <div>
                <div class="td-sub">Bukti Foto Kurir</div>
                <div class="td-strong">{{ $courierEvidenceCount }} bukti di order evidence</div>
            </div>
        </div>
    </div>
@endif

@if($serviceCode === 'RIDE')
    <div class="panel" style="margin-bottom:16px;">
        <div class="panel-header">
            <div class="panel-title">Detail Antar Jemput</div>
        </div>
        <div style="padding:18px 20px; display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:12px 16px;">
            <div>
                <div class="td-sub">Waktu Jemput</div>
                <div class="td-strong">{{ $order->rideOrder?->picked_up_at?->format('d M Y, H:i') ?? '-' }}</div>
            </div>
            <div>
                <div class="td-sub">Waktu Tiba</div>
                <div class="td-strong">{{ $order->rideOrder?->arrived_at?->format('d M Y, H:i') ?? '-' }}</div>
            </div>
            <div style="grid-column: 1 / -1;">
                <div class="td-sub">Catatan Perjalanan</div>
                <div class="td-strong">{{ $order->rideOrder?->notes ?? '-' }}</div>
            </div>
        </div>
    </div>
@endif

<div class="panel" style="margin-bottom:16px;">
    <div class="panel-header">
        <div class="panel-title">Pembayaran COD</div>
    </div>
    <div style="padding:18px 20px; display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:12px 16px;">
        <div>
            <div class="td-sub">Metode</div>
            <div class="td-strong">{{ strtoupper((string) ($latestPayment?->payment_method ?? $order->payment_method ?? 'COD')) }}</div>
        </div>
        <div>
            <div class="td-sub">Status</div>
            <div class="td-strong">{{ strtoupper((string) ($latestPayment?->payment_status ?? $order->payment_status ?? 'UNPAID')) }}</div>
        </div>
        <div>
            <div class="td-sub">Nominal</div>
            <div class="td-strong">Rp {{ number_format((float) ($latestPayment?->amount ?? $order->total_price), 0, ',', '.') }}</div>
        </div>
        <div>
            <div class="td-sub">Waktu Bayar</div>
            <div class="td-strong">{{ $latestPayment?->paid_at?->format('d M Y, H:i') ?? '-' }}</div>
        </div>
        <div>
            <div class="td-sub">Dicatat Oleh</div>
            <div class="td-strong">{{ $latestPayment?->recordedBy?->name ?? '-' }}</div>
        </div>
        <div>
            <div class="td-sub">Driver</div>
            <div class="td-strong">{{ $latestPayment?->driver?->user?->name ?? $order->driver?->user?->name ?? '-' }}</div>
        </div>
        <div>
            <div class="td-sub">Sumber Pencatatan</div>
            <div class="td-strong">{{ data_get($latestPayment?->metadata, 'source', '-') }}</div>
        </div>
    </div>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title">Lokasi Order</div>
        </div>
        <div style="padding:18px 20px; display:flex; flex-direction:column; gap:12px;">
            @forelse($locations as $location)
                <div style="padding:12px; border:1px solid var(--border-color); border-radius:8px;">
                    <div style="display:flex; justify-content:space-between; gap:8px; align-items:center;">
                        <div class="td-strong">{{ $location->location_role === 'PICKUP' ? 'Pickup' : 'Dropoff' }} {{ $location->label ? '- '.$location->label : '' }}</div>
                        <div class="td-sub">#{{ $location->sequence_no }}</div>
                    </div>
                    <div class="td-sub" style="margin-top:4px;">{{ $location->contact_name ?? '-' }} | {{ $location->contact_phone ?? '-' }}</div>
                    <div class="td-sub" style="margin-top:2px;">{{ $location->full_address }}</div>
                    <div class="td-sub" style="margin-top:2px;">Lat {{ $location->latitude }} | Lng {{ $location->longitude }}</div>
                    @if($location->notes)
                        <div class="td-sub" style="margin-top:2px;">Catatan: {{ $location->notes }}</div>
                    @endif
                </div>
            @empty
                <div class="td-sub">Belum ada data titik pickup/dropoff pada order ini.</div>
            @endforelse
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div class="panel-title">Riwayat Status</div>
        </div>
        <div style="padding:18px 20px; display:flex; flex-direction:column; gap:12px;">
            @forelse($statusHistories as $history)
                @php
                    $historyCode = $history->statusRef?->code ?? 'UNKNOWN';
                    $historyConfig = $statusMap[$historyCode] ?? ['label' => $historyCode, 'class' => 'badge-info'];
                @endphp
                <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start; border-bottom:1px solid var(--border-color); padding-bottom:10px;">
                    <div>
                        <span class="badge {{ $historyConfig['class'] }}">{{ $historyConfig['label'] }}</span>
                        <div class="td-sub" style="margin-top:5px;">{{ $history->note ?? '-' }}</div>
                    </div>
                    <div class="td-sub" style="text-align:right; min-width:130px;">
                        <div>{{ $history->created_at?->format('d M Y') ?? '-' }}</div>
                        <div>{{ $history->created_at?->format('H:i') ?? '-' }}</div>
                    </div>
                </div>
            @empty
                <div class="td-sub">Belum ada riwayat status.</div>
            @endforelse
        </div>
    </div>
</div>

<div class="panel" style="margin-top:16px;">
    <div class="panel-header">
        <div class="panel-title">Log Perubahan Terakhir</div>
    </div>
    <div class="table-responsive">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Tipe Log</th>
                    <th>Trigger</th>
                    <th>Delta Total</th>
                    <th>Catatan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td class="td-sub">{{ $log->created_at?->format('d M Y, H:i') ?? '-' }}</td>
                        <td class="td-sub">{{ $log->log_type }}</td>
                        <td class="td-sub">{{ $log->trigger_type ?? '-' }}</td>
                        <td class="td-sub">{{ $log->priceChange?->delta_total_price !== null ? 'Rp '.number_format((float) $log->priceChange->delta_total_price, 0, ',', '.') : '-' }}</td>
                        <td class="td-sub">{{ $log->note ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="td-sub" style="text-align:center; padding:20px;">Belum ada log perubahan.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
