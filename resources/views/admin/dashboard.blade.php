@extends('layouts.admin')

@section('title', 'Dashboard - BangDeliv Admin')
@section('page-title', 'Dashboard Utama')

@section('content')
<!-- Dashboard Stat Cards -->
<div class="stat-cards-wrapper">
    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-title">Pesanan Hari Ini</span>
            <div class="stat-icon" style="color:var(--color-primary)"><i class='bx bx-receipt'></i></div>
        </div>
        <div class="stat-value text-primary">24</div>
        <div class="stat-change positive">
            <i class='bx bx-up-arrow-alt'></i> 12% dari kemarin
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-title">Omset Hari Ini</span>
            <div class="stat-icon" style="color:var(--color-success)"><i class='bx bx-money'></i></div>
        </div>
        <div class="stat-value text-success">Rp 1,1M</div>
        <div class="stat-change positive">
            <i class='bx bx-up-arrow-alt'></i> 8% dari kemarin
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-title">Driver Aktif</span>
            <div class="stat-icon" style="color:#a855f7"><i class='bx bxs-car'></i></div>
        </div>
        <div class="stat-value" style="color:#a855f7">8</div>
        <div class="stat-change positive">
            <i class='bx bx-up-arrow-alt'></i> 2 dari kemarin
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-title">Pending Verifikasi</span>
            <div class="stat-icon" style="color:var(--color-info)"><i class='bx bx-id-card'></i></div>
        </div>
        <div class="stat-value text-warning">3</div>
        <div class="stat-change negative" style="color:var(--color-warning)">
            <i class='bx bx-right-arrow-alt'></i> 1 baru masuk
        </div>
    </div>
</div>

