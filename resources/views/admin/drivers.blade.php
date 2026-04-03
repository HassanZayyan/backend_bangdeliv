@extends('layouts.admin')

@section('title', 'Driver - Admin BangDeliv')
@section('page-title', 'Manajemen Driver')

@section('content')
<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="panel-title">Daftar Mitra Driver</div>
            
            <div style="display: flex; gap: 10px;">
                <button class="btn" style="background: var(--bg-hover); color: var(--text-main); border: 1px solid var(--border-color);">
                    <i class='bx bx-filter-alt'></i> Filter
                </button>
                <div class="search-bar" style="width: 280px;">
                    <i class='bx bx-search'></i>
                    <input type="text" placeholder="Cari Nama, Plat Nomor, atau HP..." style="width: 100%;">
                </div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active">Semua</button>
            <button class="tab-btn">Aktif (Online)</button>
            <button class="tab-btn">Offline</button>
            <button class="tab-btn">Suspended <span class="badge badge-danger" style="margin-left:5px;">1</span></button>
            <button class="tab-btn">Pending Verifikasi <span class="badge badge-warning" style="margin-left:5px;">3</span></button>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Profil Driver</th>
                    <th>Kendaraan</th>
                    <th>Performa & Trip</th>
                    <th>Status Akun</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <!-- Dummy Driver 1 -->
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="driver-avatar" style="width: 45px; height: 45px; flex-shrink: 0;">BS</div>
                            <div class="td-user">
                                <span class="td-strong">Budi Santoso</span>
                                <span class="td-sub"><i class='bx bx-phone'></i> 0899112233</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">B 1234 XYZ</span>
                        <span class="td-sub" style="display:block;">Honda Vario (Hitam)</span>
                    </td>
                    <td>
                        <span class="td-strong" style="color:var(--color-warning);"><i class='bx bxs-star'></i> 4.9</span>
                        <span class="td-sub" style="display:block;">1,240 Trip Selesai</span>
                    </td>
                    <td><span class="badge badge-success">Aktif (Online)</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" title="Lihat Profil Lengkap"><i class='bx bx-id-card'></i></button>
                            <button class="btn-action danger" title="Suspend Akun"><i class='bx bx-block'></i></button>
                        </div>
                    </td>
                </tr>

                <!-- Dummy Driver 2 -->
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="driver-avatar sari" style="width: 45px; height: 45px; flex-shrink: 0;">SW</div>
                            <div class="td-user">
                                <span class="td-strong">Sari Wulandari</span>
                                <span class="td-sub"><i class='bx bx-phone'></i> 0855667788</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">H 5555 ABC</span>
                        <span class="td-sub" style="display:block;">Yamaha NMAX (Putih)</span>
                    </td>
                    <td>
                        <span class="td-strong" style="color:var(--color-warning);"><i class='bx bxs-star'></i> 4.8</span>
                        <span class="td-sub" style="display:block;">850 Trip Selesai</span>
                    </td>
                    <td><span class="badge badge-success">Aktif (Pesan Masuk)</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" title="Lihat Profil Lengkap"><i class='bx bx-id-card'></i></button>
                            <button class="btn-action danger" title="Suspend Akun"><i class='bx bx-block'></i></button>
                        </div>
                    </td>
                </tr>

                <!-- Dummy Driver 3 -->
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="driver-avatar agus" style="width: 45px; height: 45px; flex-shrink: 0;">AP</div>
                            <div class="td-user">
                                <span class="td-strong">Agus Prasetyo</span>
                                <span class="td-sub"><i class='bx bx-phone'></i> 0811998877</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">D 9876 KLM</span>
                        <span class="td-sub" style="display:block;">Honda Beat (Biru)</span>
                    </td>
                    <td>
                        <span class="td-strong" style="color:var(--color-warning);"><i class='bx bxs-star'></i> 4.5</span>
                        <span class="td-sub" style="display:block;">320 Trip Selesai</span>
                    </td>
                    <td><span class="badge badge-info">Offline</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" title="Lihat Profil Lengkap"><i class='bx bx-id-card'></i></button>
                            <button class="btn-action danger" title="Suspend Akun"><i class='bx bx-block'></i></button>
                        </div>
                    </td>
                </tr>

                 <!-- Dummy Driver 4 -->
                 <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="driver-avatar rendi" style="width: 45px; height: 45px; flex-shrink: 0;">RK</div>
                            <div class="td-user">
                                <span class="td-strong">Rendi K.</span>
                                <span class="td-sub"><i class='bx bx-phone'></i> 0822334455</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">B 4321 NOP</span>
                        <span class="td-sub" style="display:block;">Suzuki Address</span>
                    </td>
                    <td>
                        <span class="td-strong" style="color:var(--text-muted);">-</span>
                        <span class="td-sub" style="display:block;">0 Trip Selesai</span>
                    </td>
                    <td><span class="badge badge-danger">Suspended</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" title="Lihat Profil Lengkap"><i class='bx bx-id-card'></i></button>
                            <button class="btn-action" style="background: rgba(16, 185, 129, 0.1); color: var(--color-success);" title="Aktifkan Kembali"><i class='bx bx-check'></i></button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="panel-pagination" style="padding: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">Menampilkan 4 dari 8 Driver</span>
        <div class="pagination-controls" style="display: flex; gap: 6px;">
            <button class="btn-page active">1</button>
            <button class="btn-page"><i class='bx bx-chevron-right'></i></button>
        </div>
    </div>
</div>
@endsection
