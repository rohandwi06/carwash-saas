# carwash-saas — aplikasi kasir & pembukuan cucian (rapiin.id)

Kasir, pembukuan harian, upah pekerja, dan penjualan makanan/minuman untuk
cucian mobil & motor. Yang dijual bukan sekadar kasir, tetapi **pengawasan
owner atas bisnis tunai yang tidak bisa ia tunggui**: setiap pembatalan
tercatat dan menunggu persetujuan owner, uang di aplikasi mengikuti uang di
laci, dan tidak ada data yang dihapus — hanya ditandai batal.

Satu kode untuk semua cucian. Tiap cucian dipasang di akun cPanel-nya sendiri
dengan database dan `.env` sendiri (rancangannya di
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)). Tidak ada nama cucian di kode:
nama, alamat, dan telepon diatur owner di Pengaturan → Akun → Profil usaha.

| Folder | Isi |
|---|---|
| `app/`, `config/`, `database/`, `resources/`, `routes/`, `public/` | aplikasi Laravel 12 |
| `deploy/cpanel/` | membuat paket zip untuk dipasang/diperbarui di cPanel |
| `ops/` | mengurus banyak cucian: daftar cucian, `.env` tiap cucian, provisioning |
| `android-app/` | aplikasi Android (Flutter) untuk tablet kasir dengan printer Bluetooth |
| `docs/` | rancangan, audit, model bisnis, catatan lapangan |

## Menjalankan di laptop

Butuh PHP 8.2+, Composer, dan MySQL/MariaDB (XAMPP cukup).

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Isi `.env`: `APP_NAME` (nama awal usaha), `DB_*`, `OWNER_USERNAME` /
`OWNER_PASSWORD` (login pertama owner), dan `GEMINI_API_KEY` bila ingin
pencarian kendaraan dibantu AI. Lalu:

```bash
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8090
```

Buka `http://localhost:8090`, masuk sebagai owner, lalu buat akun kasir di
Pengaturan → Akun. Seeder & migrasi mengisi katalog awal: 77 kendaraan, empat jenis
kendaraan, tiga layanan, delapan add-on, tarif upah, dan enam menu F&B umum. Semuanya
diubah owner sendiri dari Pengaturan — angka di `config/carwash.php` hanya
nilai awal pemasangan.

### Data contoh 30 hari

Untuk demo ke calon klien atau mencoba layar dengan data yang terisi:

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSeeder
```

Isinya 30 hari penuh sampai kemarin ditambah hari ini sampai jam sekarang:
±16 cucian/hari (lebih ramai akhir pekan), jajan & barang titipan, buku kas
dua shift dengan setoran yang diterima/ditolak owner, pengajuan batal dari
kasir (satu masih menunggu), koreksi transaksi, dan potongan upah. Semua
dicatat lewat service yang sama dengan layar kasir, jadi Rekap, Pembukuan,
Upah, dan Dashboard saling cocok. Akun kasir: `dina` / `kasir123` dan
`rizal` / `kasir123`. Seeder menolak jalan di produksi atau di database yang
sudah berisi transaksi; jalankan ulang kapan saja untuk menggeser tanggalnya
ke hari ini.

## Tes

```bash
php artisan test
```

## Lapisan kode

```
routes/api.php          daftar endpoint, tanpa logika
app/Http/Requests/      validasi input
app/Http/Controllers/   lapisan HTTP tipis: terima request, panggil service, kembalikan JSON
app/Services/           SEMUA logika bisnis:
    PricingService          harga dari katalog di database (klien tidak pernah mengirim total)
    TransactionService      antrian -> harga -> simpan -> upah, dalam satu transaksi DB
    WageService             upah per kendaraan & layanan, bagi rata, jatah training
    BookkeepingService      rekap harian format buku kas: Total / Tip / TF / Cash Motor /
                            Cash Mobil / Cash Total, dikurangi upah & pengeluaran
    CashBookService         buku kas per hari, saldo awal, setoran & persetujuan owner
    FnbService              penjualan makanan/minuman & stok
    ConsignmentService      titip jual: uang penitip tidak dihitung sebagai laba
    ShiftService            jam operasional; kasir terkunci di luar shift
    VehicleSearchService    pencarian nama kendaraan toleran salah ketik
    GeminiVehicleService    tebakan AI untuk kendaraan yang belum ada di katalog
    BusinessProfileService  nama, keterangan, alamat, telepon usaha
app/Models/             tabel + relasi, tanpa logika bisnis
public/js/kasir.js      tampilan kasir (satu halaman, memanggil /api)
resources/views/kasir/  kerangka HTML tampilan kasir, dipecah per layar
```

Aturan antar lapisan: controller tidak menghitung angka apa pun, service
tidak tahu soal HTTP, dan layar tidak pernah memutuskan besaran uang — semua
angka uang dihitung di server.

## Memasang untuk cucian baru

1. `ops/scripts/New-Tenant.ps1` — akun cPanel, database, dan `.env` cucian itu
   (lihat [ops/README.md](ops/README.md)).
2. `deploy/cpanel/buat-paket.ps1 -FolderApp rapiin-app` — paket aplikasi dan
   database kosong siap-impor.
3. Unggah & pasang: [deploy/cpanel/README.md](deploy/cpanel/README.md).

Perbaikan untuk cucian yang sudah jalan dikirim dengan
`deploy/cpanel/buat-update.ps1`.

## Pencarian kendaraan dengan AI (opsional)

Gemini hanya ditanya bila nama kendaraan tidak ditemukan di katalog. Kasir
mengonfirmasi tebakannya, lalu kendaraan itu masuk katalog dan berikutnya
ditemukan tanpa AI. Kendaraan hasil tebakan ditandai "perlu dicek" di
Pengaturan sampai owner membenarkan jenisnya. Tanpa `GEMINI_API_KEY` fitur
lain tetap berjalan; key hanya hidup di server.
