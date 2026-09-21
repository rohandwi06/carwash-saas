# armada/ — mengurus banyak cucian

Alat untuk mengurus banyak cucian yang memakai aplikasi kasir yang sama.
Aplikasinya ada di root repo ini; folder ini berisi daftar cucian, setelan
hosting, dan skrip untuk menyiapkan & memperbarui tiap cucian. Rancangannya di
[../docs/ARSITEKTUR.md](../docs/ARSITEKTUR.md).

Semua perintah di bawah dijalankan dari folder `armada\`.

```
armada.json          setelan bersama (domain utama, WHM, prefix database)
tenant/<slug>.json   satu berkas per cucian: status, domain, versi terpasang
templat/env.tenant   .env untuk tiap instalasi
bin/                 skrip PowerShell (jalan di Windows PowerShell 5.1)
rahasia/             .env terisi per cucian — di-gitignore, simpan cadangannya
```

## Pemakaian

Lihat semua cucian dan seberapa ketinggalan versinya:

```
powershell -ExecutionPolicy Bypass -File bin\daftar-tenant.ps1
```

Siapkan cucian baru (tanpa `-Terapkan` tidak menyentuh hosting sama sekali):

```
powershell -ExecutionPolicy Bypass -File bin\tenant-baru.ps1 -Slug budi -NamaBisnis "BUDI CARWASH" -Owner "Pak Budi"
```

Variabel lingkungan yang dibaca skrip (tidak pernah ditulis ke repo):

| Variabel | Untuk |
|---|---|
| `RAPIIN_WHM_TOKEN` | token API WHM reseller (hanya untuk `-Terapkan`) |
| `RAPIIN_GEMINI_KEY` | diisikan ke `GEMINI_API_KEY` di `.env` cucian baru |
