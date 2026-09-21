# carwash-saas — aplikasi kasir cucian rapiin.id

Satu kode untuk semua cucian; tiap cucian dipasang di akun cPanel sendiri.
OTIN Carwash adalah cucian pertama. Kode ini disalin dari repo `otin-carwash`
(commit `222de3a`, tanpa riwayat) — pekerjaan baru dilakukan di sini.

- Aplikasi Laravel: root repo ini (penjelasan lapisan di bawah).
- Mengurus banyak cucian: [armada/](armada/README.md).
- Rancangan multi-tenant: [docs/ARSITEKTUR.md](docs/ARSITEKTUR.md).
- Deploy ke cPanel: [deploy/cpanel/README.md](deploy/cpanel/README.md).

---

# OTIN CARWASH — Backend Laravel

Backend API kasir & pembukuan cuci mobil/motor, dengan separation of concerns.

## Arsitektur (Separation of Concerns)

```
routes/api.php            -> daftar endpoint, TIDAK ada logika
app/Http/Requests/        -> VALIDASI input (aturan data masuk)
app/Http/Controllers/     -> lapisan HTTP tipis: terima request, panggil service, kembalikan JSON
app/Services/             -> SEMUA LOGIKA BISNIS:
    PricingService        -> hitung harga (client tidak pernah kirim total!)
    WageService           -> aturan upah per kendaraan, bagi rata, rekap per pekerja
    TransactionService    -> orkestrasi transaksi (antrian -> harga -> simpan -> upah) dalam DB transaction
    BookkeepingService    -> rekap harian format buku + data kalender
    VehicleSearchService  -> fuzzy search nama kendaraan (levenshtein, toleran typo)
app/Models/               -> lapisan data (Eloquent), tanpa logika bisnis
database/migrations/      -> skema tabel
database/seeders/         -> data awal kendaraan & tarif upah
config/carwash.php        -> SATU-SATUNYA tempat angka harga/layanan
```

Aturan main antar lapisan:
- Controller tidak boleh menyentuh angka harga atau menghitung apa pun.
- Service tidak tahu-menahu soal HTTP (tidak ada Request/Response di dalamnya).
- Model murni representasi tabel + relasi.
- Mau ubah harga? Cukup `config/carwash.php`. Mau ubah aturan upah? Cukup `WageService`.

## Instalasi

```bash
composer create-project laravel/laravel otin-carwash
cd otin-carwash

# salin folder app/, database/, routes/, config/carwash.php dari paket ini
# (timpa file yang sama)

# .env: atur DB (MySQL atau cukup sqlite untuk 1 lokasi)
php artisan migrate --seed
php artisan serve --host=0.0.0.0   # akses dari tablet via IP laptop di jaringan WiFi lokal
```

SQLite cukup untuk 1 lokasi (~20 transaksi/hari); tidak perlu MySQL.

## Endpoint utama

| Method | URL | Fungsi |
|---|---|---|
| GET  | /api/vehicles/search?q=penter | fuzzy search kendaraan |
| POST | /api/vehicles/failed-search | catat pencarian gagal |
| GET/POST | /api/transactions | riwayat & buat transaksi |
| GET/POST/PATCH/DELETE | /api/workers... | kelola pekerja + hadir/libur |
| GET/PUT | /api/wage-rates | tarif upah per kategori |
| GET | /api/reports/daily?date= | rekap format buku (Total/Tip/TF/Cash Motor/Cash Mobil/Cash Total/laba) |
| GET | /api/reports/calendar?year=&month= | data kalender pembukuan |
| GET | /api/reports/daily/csv?date= | unduh CSV laporan harian |

## Frontend

`public/kasir.html` — UI kasir lengkap (search, kasir, pekerja, rekap, kalender, resi)
yang memanggil API ini. Karena berada di `public/`, dia disajikan Laravel di origin yang
sama (tanpa masalah CORS). Akses dari tablet:

```
php artisan serve --host=0.0.0.0
# di tablet (WiFi sama): http://IP-LAPTOP:8000/  (langsung terbuka, tanpa /kasir.html)
```

## Integrasi Gemini (opsional)

Dipakai HANYA saat kendaraan tidak ditemukan search lokal. Alur:
kasir tanya AI -> Gemini jawab nama + kategori -> kasir KONFIRMASI -> tersimpan ke tabel vehicles
-> lain kali langsung ketemu di search biasa (tanpa AI lagi).

Setup di `.env`:
```
GEMINI_API_KEY=isi_dari_aistudio.google.com
GEMINI_MODEL=gemini-2.5-flash
```
Tanpa key, tombol AI menampilkan pesan error yang jelas; fitur lain tetap normal.
API key hanya hidup di server — tidak pernah terkirim ke browser.

## Yang BELUM ada (sengaja)

- Autentikasi (tambahkan Sanctum + role owner/kasir sebelum dipakai di jaringan publik)
- Mode offline (kalau WiFi mati, kasir tidak bisa transaksi — pikirkan antrian lokal/sync)
