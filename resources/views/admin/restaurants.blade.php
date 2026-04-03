@extends('layouts.admin')

@section('title', 'Restoran / Warung - Admin BangDeliv')
@section('page-title', 'Mitra Restoran')

@section('content')
<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="panel-title">Daftar Mitra Restoran & Warung</div>
            
            <div style="display: flex; gap: 10px;">
                <button class="btn btn-primary">
                    <i class='bx bx-plus'></i> Tambah Mitra
                </button>
                <div class="search-bar" style="width: 280px;">
                    <i class='bx bx-search'></i>
                    <input type="text" placeholder="Cari Resto, Owner, ID..." style="width: 100%;">
                </div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active">Semua</button>
            <button class="tab-btn">Buka</button>
            <button class="tab-btn">Tutup (Luar Jam)</button>
            <button class="tab-btn">Suspended <span class="badge badge-danger" style="margin-left:5px;">1</span></button>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Info Restoran</th>
                    <th>Detail Menu</th>
                    <th>Rating & Omset</th>
                    <th>Status Live</th>
                    <th>Aksi & Kelola Menu</th>
                </tr>
            </thead>
            <tbody>
                <!-- Dummy Resto 1 -->
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 50px; height: 50px; border-radius: 10px; background-color: var(--color-primary); display: flex; align-items:center; justify-content:center; color:white; font-size:24px; flex-shrink:0;">
                                <i class='bx bx-restaurant'></i>
                            </div>
                            <div class="td-user">
                                <span class="td-strong">Ayam Geprek Juara</span>
                                <span class="td-sub"><i class='bx bxs-user-badge'></i> Herman K. (0812-xxxx)</span>
                                <span class="td-sub"><i class='bx bx-map-pin'></i> Jl. Veteran No.12</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">45 Item Menu</span>
                        <span class="td-sub" style="display:block; color:var(--color-success);"><i class='bx bxs-offer'></i> 2 Promo Aktif</span>
                    </td>
                    <td>
                        <span class="td-strong" style="color:var(--color-warning);"><i class='bx bxs-star'></i> 4.8</span>
                        <span class="td-sub" style="display:block;">1,520 Terjual</span>
                    </td>
                    <td><span class="badge badge-success">Buka</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" style="width:auto; padding:0 12px; font-size:13px; font-weight:600; color:var(--color-primary); background:rgba(255,119,0,0.1);" title="Kelola Katalog Menu"><i class='bx bx-food-menu' style="margin-right:5px;"></i> Kelola Menu</button>
                            <button class="btn-action danger" title="Suspend Restoran"><i class='bx bx-block'></i></button>
                        </div>
                    </td>
                </tr>

                <!-- Dummy Resto 2 -->
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 50px; height: 50px; border-radius: 10px; background-color: var(--color-info); display: flex; align-items:center; justify-content:center; color:white; font-size:24px; flex-shrink:0;">
                                <i class='bx bx-coffee-togo'></i>
                            </div>
                            <div class="td-user">
                                <span class="td-strong">Kopi Senja Masa</span>
                                <span class="td-sub"><i class='bx bxs-user-badge'></i> Dimas A. (0877-xxxx)</span>
                                <span class="td-sub"><i class='bx bx-map-pin'></i> Komplek Ruko A-1</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">28 Item Menu</span>
                        <span class="td-sub" style="display:block;"><i class='bx bxs-offer'></i> Tidak ada promo</span>
                    </td>
                    <td>
                        <span class="td-strong" style="color:var(--color-warning);"><i class='bx bxs-star'></i> 4.9</span>
                        <span class="td-sub" style="display:block;">830 Terjual</span>
                    </td>
                    <td><span class="badge badge-success">Buka</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" style="width:auto; padding:0 12px; font-size:13px; font-weight:600; color:var(--color-primary); background:rgba(255,119,0,0.1);" title="Kelola Katalog Menu"><i class='bx bx-food-menu' style="margin-right:5px;"></i> Kelola Menu</button>
                            <button class="btn-action danger" title="Suspend Restoran"><i class='bx bx-block'></i></button>
                        </div>
                    </td>
                </tr>

                <!-- Dummy Resto 3 -->
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 50px; height: 50px; border-radius: 10px; background-color: #a855f7; display: flex; align-items:center; justify-content:center; color:white; font-size:24px; flex-shrink:0;">
                                <i class='bx bx-bowl-rice'></i>
                            </div>
                            <div class="td-user">
                                <span class="td-strong">Warung Bu Sri</span>
                                <span class="td-sub"><i class='bx bxs-user-badge'></i> Sri W. (0821-xxxx)</span>
                                <span class="td-sub"><i class='bx bx-map-pin'></i> Jl. Mawar No.4</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">15 Item Menu</span>
                        <span class="td-sub" style="display:block;"><i class='bx bxs-offer'></i> Tidak ada promo</span>
                    </td>
                    <td>
                        <span class="td-strong" style="color:var(--color-warning);"><i class='bx bxs-star'></i> 4.5</span>
                        <span class="td-sub" style="display:block;">420 Terjual</span>
                    </td>
                    <td><span class="badge badge-info">Tutup (Luar Jam)</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" style="width:auto; padding:0 12px; font-size:13px; font-weight:600; color:var(--color-primary); background:rgba(255,119,0,0.1);" title="Kelola Katalog Menu"><i class='bx bx-food-menu' style="margin-right:5px;"></i> Kelola Menu</button>
                            <button class="btn-action danger" title="Suspend Restoran"><i class='bx bx-block'></i></button>
                        </div>
                    </td>
                </tr>

                 <!-- Dummy Resto 4 -->
                 <tr style="background-color: rgba(239, 68, 68, 0.02);">
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 50px; height: 50px; border-radius: 10px; background-color: var(--color-danger); display: flex; align-items:center; justify-content:center; color:white; font-size:24px; flex-shrink:0;">
                                <i class='bx bx-error'></i>
                            </div>
                            <div class="td-user">
                                <span class="td-strong">Mie Level Neraka</span>
                                <span class="td-sub"><i class='bx bxs-user-badge'></i> Budi J. (0899-xxxx)</span>
                                <span class="td-sub"><i class='bx bx-map-pin'></i> Jl. Neraka Jahanam 1</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">0 Item Aktif</span>
                        <span class="td-sub" style="display:block; color:var(--color-danger);">Banyak Laporan Palsu</span>
                    </td>
                    <td>
                        <span class="td-strong" style="color:var(--color-danger);"><i class='bx bxs-star'></i> 1.2</span>
                        <span class="td-sub" style="display:block;">20 Terjual</span>
                    </td>
                    <td><span class="badge badge-danger">Suspended</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" style="width:auto; padding:0 12px; font-size:13px; font-weight:600; color:var(--text-muted); background:var(--bg-hover);" title="Lihat Laporan"><i class='bx bx-message-error' style="margin-right:5px;"></i> Log</button>
                            <button class="btn-action" style="background: rgba(16, 185, 129, 0.1); color: var(--color-success);" title="Buka Suspend"><i class='bx bx-check'></i></button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="panel-pagination" style="padding: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">Menampilkan 4 dari 32 Mitra Restoran</span>
        <div class="pagination-controls" style="display: flex; gap: 6px;">
            <button class="btn-page active">1</button>
            <button class="btn-page">2</button>
            <button class="btn-page"><i class='bx bx-chevron-right'></i></button>
        </div>
    </div>
</div>
@endsection
