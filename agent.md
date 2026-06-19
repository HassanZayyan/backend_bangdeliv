# Agent Rules: Mega Refactor TA

## Audit Workflow

- Saat user memberikan path file untuk dianalisis, baca file tersebut secara keseluruhan sebelum membuat rangkuman.
- Default pembacaan file adalah satu kali read penuh dari awal sampai akhir, tanpa chunk, selama output masih muat dan tidak terpotong.
- Untuk file besar yang read penuhnya terpotong oleh output/tool limit, chunk boleh dipakai sebagai fallback hanya agar seluruh file tetap terbaca; chunk harus berurutan dari awal sampai akhir dan tidak boleh lompat bagian.
- Jika file yang diminta user terlalu panjang/berat sehingga tidak bisa dibaca secara keseluruhan dalam turn tersebut, hentikan audit file itu dan beri tahu user; jangan membuat rangkuman dari pembacaan parsial.
- Jika pembacaan file terpotong oleh output/tool limit dan fallback chunk belum/ tidak bisa menyelesaikan seluruh file, jangan membuat rangkuman parsial.
- Catat hasil analisis ke satu file audit saja: `Backend_Bangdeliv/docs/mega_refactor_TA.md`.
- Rangkuman per file harus singkat, tetapi tetap menunjukkan bahwa seluruh file sudah dibaca.
- Untuk setiap file, catat:
  - path file;
  - fungsi utama file;
  - logic penting;
  - redundansi atau bagian yang bisa disederhanakan;
  - risiko jika bagian tersebut dihapus;
  - catatan khusus jika file mengandung logic service fee.
- Perlakukan `Backend_Bangdeliv/docs/mega_refactor_TA.md` sebagai dokumen hidup:
  - jika analisis file baru menghilangkan keraguan pada catatan file lama, revisi entry file lama;
  - jika analisis file baru membuktikan asumsi lama salah atau kurang tepat, koreksi entry file lama;
  - tandai keputusan yang masih bergantung pada file lain sebagai kandidat bersyarat, lalu ubah menjadi kandidat tegas ketika bukti sudah cukup;
  - jangan menumpuk catatan ragu yang sudah terjawab oleh audit berikutnya.
- Jangan melakukan refactor kode saat fase audit kecuali user meminta implementasi.

## Service Fee Refactor Notice Rules

Gunakan `Backend_Bangdeliv/docs/minimal_refactor_service_fee_plan.md` sebagai aturan utama saat menemukan logic service fee.

Tandai sebagai kandidat hapus/refactor jika file mengandung:

- `service_fee_rules`;
- `ServiceFeeRule`;
- `order_fee_lines`;
- `OrderFeeLine`;
- relasi atau eager load `feeLines`;
- `fee_breakdown` atau `fee_lines` yang berasal dari table `order_fee_lines`;
- item surcharge / `ITEM_BLOCK_SURCHARGE`;
- overweight surcharge / `OVERWEIGHT_FLAT_SURCHARGE`;
- Courier `careful_carry_required`;
- UI/text "Perlu 2 orang";
- surcharge Courier "perlu 2 orang" / careful carry;
- request/response field `careful_carry_required`.

Tandai sebagai logic yang dipertahankan dan disederhanakan jika file mengandung:

- penalti gagal pickup merchant setelah 3 kali;
- `CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS`;
- status `CANCELLED_WITH_FEE`;
- tagihan 50% dari ongkir setelah gagal 3 kali.

Untuk logic yang dipertahankan, arah refactor adalah:

- simpan nilai final ke `orders.service_fee`;
- jangan bergantung pada `service_fee_rules`;
- jangan bergantung pada `order_fee_lines`;
- tetap jaga `orders.total_price = subtotal + delivery_fee + service_fee`.

## Audit Output Format

Setiap entry di `Backend_Bangdeliv/docs/mega_refactor_TA.md` memakai format ringkas:

```md
## path/to/file

- Fungsi: ...
- Logic penting: ...
- Redundansi/minimalisasi: ...
- Service fee notice: ...
- Risiko: ...
```
