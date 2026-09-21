# Deploy OTIN CARWASH ke shared hosting cPanel (ArenHost)

Domain: **otincarwash-pos.my.id** — aplikasi kasir dipasang di domain utama.

Panduan ini menggantikan mode LAN ([`DEPLOY.md`](../../DEPLOY.md)) dan mode
tunnel ([`../windows/README.md`](../windows/README.md)). Setelah selesai,
laptop toko **tidak perlu hidup lagi** — kasir buka `https://otincarwash-pos.my.id`
dari HP mana pun.

---

## Apa yang berubah dibanding sekarang

| | Sebelum (laptop toko) | Sesudah (hosting) |
|---|---|---|
| Server | Laptop toko + XAMPP | Server ArenHost |
| Alamat | IP lokal / URL tunnel yang berubah tiap nyala | `https://otincarwash-pos.my.id` tetap |
| Laptop mati | Kasir mati | Kasir tetap jalan |
| Backup | `storage/backups` di laptop | lihat **Bagian 7** — caranya berbeda |

---

## Bagian 0 — WAJIB dulu: kunci akun owner

Aplikasi ini lahir untuk LAN toko. Begitu ada di internet, password lemah
adalah lubang terbesar. Cek dulu di laptop:

```bash
powershell -ExecutionPolicy Bypass -File deploy\windows\scripts\preflight-keamanan.ps1
```

Yang **wajib** beres sebelum upload (sisanya soal MySQL lokal, tidak relevan
di hosting):

- `OWNER_PASSWORD` minimal 12 karakter acak — yang sekarang cuma 4 digit.
- `GEMINI_API_KEY` harus key baru (yang lama pernah bocor).
- `APP_KEY` sudah diganti (statusnya sudah OK).

---

## Bagian 1 — Arahkan domain ke hosting

Di panel domain `.my.id` (tempat domain dibeli), ganti **nameserver** ke
nameserver ArenHost — ada di email aktivasi hosting, bentuknya seperti
`ns1.arenhost.id` / `ns2.arenhost.id`.

Propagasi 15 menit sampai beberapa jam. Cek dari laptop:

```bash
nslookup otincarwash-pos.my.id
```

Kalau IP yang keluar sudah sama dengan IP di email hosting, lanjut.

> Kalau domain dipasang sebagai **Addon Domain**, bukan domain utama akun,
> folder tujuannya bukan `~/public_html` melainkan `~/otincarwash-pos.my.id`.
> Sesuaikan di semua langkah di bawah; baris `$app_base` di
> `index-public_html.php` tetap menunjuk ke `~/otin-carwash`.

---

## Bagian 2 — Buat database di cPanel

cPanel → **MySQL Databases**:

1. Create Database: `otin_carwash` → jadi `prefix_otin_carwash`.
2. Add New User: `otin` → jadi `prefix_otin`. Pakai password acak panjang,
   **catat**.
3. Add User To Database → centang **ALL PRIVILEGES**.

Salin ketiga nilai persis seperti yang tampil (lengkap dengan prefix-nya) —
nanti dipakai di `.env`.

---

## Bagian 3 — Siapkan berkas di laptop

```bash
powershell -ExecutionPolicy Bypass -File deploy\cpanel\buat-paket.ps1
```

Hasilnya di `deploy\cpanel\paket\`:

| Berkas | Tujuan di server |
|---|---|
| `otin-app.zip` | `~/otin-carwash` (**di luar** `public_html`) |
| `otin-public.zip` | `~/public_html` |
| `otin-database.sql` | diimpor lewat phpMyAdmin |

`vendor/` sengaja ikut dibungkus — shared hosting tidak punya composer.
`.env` sengaja **tidak** ikut; dibuat langsung di server (Bagian 5).

---

## Bagian 4 — Unggah & extract

cPanel → **File Manager**:

1. Di `home` (sejajar dengan `public_html`), **+ Folder** → `otin-carwash`.
2. Masuk ke sana → Upload `otin-app.zip` → klik kanan → **Extract** → hapus zip-nya.
3. Masuk `public_html` → Upload `otin-public.zip` → **Extract** → hapus zip-nya.

Susunan akhir yang benar:

```
home/
├── otin-carwash/        <- app, config, routes, vendor, storage, .env
│   ├── vendor/
│   ├── storage/
│   └── .env             (dibuat di Bagian 5)
└── public_html/
    ├── index.php        <- versi cPanel, menunjuk ke ../otin-carwash
    ├── .htaccess
    └── css/  js/  favicon.ico  robots.txt
