# Arsitektur Awal — rapiin.id (carwash multi-tenant)

Disusun 2026-09-21. Titik berangkat: `otin-carwash` di commit `222de3a`,
disalin ke repo ini tanpa riwayat git (riwayat 70 commit sebelumnya tetap di
`otin-carwash`, yang sejak itu dibekukan). Dokumen pendamping:
[AUDIT-MULTITENANT.md](AUDIT-MULTITENANT.md), [MODEL-BISNIS.md](MODEL-BISNIS.md).

## Keputusan inti

**Multi-tenant di tingkat hosting, bukan di tingkat kode.**
Satu cucian = satu akun cPanel (Reseller ArenHost) = satu instalasi aplikasi,
satu database, satu `.env`. Aplikasinya tetap aplikasi satu-toko yang sudah
terbukti jalan di OTIN; tidak ada tabel `tenants`, tidak ada `tenant_id` di
tiap query, tidak ada middleware pengenal tenant.

Konsekuensinya, repo ini berisi dua bagian yang tidak saling mencampuri:

```
carwash-saas/  (satu repo)
│
├── app/ config/ database/ resources/ routes/ ...   APLIKASI (Laravel 12)
│       satu kode untuk semua cucian; OTIN = cucian pertama
│   deploy/cpanel/                                   paket zip untuk cPanel
│   docs/                                            rancangan & bahan jualan
│
└── armada/                                          ARMADA
        armada.json      setelan bersama: domain utama, WHM, prefix DB
        tenant/*.json    daftar cucian: siapa, di mana, versi berapa
        templat/         .env per cucian
        bin/             tenant-baru, daftar-tenant, (rilis, update)
        rahasia/         .env terisi per cucian — TIDAK masuk git
                 │
                 │ WHM API (buat akun, database)  +  unggah paket
                 ▼
┌──────────── Reseller ArenHost (WHM) ────────────┐
│  cPanel otin      → otincarwash-pos.my.id  *)   │
│  cPanel budi      → budi.rapiin.id              │
│  cPanel sinar     → sinar.rapiin.id             │
│   tiap akun:  ~/rapiin-app  ~/public_html       │
│               MySQL <prefix>_app   cron 1 baris │
└─────────────────────────────────────────────────┘
 *) OTIN masih di akun hosting sendiri, di luar reseller.
```

### Aturan satu repo

- **Aplikasi di root, armada di `armada/`.** Skrip `deploy/cpanel/` dan semua
  path Laravel tetap seperti di `otin-carwash`, tidak ada yang perlu disesuaikan.
- **`armada/`, `docs/`, `deploy/`, `android-app/` tidak pernah ikut ke hosting.**
  Paket diunggah ke akun milik SATU klien; `armada/rahasia/` berisi password
  semua klien. Pengecualiannya ada di `buat-paket.ps1` dan `buat-update.ps1` —
  jangan dihapus.
- **OTIN bukan versi khusus.** Perubahan Tahap 1 (nama bisnis, zona waktu)
  dikerjakan di sini dan langsung dinikmati OTIN lewat paket update biasa.
- **`otin-carwash` dibekukan.** Semua pekerjaan baru di sini; dua kode yang
  hidup bersamaan berarti tiap perbaikan dikerjakan dua kali.

### Kenapa bukan satu aplikasi dengan database-per-tenant

Sudah dipertimbangkan di audit dan ditolak di MODEL-BISNIS bagian 1 & 8:

| | Satu cPanel per cucian (dipilih) | Satu app, DB per tenant |
|---|---|---|
| Kode aplikasi | tidak berubah | perlu resolusi tenant, koneksi DB dinamis |
| Isolasi | penuh (file, DB, `.env`, cache, sesi) | bergantung disiplin kode |
| Token/backup (temuan 1.2, 1.3, 2.1) | hilang dengan sendirinya | harus diperbaiki satu per satu |
| Deploy perbaikan | N kali → dijawab skrip | sekali |
| Kalimat jualan | "database Bapak terpisah sendiri" | — |

Harga yang dibayar hanya satu: **deploy N kali**. Seluruh repo ini ada untuk
membuat harga itu murah.

---

## Kontrak aplikasi ↔ armada

Satu-satunya tempat kedua repo bersentuhan. Semua yang berbeda antar cucian
harus lewat salah satu dari tiga jalur ini — tidak boleh ada `if ($cucian == ...)`
di kode aplikasi.

