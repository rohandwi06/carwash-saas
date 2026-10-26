# Tagihan manual (tahap 1)

Disusun 26-10-2026. Klien membayar **transfer atau QRIS ke rekeningmu**, lalu
kamu mengaktifkan langganannya **dari laptop** dengan satu perintah. Server
cucian mengunci pencatatan sendiri begitu masa aktif habis, dan membukanya
lagi begitu diperpanjang. Tidak perlu Terminal di cPanel.

Tahap 2 (payment gateway) memakai jalur server yang sama
(`POST /api/langganan/perpanjang`, bertanda tangan `LANGGANAN_KUNCI`), jadi
server cucian tidak perlu diubah lagi saat pindah — lihat bagian akhir.

## Alur dari bayar sampai klien bisa memakai aplikasi

| # | Siapa | Apa |
|---|---|---|
| 1 | Calon klien | Coba demo di aplikasi, lalu tombol **Daftar** → formulir (nama cucian, kota, pemilik, email, WhatsApp). |
| 2 | Kamu | Hubungi lewat WhatsApp, sepakati paket (Kasir / Usaha / Juragan). |
| 3 | Kamu | Siapkan server: `scripts\New-Tenant.ps1` (membuat `.env` berisi `LANGGANAN_KUNCI`), lalu unggah & pasang sesuai `deploy\cpanel\README.md`. |
| 4 | Kamu | Percobaan 30 hari, paket Usaha penuh: `scripts\Set-Langganan.ps1 -Slug budi -Hari 30 -Paket usaha -Catatan "percobaan"` |
| 5 | Kamu | Masuk sebagai owner di web cucian itu → Pengaturan → Akun owner: isi **email owner** (sekaligus mendaftarkannya ke buku alamat login aplikasi). Serahkan email + password awal ke klien. |
| 6 | Klien | Unduh aplikasi dari Play Store → **masuk dengan email & password** → atur harga, pekerja, kasir → mulai mencatat. |
| 7 | Klien | 7 hari sebelum habis, owner melihat pita pengingat di aplikasi & web. |
| 8 | Kamu | Hari itu juga kirim tagihan lewat WhatsApp (contoh di bawah). |
| 9 | Klien | Bayar transfer / QRIS, kirim bukti. |
| 10 | Kamu | Cek mutasi rekening, lalu `scripts\Set-Langganan.ps1 -Slug budi -Hari 30 -Paket usaha -Catatan "BCA 26/10"` |
| 11 | Klien | Langsung jalan. Pita hilang setelah aplikasi dibuka ulang / web dimuat ulang. |

Tidak bayar sampai tanggal akhir: sejak hari berikutnya **pencatatan terkunci**
(cucian, F&B, pengeluaran, setoran ditolak), tapi laporan & pengaturan tetap
bisa dibuka dan **data tidak hilang**. Bayar kapan pun → langkah 10 → terbuka lagi.

## Perintah