```

**Inti keamanannya:** `.env`, `storage/` (pembukuan & backup), dan `vendor/`
berada di luar `public_html`, jadi tidak ada URL yang bisa mengunduhnya.

Lalu set permission (klik kanan → Change Permissions):

- folder `otin-carwash/storage` beserta seluruh isinya → **755**
- `otin-carwash/bootstrap/cache` → **755**

---

## Bagian 5 — Buat `.env` di server

File Manager → masuk `otin-carwash` → **+ File** → nama `.env` → klik kanan →
**Edit**. Tempel isi [`env-hosting.txt`](env-hosting.txt), lalu ganti semua
yang bertanda `<...>` dengan nilai dari Bagian 2 dan password owner baru.

`APP_KEY` diisi di Bagian 6. Kalau tidak ada terminal SSH sama sekali, salin
`APP_KEY` yang sudah ada di `.env` laptop — kuncinya valid, dan aman dipakai
karena nilainya bukan lagi yang pernah bocor di GitHub.

Setelah tersimpan: klik kanan `.env` → Change Permissions → **600**.

---

## Bagian 6 — Impor database & migrasi

**Impor data yang sudah ada:** cPanel → **phpMyAdmin** → pilih database
`prefix_otin_carwash` → tab **Import** → pilih `otin-database.sql` → Go.

Kalau file `.sql` lebih besar dari batas upload phpMyAdmin, kompres jadi
`.zip` dulu — phpMyAdmin bisa membaca zip langsung.

**Kalau mau mulai dari nol** (tanpa data lama), lewati impor dan jalankan
migrasi. Untuk itu butuh terminal:

- cPanel → **Terminal** (kalau ArenHost mengaktifkannya):

  ```bash
  cd ~/otin-carwash
  php artisan key:generate --force
  php artisan migrate --force
  php artisan config:cache && php artisan route:cache && php artisan view:cache
  ```

- **Tanpa Terminal:** impor `otin-database.sql` saja sudah cukup untuk
  penyalaan pertama — struktur tabel dan data ikut di dalamnya, jadi migrasi
  tidak diperlukan. Yang perlu diingat: setiap update kode yang menambah
  migrasi harus di-dump ulang dari laptop lalu diimpor lagi, atau minta
  ArenHost mengaktifkan SSH.

> Jangan jalankan `config:cache` sebelum `.env` final — perubahan `.env`
> setelah itu tidak terbaca sampai `php artisan config:clear`.

---

## Bagian 7 — Backup

`php artisan db:backup` memanggil `mysqldump` lewat `proc_open`, dan shared
hosting **sering mematikan `proc_open`**. Jadi jangan mengandalkan itu di sini.
Dua lapis penggantinya:

1. **Backup manual dari cPanel** — cPanel → **Backup Wizard** → Download MySQL
   Database, seminggu sekali, simpan ke Google Drive. Ini yang paling penting:
   backup yang tersimpan di server yang sama bukan backup.

2. **Cron scheduler Laravel** (untuk rotasi backup & tugas terjadwal lain,
   kalau `proc_open` ternyata hidup). cPanel → **Cron Jobs** → Add New:

   ```
   * * * * * /usr/local/bin/php /home/PREFIX/otin-carwash/artisan schedule:run >> /dev/null 2>&1
   ```

   Ganti `PREFIX` dengan nama user cPanel. Path PHP bisa berbeda — lihat
   pilihan di **MultiPHP Manager** atau tanya support ArenHost. Pastikan
   versi PHP domain ini **8.2 atau lebih baru**; Laravel 12 menolak jalan
   di bawah itu.

---

## Bagian 8 — Nyalakan HTTPS

cPanel → **SSL/TLS Status** → centang domain → **Run AutoSSL**. Tunggu sampai
hijau (butuh domain sudah mengarah ke hosting, Bagian 1).

Setelah HTTPS hidup, tambahkan paksa-HTTPS di baris paling atas
`public_html/.htaccess`, **sebelum** blok `<IfModule mod_rewrite.c>` yang
sudah ada:

```apache
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

Ini wajib: `.env` sudah diset `SESSION_SECURE_COOKIE=true`, jadi lewat HTTP
polos login tidak akan pernah nyangkut.

---

## Bagian 9 — Uji sebelum dipakai kasir

1. Buka `https://otincarwash-pos.my.id` — harus muncul layar login, bukan error
   500 dan bukan daftar folder.
2. Login owner dengan kredensial dari `.env`.
3. **Langsung ganti password owner dari dalam aplikasi**: menu ☰ → Pengaturan
   → Akun owner. Sejak itu password tersimpan ter-hash di database, dan
   `OWNER_PASSWORD` di `.env` tidak dipakai lagi.
4. Buat ulang akun kasir bila perlu (Pengaturan → Akun kasir).
5. Catat satu transaksi uji, lalu void — pastikan tersimpan.
6. Buka dari HP kasir lewat data seluler (bukan WiFi toko) — memastikan
   benar-benar lewat internet.

**Kalau error 500:** File Manager → `otin-carwash/storage/logs/laravel.log`,
baca baris paling bawah. Penyebab tersering: permission `storage` bukan 755,
atau versi PHP masih di bawah 8.2.

---

## Bagian 10 — Setelah pindah

- Laptop toko **jangan** lagi menjalankan `start-tunnel.bat`. Dua salinan
  aplikasi dengan dua database terpisah = pembukuan pecah dua.
- Matikan tugas terjadwal tunnel yang sudah terpasang di laptop
  (Task Scheduler → tugas bernama `OtinCarwash Tunnel`).
- XAMPP lokal tetap boleh dipakai untuk **mengembangkan**, tapi datanya sejak
  sekarang cuma data uji — yang asli ada di hosting.
