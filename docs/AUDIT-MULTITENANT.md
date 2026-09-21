# Audit Multi-Tenant — apa yang menghalangi cucian kedua

Ditelusuri 2026-09-14 pada keadaan repo `58aa472`.
Pertanyaan yang dijawab: **kalau besok ada cucian kedua mau pakai, apa saja yang harus
diubah — dan mana yang benar-benar menghalangi, bukan sekadar kurang rapi.**

Arah yang diaudit: **database-per-tenant, satu aplikasi Laravel.**
Tiap cucian punya database sendiri; kodenya satu, dipakai bersama.

---

## Ringkasan

| | |
|---|---|
| Sudah siap dipakai ulang tanpa diubah | katalog harga, layanan, kategori, tarif upah, akun kasir, jam shift |
| Pemblokir nyata | 5 |
| Jebakan yang harus dicatat sebelum pindah infrastruktur | 2 |
| Belum ada sama sekali | konsep tenant, provisioning, onboarding |

**Kabar baiknya:** tidak ada satu pun logika bisnis yang harus dibongkar.
Semua temuan di bawah ada di lapisan konfigurasi, identitas, dan penyimpanan berkas —
bukan di `TransactionService`, `WageService`, atau `BookkeepingService`.

---

## 0. Yang sudah siap — jangan diutak-atik

Ini bukan basa-basi; menyadari ini menghemat pekerjaan yang tidak perlu.

**Katalog sudah pindah ke database sejak awal.** `config/carwash.php` menulis sendiri
bahwa sumber kebenarannya ada di tabel `wash_categories` / `wash_services` / `wash_prices` /
`wage_rates`, dan angka di file itu tidak berpengaruh pada aplikasi yang sudah jalan.
Artinya harga cuci, jenis layanan, kategori kendaraan, dan tarif upah **sudah per-cucian
secara desain** dan diatur owner sendiri lewat Pengaturan.

Cucian kedua tidak perlu menunggu kamu untuk memasang tarifnya. Ini bagian yang paling
mahal kalau harus dikerjakan sekarang, dan kebetulan sudah beres.

Ikut siap: akun kasir (tabel `users`), jam shift (tabel `shifts` + `settings`),
menu & stok F&B (tabel `products`).

---

## 1. Pemblokir nyata

### 1.1 Identitas bisnis tertanam di kode — 8 tempat

Nama "OTIN CARWASH" ditulis langsung, bukan diambil dari data.

| Berkas | Baris | Wujudnya |
|---|---|---|
| `resources/views/kasir/index.blade.php` | 6 | judul tab browser |
| `resources/views/kasir/partials/header.blade.php` | 5 | nama di header |
| `resources/views/kasir/partials/drawer.blade.php` | 3 | judul drawer |
| `resources/views/kasir/partials/drawer.blade.php` | 30 | "Terhubung ke server OTIN." |
| `resources/views/kasir/partials/login.blade.php` | 3 | judul layar login |
| `app/Http/Controllers/ReportController.php` | 94 | baris judul di dalam CSV |
| `app/Http/Controllers/ReportController.php` | 148 | nama berkas CSV |
| `app/Console/Commands/BackupDatabase.php` | 66, 105 | nama berkas backup |

**Perbaikan:** satu setting `business_name` di tabel `settings` (tempatnya sudah ada,
tinggal dipakai), dibaca sekali lalu disebar ke blade dan kedua controller.
Owner mengisinya di Pengaturan seperti mengisi yang lain.

**Bobot:** kecil. Setengah hari, dan bisa dikerjakan kapan saja — tidak menunggu keputusan
arsitektur apa pun. Ini kandidat pekerjaan pertama.

### 1.2 Akun owner lahir dari `.env` — tidak bisa dipakai banyak tenant

Sekarang: `OWNER_USERNAME` / `OWNER_PASSWORD` di `.env` adalah kredensial awal.
Begitu owner menggantinya dari Pengaturan, yang dipakai simpanan ter-hash di tabel
`settings` (`OwnerAccountService`). Untuk satu toko, rancangan ini bagus — owner selalu
bisa masuk walau tabelnya kosong.

