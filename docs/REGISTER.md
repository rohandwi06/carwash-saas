# Rancangan: daftar sendiri & langsung jalan

Status: **rancangan** (19-10-2026). Bayar di website (diputuskan). Model diubah 19-10 sore: **akun & server gratis
dulu, pencatatan dikunci sampai bayar** (bagian 3a). Kunci langganan sudah dibuat; sisanya menunggu prasyarat di bagian 7.

## 1. Yang diminta Rohan

- Cucian baru bisa **mendaftar sendiri** dan servernya **langsung jadi**.
- Daftar dengan **email + password** atau **akun Google** ("seperti aplikasi
  pada umumnya").
- ~~Bayar dulu baru server dibuat~~ → **diganti 19-10**: "bikin akun dulu, tapi untuk
  input data dan segala macem akan ada semacam payware". Server langsung jadi
  saat daftar; bayar 30 hari pertama membuka pencatatan, tidak cocok = refund penuh.
- Pembayaran lewat **payment gateway otomatis** (lunas → langganan aktif tanpa
  campur tangan).
- Pengaman: **kode verifikasi email** sebelum server dibuat.

## 2. Batasan Play Store (penentu bentuk)

Aplikasi Play Store yang menjual layanan digital **di dalam aplikasi** wajib
memakai Google Play Billing (potongan ±15% langganan) dan tidak boleh
mengarahkan pengguna ke pembayaran di luar. Pembayaran gateway (Midtrans/
Xendit) di dalam aplikasi berisiko ditolak. *Periksa ulang di Play Console →
Kebijakan Pembayaran sebelum rilis.*

**Keputusan Rohan 19-10: pembayaran di website dulu.** Pendaftaran + pembayaran di **website** (`rapiin.id/daftar`,
juga bisa dibuka dari HP), aplikasi hanya untuk **masuk** (email/password
atau Google). Ini pola umum software bisnis dan tanpa potongan Google.
Keputusan Rohan 19-10: tombol **"Belum punya akun? Daftar"** di aplikasi membuka
halaman daftar di website. Risiko kebijakan (tautan ke pembayaran luar) diredam:
tombolnya diatur dari `direktori/aplikasi.json` (tampil/teks/url) dan bisa
dimatikan tanpa rilis ulang. Bawaan: mati sampai website pendaftaran jadi.

## 3. Alur

```
Web rapiin.id/daftar                     Layanan pendaftaran (byrapiin)        cPanel
1. Nama cucian, kota, jml mobil/hari
2. Email+password  ATAU  Masuk Google ─▶ verifikasi token Google / kirim kode 6 angka
3. Kode verifikasi email (bila bukan Google)
4. Kode benar ──────────────────────────▶ buat subdomain <slug>.byrapiin.my.id ─▶ UAPI SubDomain
                                          buat database + user ────────────────▶ UAPI Mysql
                                          tulis .env cucian, migrate, akun owner
                                          daftarkan email ke buku alamat
                                          `php artisan langganan kunci`
5. Halaman "Siap" + email: unduh aplikasi, masuk, atur harga/pekerja/menu.
   Email berisi tautan bayar (website).
6. Bayar di website ─▶ tagihan gateway ─▶ notifikasi LUNAS ─▶ cek tanda tangan
                                          POST {server}/api/langganan/perpanjang
                                          (HMAC LANGGANAN_KUNCI) → pencatatan terbuka
```

## 3a. Kunci langganan (sudah dibuat 19-10, carwash-saas)

Keputusan Rohan: **boleh atur, belum boleh transaksi**; saat terkunci aplikasi
menampilkan **pesan netral**, tautan bayar dikirim **lewat email**.

- Setelan `langganan_sampai` (`LanggananService`): tidak ada = cucian lama,
  tidak pernah dikunci; kosong = belum bayar; tanggal = aktif sampai akhir hari itu.
- Middleware `langganan` (HTTP 402) di rute pencatatan: transaksi, transaksi
  tanggal lampau, draft, F&B, draft F&B, pengeluaran, setoran & saldo awal buku
  kas, setoran/gerak barang titipan. Laporan & semua pengaturan tetap jalan.
- `GET /api/langganan` → pita kuning netral di web (`#pitaLangganan`) dan
  aplikasi (`PitaLangganan`). Tanpa harga, tanpa tautan.
- `POST /api/langganan/perpanjang` dari pusat: badan `{pesanan, hari, waktu}`,
  header `X-Tanda` = HMAC-SHA256 badan dengan `LANGGANAN_KUNCI`; waktu ±10 menit;
  nomor pesanan yang sama tidak dihitung dua kali. Tanpa kunci di `.env` = 404.
- Bayar lebih awal disambung dari tanggal akhir; habis = terkunci lagi.
- Manual (mis. transfer): `php artisan langganan tambah 30`, `... kunci`, `... lepas`.

Refund: tombol di halaman admin → API refund gateway, langganan dikunci lagi
(`langganan kunci`); data tidak dihapus sampai Rohan memutuskan.
Akun yang tidak pernah bayar: dihapus otomatis setelah N hari (N ditentukan Rohan)
supaya database hosting tidak habis oleh pendaftar iseng.

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
| carwash-saas | `.env` per host, storage per cucian, `POST /api/login/google`, perintah `tenants:migrate`; kunci langganan **(selesai)** |
| android-app | "Masuk dengan Google"; tombol daftar **(selesai)**; pita langganan **(selesai)** |
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
5. Cek **batas paket hosting**: jumlah database & subdomain. Penting karena
   server kini dibuat SEBELUM bayar: pendaftar yang tidak pernah bayar ikut
   memakan jatah.
6. Harga paket (MODEL-BISNIS: 99/199/349 rb) dan kebijakan refund tertulis
   (dibutuhkan gateway & Play Store).

## 8. Urutan kerja yang disarankan

1. Arsitektur folder kode bersama di carwash-saas + uji di laptop
   (dua cucian palsu di satu folder).
2. Layanan pendaftaran dengan UAPI tiruan → alur daftar sampai server jadi.
3. Gateway mode sandbox + webhook.
4. Login Google (server + aplikasi + web).
5. Uji sungguhan di hosting dengan token & kunci sandbox.
