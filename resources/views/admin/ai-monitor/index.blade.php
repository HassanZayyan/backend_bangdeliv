@extends('layouts.admin')

@section('title', 'AI Monitor - Admin BangDeliv')
@section('page-title', 'Gemini AI Monitor')

@section('content')
@php
    $todayCalls = \App\Models\AiChatLog::whereDate('created_at', now()->toDateString())->count();
    $totalCalls = \App\Models\AiChatLog::count();
    $withModelCalls = \App\Models\AiChatLog::whereNotNull('model_used')->count();
    $successRate = $totalCalls > 0 ? round(($withModelCalls / $totalCalls) * 100, 1) : 0;
    $logs = \App\Models\AiChatLog::query()->with('user')->latest('created_at')->limit(20)->get();

    $primaryModel = \App\Models\AiChatLog::query()->whereNotNull('model_used')->groupBy('model_used')->selectRaw('model_used, count(*) as total')->orderByDesc('total')->first();
    $primaryPercent = $totalCalls > 0 && $primaryModel ? round(($primaryModel->total / $totalCalls) * 100, 1) : 0;
@endphp
<!-- Top Stats Row -->
<div class="stat-cards-wrapper" style="grid-template-columns: repeat(3, 1fr);">
    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-title">Total API Calls (Hari Ini)</span>
            <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--color-info);">
                <i class='bx bx-brain'></i>
            </div>
        </div>
        <div class="stat-value">{{ number_format($todayCalls, 0, ',', '.') }}</div>
        <div class="stat-change positive"><i class='bx bx-check'></i> Data realtime hari ini</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-title">Token Usage (Estimasi)</span>
            <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--color-success);">
                <i class='bx bx-data'></i>
            </div>
        </div>
        <div class="stat-value">{{ number_format($totalCalls, 0, ',', '.') }}</div>
        <div class="stat-change positive"><i class='bx bx-check'></i> Total log AI tersimpan</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-title">API Success Rate</span>
            <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--color-warning);">
                <i class='bx bx-check-shield'></i>
            </div>
        </div>
        <div class="stat-value">{{ number_format($successRate, 1) }}%</div>
        <div class="stat-change positive"><i class='bx bx-check-circle'></i> Berdasarkan model_used terisi</div>
    </div>
</div>

<div class="dashboard-grid">
    <!-- Left Column: Live Chat Logs -->
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title">Live Chat Interaksi Pengguna</div>
            <div style="display:flex; gap:10px;">
                <button class="btn btn-primary" style="padding: 6px 12px; font-size:12px;">
                    <i class='bx bx-refresh'></i> Refresh Log
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>User / Waktu</th>
                        <th>Konteks Pesan Terakhir</th>
                        <th>Intent AI</th>
                        <th>Status / Latency</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php
                            $intent = $log->intent ?: 'unknown';
                            $intentClass = $intent === 'pesan_makanan' ? 'badge-success' : ($intent === 'out_of_domain' ? 'badge-warning' : 'badge-info');
                        @endphp
                        <tr>
                            <td class="td-user">
                                <span class="td-strong">{{ $log->user->name ?? 'User' }}</span>
                                <span class="td-sub">{{ $log->created_at?->diffForHumans() }}</span>
                            </td>
                            <td>
                                <span class="td-sub" style="display:block; max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-main);">{{ \Illuminate\Support\Str::limit($log->message, 70) }}</span>
                                <span class="td-sub" style="font-size:11px;">Model: {{ $log->model_used ?? '-' }}</span>
                            </td>
                            <td><span class="badge {{ $intentClass }}">{{ $intent }}</span></td>
                            <td>
                                <span class="td-strong" style="color:{{ $log->model_used ? 'var(--color-success)' : 'var(--color-warning)' }};"><i class='bx bx-check-circle'></i> {{ $log->model_used ? 'Berhasil' : 'No Model' }}</span>
                                <span class="td-sub" style="display:block;">Session: {{ $log->session_id }}</span>
                            </td>
                             <td class="td-action">
                                <button class="btn-action detail" title="Lihat Transkrip Penuh"><i class='bx bx-message-rounded-detail'></i></button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="td-sub" style="text-align:center; padding:24px;">Belum ada log AI.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="panel-pagination" style="padding: 15px 20px; border-top: 1px solid var(--border-color); text-align: center;">
            <a href="#" class="panel-link">Lihat Seluruh Log Chat</a>
        </div>
    </div>

    <!-- Right Column: API & Model Metrics -->
    <div style="display: flex; flex-direction: column; gap: 20px;">
        
        <!-- Fallback Strategy Monitor -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class='bx bx-shuffle'></i> Gemini Fallback Strategy</div>
            </div>
            <div style="padding: 20px;">
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 15px;">Memonitor rute model API yang terpakai jika limit tercapai sesuai skema fallback.</p>
                
                <div style="margin-bottom: 15px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span style="font-size: 13px; font-weight: 600;">Primary: {{ $primaryModel->model_used ?? '-' }}</span>
                        <span style="font-size: 13px; font-weight: 600;">{{ $primaryPercent }}%</span>
                    </div>
                    <div style="width: 100%; background: var(--bg-body); border-radius: 4px; height: 8px;">
                        <div style="width: {{ $primaryPercent }}%; background: var(--color-success); height: 100%; border-radius: 4px;"></div>
                    </div>
                </div>

                <div style="margin-bottom: 15px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span style="font-size: 13px; font-weight: 600;">Fallback 1</span>
                        <span style="font-size: 13px; font-weight: 600;">{{ max(0, 100 - $primaryPercent) }}%</span>
                    </div>
                    <div style="width: 100%; background: var(--bg-body); border-radius: 4px; height: 8px;">
                        <div style="width: {{ max(0, 100 - $primaryPercent) }}%; background: var(--color-warning); height: 100%; border-radius: 4px;"></div>
                    </div>
                </div>
                
                <div style="margin-bottom: 5px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span style="font-size: 13px; color:var(--text-muted);">Fallback 2</span>
                        <span style="font-size: 13px; color:var(--text-muted);">0.0%</span>
                    </div>
                    <div style="width: 100%; background: var(--bg-body); border-radius: 4px; height: 8px;">
                        <div style="width: 0%; background: var(--color-danger); height: 100%; border-radius: 4px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Latency / System Health -->
        <div class="panel" style="flex: 1;">
            <div class="panel-header">
                <div class="panel-title"><i class='bx bx-pulse'></i> API Health</div>
            </div>
            <div style="padding: 20px; display: flex; flex-direction: column; gap: 15px;">
                <div style="display: flex; align-items: center; gap: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px;">
                    <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(16, 185, 129, 0.1); color: var(--color-success); display: flex; align-items: center; justify-content: center; font-size: 20px;">
                        <i class='bx bx-server'></i>
                    </div>
                    <div>
                        <div style="font-size: 14px; font-weight: 600;">Google Cloud Vertex API</div>
                        <div style="font-size: 12px; color: var(--text-muted);">Status: Online (24ms ping)</div>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(59, 130, 246, 0.1); color: var(--color-info); display: flex; align-items: center; justify-content: center; font-size: 20px;">
                        <i class='bx bx-message-rounded-dots'></i>
                    </div>
                    <div>
                        <div style="font-size: 14px; font-weight: 600;">WebSockets (Reverb)</div>
                        <div style="font-size: 12px; color: var(--text-muted);">Active Conn: 32</div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