Untuk banyak tenant, rancangan ini buntu: **satu aplikasi hanya punya satu `.env`.**
Lima cucian tidak bisa punya lima `OWNER_PASSWORD` di berkas yang sama.

**Perbaikan:** akun owner dibuat saat tenant disiapkan — langsung ditulis ter-hash ke tabel
`settings` milik database tenant itu, lewat perintah provisioning. Jalur `.env` tetap
dipertahankan sebagai cadangan untuk pemasangan satu-toko (klien lama, dan siapa pun yang
mau pasang sendiri), tapi tidak lagi jadi jalur utama.

Perhatikan `AuthController::login()` baris 44-46: pesan kesalahan menyuruh orang mengisi
`.env`. Di mode SaaS pesan itu salah arah — owner tenant tidak punya akses ke `.env`.

**Bobot:** sedang. Ini keputusan desain, bukan sekadar penggantian teks.

### 1.3 Backup semua tenant menumpuk di satu folder dengan nama sama

`app/Console/Commands/BackupDatabase.php` menulis ke `storage_path('backups')` dengan nama
`otin-<tanggal>_<jam>.sql`. Tidak ada penanda tenant di folder maupun di nama berkas.

Dengan lima tenant di satu aplikasi, isi folder itu jadi kumpulan berkas yang **tidak bisa
dibedakan milik siapa**. Lebih buruk lagi: `rotate()` menyimpan N berkas terbaru dan
membuang sisanya — tanpa peduli tenant. Lima tenant dengan `--keep=7` berarti tiap tenant
efektif cuma punya satu-dua backup, dan tidak ada yang tahu sampai backup itu dibutuhkan.

**Ini temuan paling berbahaya di dokumen ini**, karena rusaknya diam-diam dan baru ketahuan
persis pada hari kamu paling butuh: saat ada tenant minta datanya dipulihkan.

**Perbaikan:** folder per tenant (`storage/backups/<tenant>/`), nama berkas memakai nama
tenant, dan rotasi dihitung per folder. Wajib dikerjakan **sebelum** tenant kedua jalan,
bukan sesudah.

**Bobot:** kecil (satu berkas), dampak besar.

### 1.4 Satu API key Gemini untuk semua tenant

`config('carwash.gemini.key')` berasal dari `.env` — satu key untuk seluruh aplikasi.

Riwayatnya sudah membuktikan risikonya: **kuota gratis habis dalam sehari pada
30 Agustus 2026, dengan satu tenant saja.** Itu yang melahirkan cache 30 hari
(`9685c85`) dan throttle 20/menit (`71b27a2`).

Dengan lima tenant berbagi satu key, satu cucian yang ramai bisa mematikan pencarian AI
di empat cucian lain, dan tidak ada satu pun dari mereka yang tahu kenapa.

**SUDAH DIPUTUSKAN (2026-09-14): pakai key milik sendiri, nyalakan penagihan.**
Alasannya di [MODEL-BISNIS.md](MODEL-BISNIS.md) bagian 2 — yang habis 30 Agustus itu kuota
gratis (batas permintaan harian), bukan tagihan; prompt-nya cuma daftar kategori; dan
pemakaiannya menurun sendiri karena jawaban yang disimpan masuk katalog. Biayanya di bawah
Rp 15rb per tenant per bulan, yaitu di bawah 10% dari satu langganan — tidak sepadan dengan
hambatan onboarding kalau owner disuruh mengurus Google AI Studio sendiri.

**Yang tetap harus dikerjakan:** throttle `20,1` sekarang berbasis IP, jadi belum membatasi
per tenant. Harus diganti jadi batas per tenant, kalau tidak satu tenant tetap bisa
menghabiskan kuota bersama.

Pertimbangan yang dipakai saat memutuskan:

