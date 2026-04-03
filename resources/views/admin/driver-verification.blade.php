@extends('layouts.admin')

@section('title', 'Verifikasi Driver - Admin BangDeliv')
@section('page-title', 'Verifikasi Driver Baru')

@section('content')
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
            <button class="tab-btn active">Menunggu Verifikasi <span class="badge badge-warning" style="margin-left:5px;">3</span></button>
            <button class="tab-btn">Butuh Revisi Dokumen</button>
            <button class="tab-btn">Ditolak (Rejected)</button>
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
                <!-- Dummy Applicant 1 -->
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="driver-avatar sari" style="width: 45px; height: 45px; flex-shrink: 0;">JW</div>
                            <div class="td-user">
                                <span class="td-strong">Joko Widodo P.</span>
                                <span class="td-sub"><i class='bx bx-id-card'></i> 3201123456780001</span>
                                <span class="td-sub"><i class='bx bx-phone'></i> 085522334411</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">F 1234 AB</span>
                        <span class="td-sub" style="display:block;">Honda Supra X (2018)</span>
                    </td>
                    <td>
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <span class="badge badge-success" style="font-size: 10px; width: fit-content;"><i class='bx bx-check'></i> KTP Tervalidasi</span>
                            <span class="badge badge-success" style="font-size: 10px; width: fit-content;"><i class='bx bx-check'></i> SIM C Aktif (2028)</span>
                            <span class="badge badge-warning" style="font-size: 10px; width: fit-content;"><i class='bx bx-time'></i> STNK (Menunggu Pengecekan)</span>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">2 Jam Lalu</span>
                        <span class="td-sub" style="display:block;">03 April 2026, 15:10</span>
                    </td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" style="width:auto; padding:0 12px; font-size:13px; font-weight:600; color:white; background:var(--color-primary);" title="Periksa Dokumen"><i class='bx bx-search-alt' style="margin-right:5px;"></i> Periksa</button>
                        </div>
                    </td>
                </tr>

                <!-- Dummy Applicant 2 -->
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="driver-avatar agus" style="width: 45px; height: 45px; flex-shrink: 0;">DW</div>
                            <div class="td-user">
                                <span class="td-strong">Dwi Wahyudi</span>
                                <span class="td-sub"><i class='bx bx-id-card'></i> 3172233445560002</span>
                                <span class="td-sub"><i class='bx bx-phone'></i> 081199887766</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">B 9988 XYZ</span>
                        <span class="td-sub" style="display:block;">Yamaha NMAX (2022)</span>
                    </td>
                    <td>
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <span class="badge badge-success" style="font-size: 10px; width: fit-content;"><i class='bx bx-check'></i> KTP Tervalidasi</span>
                            <span class="badge badge-warning" style="font-size: 10px; width: fit-content;"><i class='bx bx-time'></i> SIM C (Menunggu Pengecekan)</span>
                            <span class="badge badge-warning" style="font-size: 10px; width: fit-content;"><i class='bx bx-time'></i> STNK (Menunggu Pengecekan)</span>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">Kemarin</span>
                        <span class="td-sub" style="display:block;">02 April 2026, 09:45</span>
                    </td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action detail" style="width:auto; padding:0 12px; font-size:13px; font-weight:600; color:white; background:var(--color-primary);" title="Periksa Dokumen"><i class='bx bx-search-alt' style="margin-right:5px;"></i> Periksa</button>
                        </div>
                    </td>
                </tr>

                <!-- Dummy Applicant 3 -->
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="driver-avatar rendi" style="width: 45px; height: 45px; flex-shrink: 0;">SA</div>
                            <div class="td-user">
                                <span class="td-strong">Siti Aisyah</span>
                                <span class="td-sub"><i class='bx bx-id-card'></i> 3305566778890003</span>
                                <span class="td-sub"><i class='bx bx-phone'></i> 087755664433</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">H 3322 K L</span>
                        <span class="td-sub" style="display:block;">Honda Scoopy (2020)</span>
                    </td>
                    <td>
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <span class="badge badge-danger" style="font-size: 10px; width: fit-content;"><i class='bx bx-x'></i> KTP Buram / Tidak Terbaca</span>
                            <span class="badge badge-success" style="font-size: 10px; width: fit-content;"><i class='bx bx-check'></i> SIM C Aktif</span>
                            <span class="badge badge-success" style="font-size: 10px; width: fit-content;"><i class='bx bx-check'></i> STNK Aktif</span>
                        </div>
                    </td>
                    <td>
                        <span class="td-strong">2 Hari Lalu</span>
                        <span class="td-sub" style="display:block;">01 April 2026, 11:20</span>
                    </td>
                    <td class="td-action">
                        <div style="display:flex; gap: 8px;">
                            <button class="btn-action warning" style="width:auto; padding:0 12px; font-size:13px; font-weight:600;" title="Kirim Notif Revisi Dokumen"><i class='bx bx-error-circle' style="margin-right:5px;"></i> Minta Revisi</button>
                        </div>
                    </td>
                </tr>

            </tbody>
        </table>
    </div>
    
</div>
@endsection
