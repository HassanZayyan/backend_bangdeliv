@extends('layouts.admin')

@section('title', 'Pesanan - Admin BangDeliv')
@section('page-title', 'Manajemen Pesanan')

@section('content')
<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="panel-title">Daftar Seluruh Pesanan</div>
            
            <div class="search-bar" style="width: 320px;">
                <i class='bx bx-search'></i>
                <input type="text" placeholder="Cari ID Pesanan, Pelanggan, Restoran..." style="width: 100%;">
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active">Semua</button>
            <button class="tab-btn">Menunggu Resto <span class="badge badge-warning" style="margin-left:5px;">2</span></button>
            <button class="tab-btn">Mencari Driver</button>
            <button class="tab-btn">Diantar <span class="badge badge-info" style="margin-left:5px;">5</span></button>
            <button class="tab-btn">Selesai</button>
            <button class="tab-btn">Batal</button>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>ID Pesanan</th>
                    <th>Waktu</th>
                    <th>Pelanggan</th>
                    <th>Restoran</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Aksi Darurat</th>
                </tr>
            </thead>
            <tbody>
                <!-- Dummy Order 1 -->
                <tr>
                    <td class="td-id">#BD-845</td>
                    <td class="td-sub">Hari ini, 14:30</td>
                    <td class="td-user">
                        <span class="td-strong">Hassan N.</span>
                        <span class="td-sub"><i class='bx bx-phone'></i> 0812345678</span>
                    </td>
                    <td class="td-resto">
                        <span class="td-strong">Ayam Geprek Juara</span>
                        <span class="td-sub">Jl. Veteran No.12</span>
                    </td>
                    <td class="td-price">Rp 55.000</td>
                    <td><span class="badge badge-warning">Menunggu Resto</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" title="Lihat Detail Log"><i class='bx bx-show'></i></button>
                            <button class="btn-action danger" title="Batalkan Paksa"><i class='bx bx-block'></i></button>
                        </div>
                    </td>
                </tr>

                <!-- Dummy Order 2 -->
                <tr>
                    <td class="td-id">#BD-844</td>
                    <td class="td-sub">Hari ini, 14:15</td>
                    <td class="td-user">
                        <span class="td-strong">Sari Wulandari</span>
                        <span class="td-sub"><i class='bx bx-phone'></i> 0855667788</span>
                    </td>
                    <td class="td-resto">
                        <span class="td-strong">Warung Bu Sri</span>
                        <span class="td-sub">Mie Ayam x2, Es Teh x2</span>
                    </td>
                    <td class="td-price">Rp 46.000</td>
                    <td><span class="badge badge-info">Diantar (Agus)</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" title="Lihat Detail Log"><i class='bx bx-show'></i></button>
                            <button class="btn-action warning" title="Reassign Driver"><i class='bx bx-transfer'></i></button>
                        </div>
                    </td>
                </tr>

                <!-- Dummy Order 3 -->
                <tr>
                    <td class="td-id">#BD-843</td>
                    <td class="td-sub">Hari ini, 13:50</td>
                    <td class="td-user">
                        <span class="td-strong">Budi Santoso</span>
                        <span class="td-sub"><i class='bx bx-phone'></i> 0899112233</span>
                    </td>
                    <td class="td-resto">
                        <span class="td-strong">Sate Madura Asli</span>
                        <span class="td-sub">Sate Ayam 20 tusuk</span>
                    </td>
                    <td class="td-price">Rp 60.000</td>
                    <td><span class="badge badge-success">Selesai</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" title="Lihat Detail Log"><i class='bx bx-show'></i></button>
                        </div>
                    </td>
                </tr>
                
                 <!-- Dummy Order 4 -->
                 <tr>
                    <td class="td-id">#BD-842</td>
                    <td class="td-sub">Hari ini, 13:10</td>
                    <td class="td-user">
                        <span class="td-strong">Andi M.</span>
                        <span class="td-sub"><i class='bx bx-phone'></i> 0811998877</span>
                    </td>
                    <td class="td-resto">
                        <span class="td-strong">Nasi Goreng Gila</span>
                        <span class="td-sub">Nasi Goreng Spesial x1</span>
                    </td>
                    <td class="td-price">Rp 35.000</td>
                    <td><span class="badge badge-danger">Dibatalkan</span></td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" title="Lihat Detail Log"><i class='bx bx-show'></i></button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="panel-pagination" style="padding: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">Menampilkan 4 dari 24 Pesanan</span>
        <div class="pagination-controls" style="display: flex; gap: 6px;">
            <button class="btn-page active">1</button>
            <button class="btn-page">2</button>
            <button class="btn-page">3</button>
            <button class="btn-page"><i class='bx bx-chevron-right'></i></button>
        </div>
    </div>
</div>
@endsection