<!-- Main Dashboard Grid -->
<div class="dashboard-grid">
    <!-- Left Column: Tables -->
    <div style="display:flex; flex-direction:column; gap:20px;">
        <!-- Orders Table -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">Pesanan Terkini</h3>
                <a href="#" class="panel-link">Lihat Semua <i class='bx bx-right-arrow-alt'></i></a>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Pelanggan</th>
                            <th>Restoran</th>
                            <th>Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="td-id">#BD-830</td>
                            <td class="td-user">
                                <span class="td-strong">Hassan N.</span>
                                <span class="td-sub">Jl. Diponegoro No.5</span>
                            </td>
                            <td class="td-resto">
                                <span class="td-strong">Warung Bu Sri</span>
                                <span class="td-sub">Mie Ayam x2, Es Teh x2</span>
                            </td>
                            <td class="td-price">Rp 46.000</td>
                            <td><span class="badge badge-warning">Diantar</span></td>
                        </tr>
                        <tr>
                            <td class="td-id">#BD-829</td>
                            <td class="td-user">
                                <span class="td-strong">Zaky M.</span>
                                <span class="td-sub">Jl. Veteran No.12</span>
                            </td>
                            <td class="td-resto">
                                <span class="td-strong">Geprek Juara</span>
                                <span class="td-sub">Ayam Geprek x2</span>
                            </td>
                            <td class="td-price">Rp 36.000</td>
                            <td><span class="badge badge-warning">Pending</span></td>
                        </tr>
                        <tr>
                            <td class="td-id">#BD-828</td>
                            <td class="td-user">
                                <span class="td-strong">Rina S.</span>
                                <span class="td-sub">Jl. Kartini No.3</span>
                            </td>
                            <td class="td-resto">
                                <span class="td-strong">Warung Mbak Yuni</span>
                                <span class="td-sub">Soto Ayam x1</span>
                            </td>
                            <td class="td-price" style="color:var(--text-main)">Rp 18.000</td>
                            <td><span class="badge badge-success">Selesai</span></td>
                        </tr>
                        <tr>
                            <td class="td-id">#BD-827</td>
                            <td class="td-user">
                                <span class="td-strong">Dewi A.</span>
                            </td>
                            <td class="td-resto">
                                <span class="td-strong">Warung Pak Jo</span>
                                <span class="td-sub">Es Jeruk x3</span>
                            </td>
                            <td class="td-price" style="color:var(--text-main)">Rp 22.000</td>
                            <td><span class="badge badge-success">Selesai</span></td>
                        </tr>
                        <tr>
                            <td class="td-id">#BD-826</td>
                            <td class="td-user">
                                <span class="td-strong">Bima R.</span>
                            </td>
                            <td class="td-resto">
                                <span class="td-strong">Geprek Juara</span>
                                <span class="td-sub">Ayam Geprek x1</span>
                            </td>
                            <td class="td-price" style="color:var(--text-main)">Rp 29.000</td>
                            <td><span class="badge badge-danger">Batal</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                 <h3 class="panel-title">Riwayat / Log (Area Bawah)</h3>
                 <a href="#" class="panel-link">Laporan <i class='bx bx-right-arrow-alt'></i></a>
            </div>
            <div style="padding: 20px; color: var(--text-muted); font-size:14px; text-align:center;">
                 Grafik atau riwayat 7 hari terakhir dapat ditempatkan di sini.
            </div>
        </div>
    </div>

    <!-- Right Column: Drivers & AI -->
    <div style="display:flex; flex-direction:column; gap:20px;">
        <!-- Status Driver -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">Status Driver</h3>
                <a href="#" class="panel-link">Kelola <i class='bx bx-right-arrow-alt'></i></a>
            </div>
            <div class="driver-list">
                <!-- Driver 1 -->
                <div class="driver-item">
                    <div class="driver-avatar"><i class='bx bx-user'></i></div>
                    <div class="driver-info">
                        <div class="td-strong">Budi Santoso</div>
                        <div class="td-sub">Mengantarkan #BD-830</div>
                    </div>
                    <span class="badge badge-success">Aktif</span>
                </div>
                <!-- Driver 2 -->
                <div class="driver-item">
                    <div class="driver-avatar sari"><i class='bx bx-user'></i></div>
                    <div class="driver-info">
                        <div class="td-strong">Sari Wulandari</div>
                        <div class="td-sub">Mengantarkan #BD-829</div>
                    </div>
                    <span class="badge badge-success">Aktif</span>
                </div>
                <!-- Driver 3 -->
                <div class="driver-item">
                    <div class="driver-avatar agus"><i class='bx bx-user'></i></div>
                    <div class="driver-info">
                        <div class="td-strong">Agus Prasetyo</div>
                        <div class="td-sub">Menunggu pesanan</div>
                    </div>
                    <span class="badge" style="background:var(--bg-body); color:var(--text-muted)">Idle</span>
                </div>
                <!-- Driver 4 -->
                <div class="driver-item">
                    <div class="driver-avatar rendi"><i class='bx bx-user'></i></div>
                    <div class="driver-info">
                        <div class="td-strong">Rendi K.</div>
                        <div class="td-sub">Menunggu verifikasi</div>
                    </div>
                    <span class="badge badge-warning">Verif</span>
                </div>
            </div>
        </div>

        <!-- AI Monitor -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title" style="display:flex;align-items:center;gap:8px">
                    <i class='bx bxl-google' style="color:var(--color-primary)"></i> Gemini Monitor
                </h3>
                <a href="#" class="panel-link">Detail <i class='bx bx-right-arrow-alt'></i></a>
            </div>
            <div style="padding: 20px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:8px">
                    <span class="td-sub">Model Aktif</span>
                    <span class="td-strong" style="color:var(--color-primary)">gemini-2.5-flash</span>
                </div>
                <!-- Progress bar mockup -->
                <div style="width:100%; height:6px; background-color:var(--border-color); border-radius:3px; margin-bottom:12px; overflow:hidden">
                    <div style="width:34%; height:100%; background-color:var(--color-primary); border-radius:3px"></div>
                </div>
                <div style="display:flex; justify-content:space-between;">
                    <span class="td-sub">340 / 1.000 req/hari</span>
                    <span class="td-sub" style="color:var(--color-success)">66% tersisa</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