| Pilihan | Untung | Rugi |
|---|---|---|
| Key milik tiap tenant (owner isi sendiri di Pengaturan) | biayanya bukan tanggunganmu; satu tenant tidak bisa mematikan yang lain | owner harus mengurus Google AI Studio — hambatan nyata saat onboarding |
| Key milikmu, kuota berbayar | onboarding mulus, terasa seperti produk jadi | biayanya naik seiring jumlah tenant; perlu batas per tenant |

**Bobot:** kecil. Tidak lagi menunggu keputusan apa pun — tinggal dikerjakan bersama
Tahap 2, saat id tenant sudah tersedia untuk dipakai sebagai kunci throttle.

### 1.5 Zona waktu terkunci `Asia/Jakarta`

`config/app.php:76`. Komentarnya jujur menyebut "aplikasi ini melayani satu toko".

Selama semua tenant di WIB (Jawa, Sumatera), tidak ada masalah. Begitu ada cucian di Bali,
Makassar, atau Papua (WITA/WIT, beda 1–2 jam), yang rusak bukan tampilan jam saja:

- transaksi tergolong ke **shift yang salah** (`ShiftService::shiftPada()` membandingkan jam)
- transaksi lewat tengah malam waktu setempat masuk ke **tanggal yang salah** di pembukuan
- backup harian 21:30 jalan di jam yang bukan tutup toko

**Perbaikan:** zona waktu jadi setelan per tenant. Tidak mendesak kalau target awalmu
Jawa Timur, tapi harus dicatat sekarang supaya tidak jadi kejutan di penjualan ke-6.

**Bobot:** sedang — menyentuh semua tempat yang memakai `now()`.

---

## 2. Dua jebakan yang harus dicatat

### 2.1 Token sesi tidak membawa identitas tenant

`AuthTokenService` menyimpan token di cache dengan kunci `auth:token:<sha256>`, isinya hanya
`role` dan `name`. **Tidak ada penanda tenant.**

Saat ini aman, dan aman karena kebetulan: `.env.example` menyetel `CACHE_STORE=database`,
jadi cache ikut tinggal di database tenant. Dengan database-per-tenant, token tenant A
otomatis tidak terlihat oleh tenant B.

**Jebakannya:** begitu kamu pindah ke Redis atau cache bersama demi kecepatan — langkah
yang wajar saat tenant bertambah — isolasi itu hilang diam-diam, dan **token dari satu
cucian jadi berlaku di cucian lain.** Tidak ada satu pun tes yang akan menangkap ini.

**Yang harus dilakukan:** masukkan id tenant ke dalam kunci cache **sekarang**, selagi
perubahannya sepele dan tidak mendesak. Jangan menunggu sampai pindah cache.

Hal yang sama berlaku untuk `SESSION_DRIVER=database`.

### 2.2 Cache jawaban AI ikut terpisah per tenant

Kunci `ai-vehicle:<sidik kategori>:<kata>` juga tinggal di cache tenant. Artinya "Avanza"
yang sudah ditanyakan tenant A tetap membakar kuota lagi saat ditanya tenant B.

Ini kebalikan dari 2.1: di sini berbagi justru menguntungkan. Katalog kendaraan itu
pengetahuan umum, bukan data rahasia cucian. Kalau memakai key milikmu (1.4), jawaban AI
sebaiknya disimpan di tempat bersama supaya tiap nama mobil hanya dibayar sekali seumur
hidup, bukan sekali per tenant.

Bukan pemblokir. Tapi langsung menyentuh biaya bulananmu.

---

## 3. Yang belum ada sama sekali

Bukan bug — memang belum pernah dibutuhkan.

1. **Konsep tenant.** Tidak ada tabel tenant, tidak ada cara aplikasi tahu sedang melayani
   cucian yang mana (subdomain? domain sendiri?). Ini fondasi semua hal di atas.
2. **Provisioning.** Menyiapkan tenant baru sekarang berarti: bikin database, jalankan
   migrasi, jalankan seeder, bikin akun owner, isi katalog. Semuanya manual. Harus jadi
   satu perintah.
