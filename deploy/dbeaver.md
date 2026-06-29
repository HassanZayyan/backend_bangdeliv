# Koneksi Database Production ke DBeaver

Gunakan SSH tunnel. Jangan buka port `3306` di firewall Tencent.

## Setup di VPS

```bash
cd /opt/bangdeliv
bash scripts/setup-dbeaver-readonly.sh
```

Script akan:

- membuat `docker-compose.override.yml` dengan bind MySQL ke `127.0.0.1:3306`;
- menjalankan ulang service MySQL Docker;
- membuat user `bangdeliv_readonly` dengan akses `SELECT` dan `SHOW VIEW`;
- menampilkan password read-only sekali di terminal.

## Setting DBeaver

Main tab:

- Driver: `MySQL`
- Host: `127.0.0.1`
- Port: `3306`
- Database: sesuai output script
- Username: `bangdeliv_readonly`
- Password: sesuai output script

SSH tab:

- Enable SSH tunnel
- Host/IP: `43.129.55.16`
- Port: `22`
- User: `ubuntu`
- Auth: password atau SSH key yang dipakai untuk login VPS

## Verifikasi

Query aman:

```sql
SELECT COUNT(*) FROM users;
SELECT COUNT(*) FROM orders;
```

User ini read-only. Query tulis seperti `DELETE`, `UPDATE`, atau `INSERT` harus gagal.
