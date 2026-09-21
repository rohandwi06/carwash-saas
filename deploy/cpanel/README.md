# Memasang di shared hosting cPanel (ArenHost)

Satu cucian = satu akun cPanel. Contoh di bawah memakai cucian `budi`
dengan domain `budi.rapiin.id` dan folder aplikasi `rapiin-app`.
(OTIN, cucian pertama, memakai folder `otin-carwash` di akun hosting
sendiri — ganti nama folder itu bila memperbarui OTIN.)

| Langkah | Alat | Hasil |
|---|---|---|
| 1 | `ops/scripts/New-Tenant.ps1` | akun cPanel + database + `ops/secrets/budi.env` |
| 2 | `deploy/cpanel/buat-paket.ps1` | `app.zip`, `public.zip`, `database.sql` |
| 3–8 | cPanel (bagian di bawah) | aplikasi jalan di `https://budi.rapiin.id` |

---

## 1. Siapkan akun & `.env`

```bash
powershell -ExecutionPolicy Bypass -File ops\scripts\New-Tenant.ps1 -Slug budi -NamaBisnis "BUDI CARWASH" -Owner "Pak Budi" -Terapkan
```

Tanpa `-Terapkan` (atau bila API WHM belum bisa dipakai), buat sendiri di
WHM → Create a New Account, lalu cPanel → **MySQL Databases**: database,
user, dan **ALL PRIVILEGES**. cPanel menambahkan prefix username di depan
nama database & user — salin persis yang tampil ke `ops/secrets/budi.env`.

## 2. Buat paket

```bash
powershell -ExecutionPolicy Bypass -File deploy\cpanel\buat-paket.ps1 -FolderApp rapiin-app
```

Hasilnya di `deploy\cpanel\paket\`:

| Berkas | Tujuan di server |
|---|---|
| `app.zip` | `~/rapiin-app` (**di luar** `public_html`) |
| `public.zip` | `~/public_html` |
| `database.sql` | diimpor lewat phpMyAdmin |

- `vendor/` ikut dibungkus — shared hosting tidak punya composer.
- `.env`, `ops/`, `docs/`, `deploy/`, `tests/`, `android-app/` **tidak pernah**
  ikut: paket ini diunggah ke akun milik satu klien.
- `database.sql` adalah database **kosong** (migrasi + katalog awal), dibuat di
  database sementara lalu dibuang. `-Database lokal` mendump database laptop
  apa adanya — hanya untuk memindahkan data cucian yang sama, jangan pernah
  untuk cucian lain.

## 3. Unggah & ekstrak

cPanel → **File Manager**:

1. Di `home` (sejajar dengan `public_html`) buat folder `rapiin-app`.
2. Masuk ke sana → Upload `app.zip` → klik kanan → **Extract** → hapus zip-nya.
3. Masuk `public_html` → Upload `public.zip` → **Extract** → hapus zip-nya.

```
home/
├── rapiin-app/          <- app, config, routes, vendor, storage, .env
└── public_html/
    ├── index.php        <- menunjuk ke ../rapiin-app (ditulis buat-paket.ps1)
    ├── .htaccess
    └── css/  js/  favicon.ico  robots.txt
```

`.env`, `storage/` (pembukuan & backup), dan `vendor/` berada di luar
`public_html`, jadi tidak ada URL yang bisa mengunduhnya.

Permission (klik kanan → Change Permissions): `rapiin-app/storage` beserta
isinya dan `rapiin-app/bootstrap/cache` → **755**.

## 4. `.env`

File Manager → `rapiin-app` → **+ File** → `.env` → Edit → tempel isi
`ops/secrets/budi.env` → simpan → Change Permissions → **600**.

## 5. Database

cPanel → **phpMyAdmin** → pilih database cucian → **Import** →
`database.sql` → Go. Tidak perlu Terminal atau `php artisan migrate`.

## 6. PHP & cron

- **MultiPHP Manager**: domain ini **PHP 8.2 atau lebih baru** (Laravel 12
  menolak jalan di bawahnya).
- **Cron Jobs** → Add New:

  ```
  * * * * * /usr/local/bin/php /home/USER/rapiin-app/artisan schedule:run >> /dev/null 2>&1
  ```

  Ganti `USER` dengan username cPanel; path PHP bisa berbeda (lihat
  MultiPHP Manager).

## 7. HTTPS

cPanel → **SSL/TLS Status** → centang domain → **Run AutoSSL**. Setelah hijau,
tambahkan di baris paling atas `public_html/.htaccess`, **sebelum** blok
`<IfModule mod_rewrite.c>`:

```apache
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