3. **Onboarding mandiri.** Tidak ada jalur bagi cucian untuk mendaftar sendiri. Untuk
   sekarang **ini tidak apa-apa** — lihat catatan di bawah.
4. **Deployment.** Masih paket ZIP ke cPanel lewat `deploy/cpanel/buat-paket.ps1` dan
   `buat-update.ps1`. Lima tenant = lima cPanel diurus tangan, dan tiap perbaikan bug
   dikirim lima kali.

---

## 4. Urutan yang aku sarankan

Yang mengubah urutan: **temuan 1.3 (backup) dan 2.1 (token) keduanya kecil, tapi keduanya
rusak diam-diam.** Keduanya harus masuk sebelum ada tenant kedua yang datanya nyata —
bukan karena mendesak hari ini, tapi karena sesudahnya tidak akan terasa perlu sampai
terlambat.

**Tahap 1 — rapikan sekarang, tidak menunggu keputusan apa pun (±1-2 hari)**
- `business_name` di settings, cabut 8 titik hardcoded (1.1)
- Backup per tenant: folder, nama berkas, rotasi (1.3)
- Id tenant masuk ke kunci cache token & sesi (2.1)

Ketiganya bisa dikerjakan di repo `otin-carwash` sekarang juga, dan **klien pertama
langsung ikut menikmati** — nama bisnisnya jadi data, backupnya jadi rapi. Tidak ada
pekerjaan terbuang.

**Tahap 2 — fondasi tenant (keputusan desain)**
- Tabel tenant + cara aplikasi mengenali tenant dari permintaan masuk
- Akun owner lewat provisioning, bukan `.env` (1.2)
- Satu perintah `tenant:buat`

**Tahap 3 — menyusul kebutuhan**
- Batas pemakaian AI per tenant (1.4) — keputusan key sudah diambil: pakai key sendiri,
  lihat [MODEL-BISNIS.md](MODEL-BISNIS.md) bagian 2
- Zona waktu per tenant (1.5) — begitu ada calon di luar WIB

**Tahap 4 — saat mendekati klien ke-10** (angkanya di [MODEL-BISNIS.md](MODEL-BISNIS.md) bagian 6)
- Pindah dari cPanel ke VPS, deployment otomatis
- Onboarding mandiri

### Catatan soal onboarding mandiri

Rencana besarmu menyebut pendaftaran mandiri dan model langganan. Untuk lima klien pertama,
**jangan dibangun.** Kamu mendatangi mereka sendiri (langkah 11 rencanamu: door to door),
jadi kamulah proses onboarding-nya — dan ikut mendengar langsung apa yang mereka bingungkan,
persis seperti yang menghasilkan 14 perbaikan dari klien pertama.

Membangun pendaftaran mandiri sebelum ada yang mendaftar adalah cara termahal untuk
menunda penjualan pertama.

---

## Lampiran — yang sudah diperiksa dan ternyata aman

Supaya tidak diperiksa dua kali:

- **Logika bisnis** — `TransactionService`, `WageService`, `PricingService`,
  `BookkeepingService`, `CashBookService`, `FnbService`, `ShiftService`, `StatisticsService`:
  semuanya bekerja di atas data dari database, tidak ada satu pun nilai khusus OTIN
  yang tertanam.
- **Kunci `localStorage` di browser** (`otin_token`, `otin_role`, `otin_name`,
  `otinLastPage`, `otinTabPengaturan`): berawalan "otin" tapi tinggal di browser tiap orang
  dan terpisah per domain. Aman apa adanya; ganti nama hanya kalau mau rapi.
- **Katalog kendaraan** (`VehicleSeeder`): dipakai sebagai data awal tiap tenant.
  Layak dipertimbangkan jadi katalog bersama nanti, tapi menyalin per tenant tidak salah —
  tiap cucian bisa menambah kendaraannya sendiri tanpa mengganggu yang lain.
- **`config/carwash.php`** bagian `categories` / `services` / `default_wages`:
  sudah bukan sumber kebenaran, hanya nilai awal pemasangan. Tidak perlu disentuh.