| Jalur | Isinya | Siapa yang mengubah |
|---|---|---|
| `.env` | `APP_URL`, `APP_KEY`, `DB_*`, `OWNER_USERNAME/PASSWORD` awal, `APP_TIMEZONE`, `GEMINI_API_KEY` | armada, sekali saat provisioning |
| tabel `settings` | nama bisnis, jam shift, akun owner (ter-hash) | owner sendiri, dari Pengaturan |
| tabel katalog | harga, layanan, kategori, upah, produk F&B | owner sendiri |

**Yang harus dikerjakan di aplikasi agar kontrak ini berlaku**
(Tahap 1 audit, sisa setelah keputusan satu-cPanel-per-cucian):

1. `business_name` dibaca dari `settings`, 8 titik hardcoded dicabut (audit 1.1).
   Nilai awalnya diambil dari `APP_NAME` supaya instalasi baru langsung bernama benar.
2. `config/app.php` membaca `env('APP_TIMEZONE', 'Asia/Jakarta')` (audit 1.5).
   Hanya boleh diisi saat provisioning — mengganti zona waktu cucian yang sudah
   punya data menggeser semua jam lama (lihat komentar migrasi `2026_07_27_000013`).
3. `APP_NAME` di `env-hosting.txt` jadi satu-satunya tempat nama cucian untuk
   hal di luar database (nama berkas backup, judul CSV).

---

## Daftar tenant (`armada/tenant/<slug>.json`)

Satu berkas per cucian, di-commit. Ini **sumber kebenaran armada** — dan
menjawab masalah yang sekarang ditebak lewat md5 isi zip: *commit mana yang
sedang jalan di produksi.*

```json
{
  "slug": "budi",
  "nama_bisnis": "BUDI CARWASH",
  "status": "percobaan",
  "paket_harga": "kecil",
  "domain": "budi.rapiin.id",
  "zona_waktu": "Asia/Jakarta",
  "hosting": {
    "jenis": "reseller",
    "cpanel_user": "budi",
    "folder_app": "rapiin-app",
    "docroot": "public_html",
    "prefix_db": "budi"
  },
  "owner": { "nama": "Pak Budi" },
  "mulai": "2026-10-01",
  "versi": { "commit": "222de3a", "pasti": true, "migrasi_terakhir": "2026_09_18_000003_add_consignment_to_products_sales_expenses" },
  "catatan": []
}
```

- **`slug`** = nama akun cPanel = subdomain. Aturan cPanel: huruf kecil &
  angka, diawali huruf, maks 16, tanpa tanda hubung, tidak diawali `test`.
- **`status`**: `disiapkan` → `percobaan` (gratis sebulan) → `aktif` → `berhenti`.
  Skrip update hanya menyentuh `percobaan` dan `aktif`.
- **`paket_harga`**: `kecil` / `sedang` / `besar` (MODEL-BISNIS bagian 4). Hanya
  catatan tagihan; fiturnya sama.
- **`versi.commit`** ditulis oleh skrip update setelah paket naik. `pasti: false`
  berarti tebakan — skrip update wajib memakai paket penuh, bukan inkremental.
- **`versi.migrasi_terakhir`**: nama migrasi terakhir yang sudah dijalankan di
  database itu. Dengan ini, migrasi yang tertunda per cucian bisa dihitung,
  bukan diingat.

Yang **tidak** boleh ada di berkas ini: password, API key, nomor HP owner.
Password ada di `armada/rahasia/<slug>.env` (di-gitignore); token WHM di variabel
lingkungan `RAPIIN_WHM_TOKEN`.

---

## Alur kerja

### 1. Cucian baru — `armada/bin/tenant-baru.ps1`

```
tenant-baru.ps1 -Slug budi -NamaBisnis "BUDI CARWASH" -Owner "Pak Budi"
```

Tanpa `-Terapkan` skrip hanya menyiapkan berkas lokal dan mencetak apa yang
akan dilakukan. Dengan `-Terapkan`:

1. Validasi slug & pastikan belum dipakai.
2. Buat password DB, password awal owner, dan `APP_KEY` acak.
3. Tulis `armada/rahasia/budi.env` dari `armada/templat/env.tenant`.
4. Tulis `tenant/budi.json` berstatus `disiapkan`.
5. WHM `createacct` → akun cPanel `budi` di `budi.rapiin.id`.
6. WHM `uapi_cpanel` → `Mysql::create_database`, `create_user`,
   `set_privileges_on_database`.
7. Cetak sisa langkah manual (lihat bawah).

**Langkah yang masih manual** sampai WHM API ArenHost terverifikasi:
unggah & ekstrak paket rilis, impor database cetakan, AutoSSL, satu baris cron.

### 2. Rilis — `armada/bin/rilis.ps1` *(berikutnya)*

Membangun **satu paket untuk semua cucian** dari aplikasi di repo ini pada satu commit:

- `rilis/<commit>/app.zip` — seluruh aplikasi termasuk `vendor/`, tanpa `.env`,
  `docs/`, `deploy/`, `tests/`, `android-app/`, `armada/`. Aturan pengecualian disalin dari
  `buat-update.ps1` (termasuk alasan kenapa zip ditulis entri per entri).
- `rilis/<commit>/public.zip` — isi `public/` dengan `index.php` versi cPanel
  (`deploy/cpanel/index-public_html.php`), baris `$app_base` diganti ke
  `folder_app` dari `armada.json` — OTIN memakai `otin-carwash`, cucian baru `rapiin-app`.
- `rilis/<commit>/cetakan.sql` — **database cetakan**: `migrate:fresh --seed`
  dijalankan di MySQL lokal lalu di-dump. Ini jawaban untuk hosting tanpa
  Terminal: cucian baru cukup impor satu berkas, bukan menjalankan artisan.
- `rilis/<commit>/migrasi/*.sql` — satu berkas SQL idempotent per migrasi
  (`IF NOT EXISTS`, cara yang sudah dipakai di `update-migrasi.sql`).

Paket penuh, bukan inkremental. Selisih ukurannya kecil dibanding hilangnya
satu kelas bug: "berkas tertinggal karena baseline salah tebak".

### 3. Update — `armada/bin/update-semua.ps1` *(berikutnya)*

Untuk setiap tenant `percobaan`/`aktif`:
1. Bandingkan `versi.migrasi_terakhir` dengan isi rilis → daftar SQL yang harus
   diimpor untuk cucian **itu** (bisa beda antar cucian).
2. Unggah & ekstrak `app.zip` + `public.zip`.
3. Tulis `versi.commit` dan `versi.migrasi_terakhir` baru ke `tenant/<slug>.json`,
   lalu commit — riwayat git repo ini jadi riwayat deploy.

Satu cucian gagal tidak menghentikan yang lain; hasilnya dirangkum di akhir.

### 4. Backup di luar hosting — `armada/bin/tarik-backup.ps1` *(berikutnya)*

Backup mingguan ArenHost tinggal di penyedia yang sama. Skrip ini mengunduh
dump tiap cucian ke `backup/<slug>/<tanggal>.sql.gz` di laptop (lalu disinkron
ke Google Drive), rotasi dihitung **per folder cucian**. Wajib ada sebelum
cucian kedua menyimpan data nyata.

---

## Yang harus diverifikasi sebelum otomasi dipercaya

Dari MODEL-BISNIS "Yang masih perlu diverifikasi", ditambah yang muncul dari rancangan ini:

| # | Pertanyaan | Kalau jawabannya "tidak" |
|---|---|---|
| 1 | WHM reseller ArenHost membuka API token (`createacct`, `uapi_cpanel`)? | provisioning lewat WHM web, skrip hanya menyiapkan `.env` & checklist |
| 2 | Akun cPanel boleh ber-domain utama subdomain `x.rapiin.id`? | pakai domain sendiri per cucian, atau satu akun + addon domain |
| 3 | Prefix database di akun reseller 8 karakter (seperti `otincarw_` di OTIN)? | ubah `prefix_db_panjang` di `armada.json` |
| 4 | Cron cPanel bisa menjalankan `php artisan migrate --force` sekali jalan? | tetap SQL manual lewat phpMyAdmin — tapi sudah dihitung per cucian |
| 5 | `Fileman` UAPI bisa unggah & ekstrak zip? | unggah lewat File Manager, skrip mencatat versinya |

Nomor 1 menentukan hampir semua otomasi. Cek itu dulu sebelum menulis `rilis.ps1`.

---

## Urutan pengerjaan

1. **Sekarang, di aplikasi:** `business_name` di settings + `APP_TIMEZONE`.
   OTIN ikut menikmati; tidak ada kerja terbuang.
2. **Sudah:** daftar tenant + `tenant-baru.ps1` di `armada/`.
   OTIN tercatat sebagai tenant pertama — versinya berhenti ditebak.
3. **Setelah beli reseller & cek verifikasi #1–#3:** jalankan `tenant-baru.ps1
   -Terapkan` untuk satu akun uji, lalu tulis `rilis.ps1`.
4. **Sebelum cucian kedua punya data nyata:** `tarik-backup.ps1`.
5. **Saat cucian ke-3 atau ke-4:** `update-semua.ps1`. Dengan dua cucian,
   deploy manual dua kali masih lebih murah dari menulis skripnya.

Pendaftaran mandiri **tidak** ada di daftar ini — lima klien pertama didatangi
langsung, dan Rohan sendiri proses onboarding-nya.

