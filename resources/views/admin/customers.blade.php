@extends('layouts.admin')

@section('title', 'Pelanggan - Admin BangDeliv')
@section('page-title', 'Manajemen Pelanggan')

@section('content')
<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="panel-title">Daftar Pengguna App (Customer)</div>
            
            <div style="display: flex; gap: 10px;">
                <button class="btn" style="background: var(--bg-hover); color: var(--text-main); border: 1px solid var(--border-color);">
                    <i class='bx bx-filter-alt'></i> Filter
                </button>
                <div class="search-bar" style="width: 280px;">
                    <i class='bx bx-search'></i>
                    <input type="text" placeholder="Cari Nama, Email, atau HP..." style="width: 100%;">
                </div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active">Semua</button>
            <button class="tab-btn">Aktif</button>
            <button class="tab-btn">Pelanggan Baru</button>
            <button class="tab-btn">Blacklisted <span class="badge badge-danger" style="margin-left:5px;">2</span></button>
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
                <!-- Dummy Customer 1 -->
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="driver-avatar sari" style="width: 45px; height: 45px; flex-shrink: 0;">AD</div>
                            <div class="td-user">
                                <span class="td-strong">Ayu Diana</span>
                                <span class="td-sub">Bergabung: 12 Feb 2026</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong"><i class='bx bx-envelope'></i> ayu.d@gmail.com</span>
                        <span class="td-sub" style="display:block;"><i class='bx bx-phone'></i> 08122334455</span>
                    </td>
                    <td>
                        <span class="td-strong" style="color:var(--color-success);">24 Sukses</span>
                        <span class="td-sub" style="display:block;">1 Dibatalkan</span>
                    </td>
                    <td><span class="badge badge-success">Aktif</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" title="Lihat History & Detail"><i class='bx bx-show'></i></button>
                            <button class="btn-action danger" title="Blacklist Akun"><i class='bx bx-block'></i></button>
                        </div>
                    </td>
                </tr>

                <!-- Dummy Customer 2 -->
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="driver-avatar agus" style="width: 45px; height: 45px; flex-shrink: 0;">HN</div>
                            <div class="td-user">
                                <span class="td-strong">Hassan N.</span>
                                <span class="td-sub">Bergabung: 01 Mar 2026</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong"><i class='bx bx-envelope'></i> hassan99@yahoo.com</span>
                        <span class="td-sub" style="display:block;"><i class='bx bx-phone'></i> 0812345678</span>
                    </td>
                    <td>
                        <span class="td-strong" style="color:var(--color-success);">12 Sukses</span>
                        <span class="td-sub" style="display:block;">0 Dibatalkan</span>
                    </td>
                    <td><span class="badge badge-success">Aktif</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" title="Lihat History & Detail"><i class='bx bx-show'></i></button>
                            <button class="btn-action danger" title="Blacklist Akun"><i class='bx bx-block'></i></button>
                        </div>
                    </td>
                </tr>

                <!-- Dummy Customer 3 -->
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="driver-avatar rendi" style="width: 45px; height: 45px; flex-shrink: 0;">FP</div>
                            <div class="td-user">
                                <span class="td-strong">Fauzan Putra</span>
                                <span class="td-sub">Bergabung: Hari ini</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong"><i class='bx bx-envelope'></i> fauz.p@gmail.com</span>
                        <span class="td-sub" style="display:block;"><i class='bx bx-phone'></i> 0855998811</span>
                    </td>
                    <td>
                        <span class="td-strong" style="color:var(--text-muted);">0 Sukses</span>
                        <span class="td-sub" style="display:block;">0 Dibatalkan</span>
                    </td>
                    <td><span class="badge badge-info">Pelanggan Baru</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" title="Lihat History & Detail"><i class='bx bx-show'></i></button>
                            <button class="btn-action danger" title="Blacklist Akun"><i class='bx bx-block'></i></button>
                        </div>
                    </td>
                </tr>

                 <!-- Dummy Customer 4 (Blacklisted) -->
                 <tr style="background-color: rgba(239, 68, 68, 0.02);">
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="driver-avatar" style="background-color: var(--color-danger); width: 45px; height: 45px; flex-shrink: 0;">RM</div>
                            <div class="td-user">
                                <span class="td-strong">Rizal M. (Fraud)</span>
                                <span class="td-sub">Bergabung: 10 Jan 2026</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong"><i class='bx bx-envelope'></i> rizal.fake@email.com</span>
                        <span class="td-sub" style="display:block;"><i class='bx bx-phone'></i> 088899990000</span>
                    </td>
                    <td>
                        <span class="td-strong" style="color:var(--color-success);">2 Sukses</span>
                        <span class="td-sub" style="color:var(--color-danger); display:block; font-weight:600;">14 Dibatalkan</span>
                    </td>
                    <td><span class="badge badge-danger"><i class='bx bx-x-circle'></i> Blacklisted</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" title="Lihat History & Detail"><i class='bx bx-show'></i></button>
                            <button class="btn-action" style="background: rgba(16, 185, 129, 0.1); color: var(--color-success);" title="Lepas Blacklist (Unblock)"><i class='bx bx-check'></i></button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="panel-pagination" style="padding: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">Menampilkan 4 dari 89 Pelanggan</span>
        <div class="pagination-controls" style="display: flex; gap: 6px;">
            <button class="btn-page active">1</button>
            <button class="btn-page">2</button>
            <button class="btn-page">3</button>
            <button class="btn-page">4</button>
            <button class="btn-page">5</button>
            <button class="btn-page"><i class='bx bx-dots-horizontal-rounded'></i></button>
            <button class="btn-page"><i class='bx bx-chevron-right'></i></button>
        </div>
    </div>
</div>
@endsection