Dijalankan dari folder `ops\`, awali dengan `powershell -ExecutionPolicy Bypass -File`:

| Keperluan | Perintah |
|---|---|
| Percobaan / perpanjang 30 hari | `scripts\Set-Langganan.ps1 -Slug budi -Hari 30 -Paket usaha` |
| Bayar setahun (12 bulan) | `scripts\Set-Langganan.ps1 -Slug budi -Hari 365 -Catatan "tahunan, BCA 26/10"` |
| Naik / turun paket saja | `scripts\Set-Langganan.ps1 -Slug budi -Paket kasir` |
| Cek dulu tanpa mengirim | tambahkan `-Uji` |

- **Hari** dihitung termasuk hari ini; kalau masih aktif, **disambung dari tanggal
  akhir** — klien yang bayar lebih awal tidak rugi.
- `-Paket` boleh dikosongkan saat perpanjang; paket lama tetap.
- Tiap perintah mendapat nomor pesanan sendiri (`MANUAL-budi-<waktu>`). Perintah
  yang sama terkirim dua kali karena koneksi putus **tidak** menambah hari dua kali
  selama nomornya sama (pakai `-Pesanan` untuk mengulang dengan nomor yang sama).
- Hasilnya dicatat di `tenants\budi.json` (`langganan.sampai`, `paket`, `riwayat`)
  — commit berkas itu supaya riwayat bayar ikut tersimpan.

## Yang dilihat owner & kasir

| Sisa masa aktif | Owner | Kasir |
|---|---|---|
| lebih dari 7 hari | tidak ada pita | tidak ada pita |
| 7 hari terakhir | pita kuning: "Langganan cucian ini berakhir 3 hari lagi (29 Oktober 2026)..." | tidak ada pita |
| habis | pita: belum aktif, info dikirim ke email/WhatsApp owner; pencatatan ditolak | pita: hubungi owner; pencatatan ditolak |

## Contoh pesan WhatsApp

Isian `{...}` diganti sendiri. **Harga, nomor rekening, dan QRIS hanya lewat
WhatsApp/email — jangan pernah di aplikasi, di listing Play Store, atau di
halaman yang dibuka dari aplikasi (daftar, privasi, hapus akun).** Itu yang
membuat aplikasi boleh tanpa Google Play Billing.

**Tagihan (7 hari sebelum habis)**
> Halo {nama owner}, langganan aplikasi kasir {nama cucian} aktif sampai {tanggal}.
> Untuk 1 bulan berikutnya paket {paket}: Rp {harga}.
> Transfer ke {bank} {nomor} a.n. {nama rekening}, atau scan QRIS terlampir.
> Kirim bukti bayar ke nomor ini ya, langsung saya aktifkan. Terima kasih 🙏

**Akhir percobaan**
> Halo {nama owner}, masa percobaan {nama cucian} selesai {tanggal}. Selama sebulan
> tercatat {jumlah} kendaraan dan {jumlah} pembatalan. Kalau mau lanjut, paket {paket}
> Rp {harga}/bulan (atau Rp {harga tahunan}/tahun). Pembayaran ke {bank} {nomor} a.n. {nama}.

**Pembayaran diterima**
> Pembayaran sudah diterima, terima kasih. Langganan {nama cucian} aktif sampai {tanggal baru}.
> Kalau di aplikasi masih ada pita kuning, tutup lalu buka lagi aplikasinya.

**Sudah terkunci**
> Halo {nama owner}, langganan {nama cucian} berakhir {tanggal}, jadi pencatatan sementara
> terkunci. Semua data aman. Begitu pembayaran Rp {harga} masuk, langsung saya buka lagi.

## Cucian lama (dibuat sebelum 26-10)

OTIN, Kembar 99, Thamrin, dan Roni **tidak punya kunci langganan**: tidak pernah
terkunci dan tidak ada pengingat — tagihannya tetap seperti sekarang. Kalau
suatu saat mau memasukkan salah satunya ke tagihan ini:

1. Kodenya harus versi 19-10 ke atas (kunci langganan). OTIN memakai repo
   otin-carwash yang **belum punya** fitur ini — tidak bisa.
2. Buat kunci: `Import-Module .\scripts\Common.psm1; New-Secret 40`.
3. Tambahkan baris `LANGGANAN_KUNCI=<kunci>` ke `.env` server (File Manager) **dan**
   ke `secrets\<slug>.env` — isinya harus sama persis.
4. Kabari ownernya dulu, lalu `scripts\Set-Langganan.ps1 -Slug <slug> -Hari <sisa hari yang sudah dibayar>`.
   Sejak itu server mengunci sendiri bila tidak diperpanjang.

## Kalau Set-Langganan gagal

| Pesan | Sebab | Yang dilakukan |
|---|---|---|
| `Gagal (404)` | `.env` server belum berisi `LANGGANAN_KUNCI`, atau kodenya lebih tua dari 19-10 | tambahkan kuncinya / unggah paket update |
| `Gagal (403)` | kunci di `secrets\<slug>.env` beda dengan `.env` server | samakan keduanya |
| `Gagal (422)` | paket salah ketik, `-Hari 0` tanpa `-Paket`, atau jam laptop selisih > 10 menit | periksa perintah / jam Windows |
| `LANGGANAN_KUNCI belum ada di secrets` | cucian lama | bagian "Cucian lama" di atas |
| `[!] Nomor pesanan ... sudah pernah dicatat` | perintah dengan `-Pesanan` yang sama diulang | aman, tidak ada yang berubah |

## Nanti: tahap 2 (payment gateway)

Layanan pusat kecil di byrapiin.my.id membuat tagihan Midtrans/Xendit, tautannya
dikirim lewat email/WhatsApp (tetap **bukan** dari aplikasi). Begitu notifikasi
LUNAS masuk, pusat memanggil jalur yang sama dengan `pesanan` = nomor order
gateway. `Set-Langganan.ps1` tetap dipakai untuk transfer manual, refund, atau
kompensasi. Prasyarat dari kamu: akun gateway + verifikasi (KYC), kunci
sandbox, dan kebijakan refund tertulis (lihat `docs/REGISTER.md` bagian 7).
