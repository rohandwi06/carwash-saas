# Panduan Deploy OTIN CARWASH (jaringan lokal / LAN)

Aplikasi ini dirancang jalan di **satu komputer di dalam toko**, diakses HP/tablet kasir lewat wifi toko. **Jangan diexpose ke internet publik** tanpa HTTPS + review keamanan lebih lanjut.

## 1. Wajib sebelum jalan pertama kali

Edit file `.env`:

```
OWNER_USERNAME=...    # username akun owner
OWNER_PASSWORD=...    # password owner — JANGAN yang gampang ditebak
GEMINI_API_KEY=...    # buat KEY BARU di Google AI Studio.
                      # Key lama yang pernah ada di file ini sudah bocor — hapus/revoke di console Google.
```

Login owner **ditolak total** selama kredensial di atas kosong — ini disengaja.

Nilai `.env` di atas hanya **kredensial awal**. Setelah aplikasi jalan, ubah
akun owner dari dalam aplikasi: menu &#9776; → Pengaturan → **Akun owner**
(wajib isi password lama). Sejak diubah, password owner tersimpan **ter-hash**
di tabel `settings` dan nilai `OWNER_PASSWORD` di `.env` tidak dipakai lagi
untuk login — lebih aman daripada teks polos di file.

Akun **kasir** dibuat owner dari dalam aplikasi: menu &#9776; → Pengaturan → Akun kasir
(tersimpan di tabel `users`, password di-hash).

## 2. Menjalankan server

**Cara gampang (Windows):** dobel-klik `start-otin.bat` di folder ini.
Skrip itu menyalakan MySQL XAMPP (kalau belum jalan), menampilkan alamat
untuk HP, lalu menjalankan server. **Biarkan jendelanya tetap terbuka**
selama kasir dipakai. Buat shortcut-nya di Desktop biar tinggal klik.

Cara manual (setara):

```bash
# 1. Nyalakan MySQL dari XAMPP Control Panel (atau C:\xampp\mysql_start.bat)
php artisan migrate --force     # sekali saja / tiap update kode
php artisan serve --host=0.0.0.0 --port=8000
php artisan schedule:work        # terminal kedua (opsional): backup harian 21:30
```

Akses dari HP kasir: `http://IP-KOMPUTER:8000/` (cek IP dengan `ipconfig`,
lihat baris "IPv4 Address" — HP harus di WiFi yang sama).

Kalau HP tidak bisa konek padahal WiFi sama: izinkan PHP/port 8000 di
Windows Defender Firewall (biasanya cukup klik "Allow" saat pertama muncul).

## 3. Pembagian akses

| Aksi | Kasir | Owner |
|---|---|---|
| Transaksi, F&B, laporan, unduh CSV | ✅ | ✅ |
| Batalkan (void) transaksi — wajib isi alasan, jejak tersimpan | ✅ | ✅ |
| Hapus pekerja / hapus menu / ubah tarif upah / reset antrean | ❌ | ✅ |
| Layar **Pekerja & Upah** (menunya disembunyikan untuk kasir) | ❌ | ✅ |
| Layar **Pengaturan** (menunya disembunyikan untuk kasir) | ❌ | ✅ |
| Catat & lihat **deposit pekerja ke kas** | ❌ | ✅ |
| Ubah akun owner sendiri | ❌ | ✅ |
| Lihat/ubah **biodata pekerja** (NIK, tempat/tgl lahir, HP, alamat) | ❌ | ✅ |
| Tutup **buku kas** & ajukan setoran (buku miliknya sendiri) | ✅ | ✅ |
| Terima/tolak setoran buku kas yang diajukan kasir | ❌ | ✅ |

Kasir tetap menerima daftar pekerja — ia perlu memilih siapa yang mengerjakan
cucian — tapi hanya id, nama, dan status kehadiran. Biodata tidak pernah ikut
terkirim ke tablet kasir.

**Umur tidak disimpan**, hanya tanggal lahir; umur dihitung saat ditampilkan
supaya angkanya tidak pernah basi.

## 3c. Timezone (WIB)

Aplikasi memakai `Asia/Jakarta`, bukan `UTC` bawaan Laravel — lihat
`config/app.php`. Kalau menjalankan `php artisan migrate` di server yang
datanya masih ditulis saat timezone masih UTC: migrasi
`2026_07_27_000013_shift_timestamps_to_wib` menggeser jam yang SUDAH
tersimpan (+7 jam) supaya cocok dengan waktu kejadian sebenarnya. **WAJIB
`php artisan db:backup` dulu** — migrasi ini mengubah `created_at` dan
kolom waktu lain di ratusan baris transaksi yang sudah ada. Kolom **tanggal**
(`date`) sengaja TIDAK ikut digeser — toko buka 07:00–21:00 WIB masih jatuh
di tanggal kalender yang sama di UTC, jadi tanggalnya sudah benar apa adanya.

## 3b. Buku kas (pengganti shift sebagai satuan laporan)

Rekap Hari Ini & Pembukuan tidak lagi dikelompokkan per **shift** (jam), tapi
per **buku kas** — satuan berdasarkan PERISTIWA, bukan jam: dibuka, diisi
transaksi, ditutup & diajukan setoran, disetujui/ditolak owner.

Shift sendiri **tidak dihapus** — tetap dipakai di Pengaturan → Shift untuk
mengunci aplikasi kasir di luar jam operasional. Yang berubah hanya: shift
tidak lagi menandai transaksi untuk keperluan laporan.

**Alurnya:**
1. Buku pertama hari itu (Buku 1) terbuka otomatis saat transaksi pertama
   dicatat.
