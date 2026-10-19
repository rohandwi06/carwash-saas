# Rancangan: daftar sendiri & langsung jalan

Status: **rancangan** (19-10-2026). Kode menunggu prasyarat di bagian 7.

## 1. Yang diminta Rohan

- Cucian baru bisa **mendaftar sendiri** dan servernya **langsung jadi**.
- Daftar dengan **email + password** atau **akun Google** ("seperti aplikasi
  pada umumnya").
- **Bayar dulu 30 hari pertama**, tidak cocok = refund penuh.
- Pembayaran lewat **payment gateway otomatis** (lunas → server dibuat tanpa
  campur tangan).
- Pengaman: **kode verifikasi email** sebelum server dibuat.

## 2. Batasan Play Store (penentu bentuk)

Aplikasi Play Store yang menjual layanan digital **di dalam aplikasi** wajib
memakai Google Play Billing (potongan ±15% langganan) dan tidak boleh
mengarahkan pengguna ke pembayaran di luar. Pembayaran gateway (Midtrans/
Xendit) di dalam aplikasi berisiko ditolak. *Periksa ulang di Play Console →
Kebijakan Pembayaran sebelum rilis.*

**Rekomendasi:** pendaftaran + pembayaran di **website** (`rapiin.id/daftar`,
juga bisa dibuka dari HP), aplikasi hanya untuk **masuk** (email/password
atau Google). Ini pola umum software bisnis dan tanpa potongan Google.
Di aplikasi boleh ada tulisan netral "Belum punya akun? Hubungi kami" tanpa
tautan/harga pembayaran.

## 3. Alur

```
Web rapiin.id/daftar                     Layanan pendaftaran (byrapiin)        cPanel
1. Nama cucian, kota, jml mobil/hari
2. Email+password  ATAU  Masuk Google ─▶ verifikasi token Google / kirim kode 6 angka
3. Kode verifikasi email (bila bukan Google)
4. Pilih paket ─▶ buat tagihan gateway ─▶ halaman bayar (QRIS/VA/e-wallet)
5. Gateway kirim notifikasi LUNAS ─────▶ cek tanda tangan notifikasi
                                          buat subdomain <slug>.byrapiin.my.id ─▶ UAPI SubDomain
                                          buat database + user ────────────────▶ UAPI Mysql
                                          tulis .env cucian, migrate, akun owner
                                          daftarkan email ke buku alamat
6. Halaman "Siap": unduh aplikasi, masuk dengan email/Google.  Email konfirmasi.
```

Refund: tombol di halaman admin → API refund gateway, server cucian
dibekukan (bukan dihapus) sampai Rohan memutuskan.

## 4. Arsitektur server cucian hasil daftar sendiri

Klien lama (OTIN, Kembar 99, Thamrin, Roni) **tidak berubah**: satu salinan
kode per cucian, diperbarui lewat paket seperti sekarang.

Cucian hasil daftar sendiri memakai **satu folder kode bersama**
(`~/rapiin-app` + `~/rapiin-public`), database & penyimpanan terpisah:

- Semua subdomain baru menunjuk ke docroot yang sama.
- `bootstrap/app.php` memilih `.env` menurut host:
  `~/tenants/<slug>/.env` (DB, APP_KEY, nama usaha, zona waktu).
- `storage/` per cucian: `~/tenants/<slug>/storage` (foto, log, sesi).
- Cache/token diberi awalan slug (audit 2.1 — token tanpa id tenant).
- Update cukup diunggah sekali untuk semua cucian baru; migrasi DB per
  cucian dijalankan oleh perintah `tenants:migrate` lewat cron.

## 5. Login Google

- Server cucian: `POST /api/login/google {id_token}` → verifikasi tanda
  tangan token Google (kunci publik Google, `aud` = client ID kita, email
  terverifikasi) → email harus sama dengan email owner → token sesi.
- Aplikasi: tombol "Masuk dengan Google" (paket `google_sign_in`) → email
  dari Google → buku alamat (sudah ada) → login Google ke server itu.
- Web pendaftaran: Google Identity Services.

## 6. Komponen yang dibuat

| Bagian | Isi |
|---|---|
| `rapiin-daftar/` (Laravel kecil di byrapiin) | form daftar, kode email, Google, tagihan gateway, webhook, penyedia (UAPI), halaman admin (daftar pendaftar, refund, bekukan) |
| carwash-saas | `.env` per host, storage per cucian, `POST /api/login/google`, perintah `tenants:migrate`, penguncian saat dibekukan |
| android-app | "Masuk dengan Google", teks "Belum punya akun?" |
| Uji di laptop | cPanel tiruan (UAPI palsu) + mode sandbox gateway, jadi semua alur bisa dites sebelum menyentuh hosting |

## 7. Prasyarat (hanya bisa dilakukan Rohan)

1. **API token cPanel** (cPanel → Security → Manage API Tokens) — disimpan
   sendiri di `.env` layanan pendaftaran. Kunci penuh hosting: jangan dikirim
   lewat chat.
2. **Akun payment gateway** (Midtrans atau Xendit): daftar & verifikasi
   usaha (KYC), lalu ambil kunci *sandbox* untuk uji dan *production* untuk
   jalan. Server Key juga disimpan sendiri di `.env`.
3. **Google Cloud project** → OAuth client ID: satu "Web", satu "Android"
   (butuh SHA-1 kunci tanda tangan rilis dari `key.properties`).
4. **Email pengirim (SMTP)** di hosting untuk kode verifikasi & konfirmasi,
   mis. `noreply@byrapiin.my.id`.
5. Cek **batas paket hosting**: jumlah database & subdomain.
6. Harga paket (MODEL-BISNIS: 99/199/349 rb) dan kebijakan refund tertulis
   (dibutuhkan gateway & Play Store).

## 8. Urutan kerja yang disarankan

1. Arsitektur folder kode bersama di carwash-saas + uji di laptop
   (dua cucian palsu di satu folder).
2. Layanan pendaftaran dengan UAPI tiruan → alur daftar sampai server jadi.
3. Gateway mode sandbox + webhook.
4. Login Google (server + aplikasi + web).
5. Uji sungguhan di hosting dengan token & kunci sandbox.