Wajib: `.env` menyetel `SESSION_SECURE_COOKIE=true`, jadi lewat HTTP polos
login tidak akan pernah nyangkut.

## 8. Uji sebelum diserahkan

1. Buka `https://budi.rapiin.id` — harus layar login bernama cucian itu,
   bukan error 500 atau daftar folder.
2. Masuk owner dengan kredensial dari `.env`, lalu **langsung ganti
   password** di Pengaturan → Akun → Akun owner. Sejak itu password
   tersimpan ter-hash dan `OWNER_PASSWORD` di `.env` tidak dipakai lagi.
3. Pengaturan → Akun → **Profil usaha**: alamat & telepon untuk resi.
4. Buat akun kasir, catat satu transaksi uji, lalu batalkan.
5. Buka dari HP lewat data seluler, bukan WiFi.

**Error 500:** baca baris terbawah `rapiin-app/storage/logs/laravel.log`.
Penyebab tersering: permission `storage` bukan 755, atau PHP di bawah 8.2.

---

## Situs demo

Akun cPanel biasa di reseller, disiapkan dengan `-Demo`:

```bash
powershell -ExecutionPolicy Bypass -File ops\scripts\New-Tenant.ps1 -Slug demo -NamaBisnis "DEMO CARWASH" -Owner "Rohan" -Demo
powershell -ExecutionPolicy Bypass -File deploy\cpanel\buat-paket.ps1 -FolderApp rapiin-app -Database demo
```

Pemasangannya sama dengan Bagian 3–8, dengan tiga beda:

- `.env`-nya berisi `APP_ENV=demo`. Layar login lalu menampilkan info masuk
  owner & kasir, dan akun owner/kasir tidak bisa diubah atau dihapus —
  akunnya dipakai bersama semua pengunjung.
- `database.sql` sudah berisi data contoh 30 hari.
- Cron-nya **bukan** `schedule:run`, melainkan satu baris pada jam sepi:

  ```
  0 3 * * * /usr/local/bin/php /home/USER/rapiin-app/artisan demo:reset >> /dev/null 2>&1
  ```

  `demo:reset` menghapus seluruh database lalu membangun ulang data 30 hari
  yang berakhir hari itu, jadi "Rekap Hari Ini" selalu terisi dan coretan
  pengunjung hilang besok paginya. Jam cron mengikuti zona waktu server
  (cek di cPanel → Cron Jobs). Perintah ini menolak jalan bila `APP_ENV`
  bukan `demo`, jadi tidak bisa terpasang tak sengaja di akun cucian.

## Memperbarui cucian yang sudah jalan

```bash
powershell -ExecutionPolicy Bypass -File deploy\cpanel\buat-update.ps1 -Sejak <commit-yang-terpasang>
```

Commit yang terpasang tercatat di `ops/tenants/<slug>.json` (`versi.commit`).
Hasilnya `update-app.zip` (ekstrak di folder aplikasi) dan `update-public.zip`
(ekstrak di `public_html`). Skrip ini **tidak** mengirim database — situs yang
jalan sudah berisi pembukuan yang tidak ada di laptop.

Bila ada migrasi baru dan hosting tanpa Terminal: jalankan SQL-nya di
phpMyAdmin (contoh: `update-migrasi.sql`, diambil dari
`php artisan migrate --pretend`, setiap perintah `IF NOT EXISTS` supaya aman
diulang). Setelah naik, perbarui `versi.commit` dan `versi.migrasi_terakhir`
di `ops/tenants/<slug>.json`.

## Backup

`php artisan db:backup` memanggil `mysqldump` lewat `proc_open`, dan shared
hosting sering mematikannya. Jangan mengandalkan itu: unduh backup database
dari cPanel → **Backup Wizard** seminggu sekali dan simpan di luar hosting
(Google Drive). Backup yang tersimpan di server yang sama bukan backup.