2. Kasir menekan **"Simpan & Ajukan Setoran"** di Rekap Hari Ini kapan pun
   (biasanya di akhir shift kerjanya). Buku itu tertutup, dan **buku
   berikutnya langsung terbuka** (mis. Buku 2) supaya transaksi selanjutnya
   tetap tercatat tanpa jeda.
3. Owner melihat kartu **"Setoran menunggu persetujuan"** di Rekap Hari
   Ini (siapa pun tab yang sedang dibuka) dan menekan **Terima** setelah uang
   cash benar-benar diterima, atau **Tolak** dengan alasan bila ada selisih.
4. Setoran yang **ditolak** TIDAK dibuka lagi otomatis — buku berikutnya
   sudah telanjur berjalan; menolak hanya menandai perlu tindak lanjut manual
   antara owner & kasir (mis. hitung ulang uang), sama seperti penolakan
   pengajuan pembatalan transaksi.

**Angka yang disetor (kolom "amount") = omzet CASH (cuci + F&B) dikurangi
pengeluaran yang dicatat di buku itu.** TF tidak dihitung — uangnya sudah
otomatis masuk rekening, tidak ada wujud fisik untuk diserahkan. Angka ini
**boleh negatif** (mis. buku baru yang langsung kena pengeluaran cash sebelum
ada pemasukan) — artinya owner yang perlu mengganti ke kasir, bukan
sebaliknya.

Buku dibubuhkan **saat transaksi/F&B/pengeluaran dicatat**, bukan dihitung
ulang saat laporan dibaca — rekap hari yang sudah lewat tidak pernah bergeser.
Pengeluaran untuk **tanggal lampau** (lewat kalender) sengaja TIDAK dibubuhi
buku — kalau ikut dibubuhi, itu akan diam-diam membuka kembali buku hari yang
sudah lama ditutup & disetor.

### Migrasi dari data lama (shift → buku)

Kalau menjalankan `php artisan migrate` di server yang datanya masih memakai
sistem shift lama: migrasi `2026_08_11_*` memindahkan tiap kelompok shift per
tanggal jadi satu buku `deposited` (hari yang sudah lewat) atau **satu buku
`open` gabungan** untuk hari migrasi itu dijalankan (hari yang sedang
berjalan belum pernah benar-benar "ditutup" lewat alur baru ini, jadi tidak
diberi status seolah sudah disetor). **WAJIB `php artisan db:backup` dulu**
sebelum migrasi ini — ia mengubah/memindahkan data transaksi, F&B, dan
pengeluaran yang sudah ada.

## 3a. Upah karyawan training

Pekerja bisa ditandai **training** di menu &#9776; → Pengaturan → Karyawan.
Upahnya tidak ikut bagi rata. Angka yang diatur owner adalah **jatah bersama
seluruh anak training** pada satu cucian — **bukan per orang** — dan ditetapkan
**per jenis kendaraan + layanan**. Sisa jatah baru dibagi rata ke senior.

Contoh, Mobil / Cuci Reguler dengan jatah pekerja Rp 15.000 dan jatah training
Rp 5.000:

| Yang mengerjakan | Senior | Training |
|---|---|---|
| 1 senior + 1 training | 10.000 | 5.000 |
| 1 senior + 2 training | 10.000 | 2.500 masing-masing |
| 2 senior + 2 training | 5.000 masing-masing | 2.500 masing-masing |

- Jatah **bersama**, supaya bagian senior tidak tergerus saat anak training
  bertambah. Dengan nominal per orang, 1 senior + 2 training justru membuat
  seniornya dapat paling sedikit — kebalikan dari maksud fitur ini.
- Kalau cucian dikerjakan training saja, mereka tetap menerima jatah kecilnya;
  selisihnya tidak dibagikan ke siapa pun (jadi milik owner).
- **Batas otomatis:** jatah training tidak pernah melebihi porsi yang akan
  mereka terima seandainya dibagi rata. Tanpa batas ini, cucian bertarif kecil
  (motor Rp 5.000) dengan jatah training Rp 5.000 membuat seniornya pulang
  Rp 0 sementara yang training dibayar penuh.

Mengubah status training **tidak** mengubah upah yang sudah tercatat — nilainya
sudah tersimpan di transaksi saat cucian dibuat.

Sesi login berlaku 14 jam; setelah itu diminta login lagi.

## 4. Backup

- Otomatis tiap hari 21:30 ke `storage/backups/` (disimpan 14 terakhir), selama `schedule:work` / cron jalan.
- Manual kapan saja: `php artisan db:backup`.
- **Backup lokal ≠ aman.** Minimal seminggu sekali salin folder `storage/backups/` ke flashdisk atau Google Drive. Kalau komputer kasir rusak/dicuri, pembukuan ikut hilang tanpa salinan luar.

## 5. Pemulihan dari backup

Matikan server, lalu:

```bash
cp storage/backups/otin-TANGGAL.sqlite database/database.sqlite
```

## 6. Struktur tampilan

- Layout utama: `resources/views/kasir/index.blade.php`
- Tiap layar: `resources/views/kasir/partials/*.blade.php` (edit satu layar = satu file kecil)
- Gaya & logika: `public/css/kasir.css`, `public/js/kasir.js`

## 7. Yang belum dicakup (sadari risikonya)

- Koneksi masih HTTP polos — cukup untuk LAN toko, TIDAK cukup untuk internet.
- Tidak ada log siapa-melakukan-apa per kasir (hanya role kasir/owner, bukan per orang).
- File `.env` berisi password owner & API key — jangan pernah ikut di-zip/di-share/di-commit.
