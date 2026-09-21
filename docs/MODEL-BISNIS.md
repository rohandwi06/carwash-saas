# Model Bisnis — angka langganan

Disusun 2026-09-14 dari **data produksi nyata** OTIN Carwash, bukan perkiraan pasar.
Direvisi 2026-09-21: infrastruktur pindah dari VPS ke **reseller hosting ArenHost**,
dan ditambah **cara menentukan harga** untuk tiap calon klien.

Dokumen pendamping: [AUDIT-MULTITENANT.md](AUDIT-MULTITENANT.md) (apa yang harus dibangun),
[BUKTI-LAPANGAN.md](BUKTI-LAPANGAN.md) (apa yang dijual).

---

## Data dasar

Diambil dari database produksi, rentang 20 Juli – 3 September 2026 (46 hari):

| | |
|---|---|
| Transaksi cuci | 733 → **15,9 per hari** |
| Omzet 46 hari | Rp 24.395.000 → **~Rp 15,9 juta/bulan** |
| Ukuran database | **1,66 MB** |
| Penjualan F&B | 101 |
| Kendaraan di katalog | 99 |
| Pekerja | 8 |

Ini **profil satu cucian**, dipakai sebagai patokan awal. Belum tentu mewakili cucian
lain — lihat daftar verifikasi di akhir.

---

## 1. Infrastruktur: hosting biasa, bukan VPS

**1,66 MB untuk 46 hari** berarti ~13 MB per cucian per tahun. Lima puluh cucian
masih di bawah 1 GB. 16 transaksi sehari berarti server nyaris menganggur.

Beban sekecil ini **tidak butuh VPS**. Dan aplikasinya sudah terbukti jalan di shared
hosting cPanel — OTIN jalan di ArenHost sekarang:

- **Tidak memakai queue** — tidak ada `app/Jobs`, tidak ada `dispatch()`. Ini biasanya
  alasan nomor satu orang butuh VPS, dan tidak berlaku di sini.
- **Scheduler cukup cron** — `routes/console.php` hanya butuh
  `* * * * * php artisan schedule:run`, dan cPanel menyediakannya.
- **Satu-satunya perlawanan shared hosting sudah diatasi** — ArenHost memblokir
  DELETE/PUT/PATCH di level server; diselesaikan di `593968a` lewat
  `X-HTTP-Method-Override` tanpa mengubah satu pun controller.

VPS juga menambah pekerjaan sysadmin (patching OS, web server, MySQL, SSL) —
padahal menurut bagian 3, **waktu adalah biaya termahal**. Hosting biasa menghapus
pekerjaan itu sepenuhnya.

### Pilihan: Reseller Hosting ArenHost (WHM + cPanel), dicek 2026-09-15

Reseller hosting dirancang persis untuk jualan seperti ini: satu akun induk (WHM)
yang bisa membuat banyak akun cPanel — **satu cPanel untuk satu cucian**.

| Paket | Jatah | Storage | Per bulan | Per tahun | Efektif/bulan |
|---|---|---|---|---|---|
| **Small** | **5 cPanel** | 5 GB | Rp 29rb | Rp 229rb | **Rp 19,1rb** |
| **Starter** | **15 cPanel** | 15 GB | Rp 59rb | Rp 559rb | **Rp 46,6rb** |
| **Enterprise** | **30 cPanel** | 30 GB | Rp 99rb | Rp 999rb | **Rp 83,3rb** |

Tiap cPanel dapat 2 CPU / 4 GB RAM, backup mingguan yang bisa di-restore, SSL gratis,
LiteSpeed, dan PHP multi-versi 5.x–8.x.

**Biaya per cucian saat paket penuh:** Small Rp 3.817 · Starter **Rp 3.106** ·
Enterprise **Rp 2.775**.

### Kenapa satu cPanel per cucian

| | Satu cPanel per cucian | Satu cPanel untuk semua |
|---|---|---|
| Isolasi data | **penuh** — database, file, `.env` terpisah | berbagi |
| Bikin cucian baru | bisa diotomatiskan lewat **WHM API** | database dibuat manual |
| Deploy perbaikan | **N kali** (satu per cPanel) | sekali |

Yang dipilih: **satu cPanel per cucian**. Harganya adalah deploy jadi N kali — dijawab
dengan menjadikan `deploy/cpanel/buat-update.ps1` berputar ke daftar akun, bukan
dengan pindah ke VPS. Dan isolasinya jadi kalimat jualan: *"database cucian Bapak
terpisah sendiri, tidak campur dengan cucian lain."*

Pilihan ini juga **menyederhanakan audit multi-tenant** — lihat bagian 8.

### Domain

Satu domain utama (rencana: **rapiin.id**), tiap cucian memakai subdomain —
`otin.rapiin.id`, `namacucian.rapiin.id`. Tidak ada biaya domain per cucian.

| Domain | Tahun pertama | Perpanjangan |
|---|---|---|
| .com (pembanding) | Rp 164rb | **Rp 209rb/tahun** (~Rp 17,4rb/bulan) |
| .id | *belum dicek* | *belum dicek* |

Yang dipakai menghitung adalah **harga perpanjangan** — diskon tahun pertama cuma
menunda biaya. Angka .com dipakai sebagai perkiraan sampai harga .id dicek.

### Biaya tetap per tahap

| Jumlah cucian | Paket | Hosting/bulan | + domain | **Total tetap** |
|---|---|---|---|---|
| 1–5 | Small Reseller | Rp 19,1rb | Rp 17,4rb | **Rp 36,5rb** |
| 6–15 | Starter Reseller | Rp 46,6rb | Rp 17,4rb | **Rp 64rb** |
| 16–30 | Enterprise Reseller | Rp 83,3rb | Rp 17,4rb | **Rp 100,7rb** |
| 30+ | baru pertimbangkan VPS | — | — | — |

### Catatan risiko

ArenHost penyedia kecil (PT Data Kencang Hutama, sejak 2023). Backup mingguan dari
hosting tinggal **di penyedia yang sama** — kalau penyedianya yang bermasalah, backup itu
ikut tidak terjangkau. Jadi tetap perlu salinan di luar: unduh backup berkala ke tempat
lain (komputer sendiri / Google Drive). Murah, dan wajib sebelum ada data cucian orang
lain di sana.

---

## 2. Keputusan API key Gemini — PAKAI KEY SENDIRI

Ini menutup pertanyaan terbuka di [AUDIT-MULTITENANT.md](AUDIT-MULTITENANT.md) bagian 1.4.

- **Yang habis pada 30 Agustus 2026 itu kuota GRATIS** — batas jumlah permintaan harian,
  bukan tagihan. Menyalakan penagihan menyelesaikannya.
- **Prompt-nya kecil.** `GeminiVehicleService::classify()` hanya mengirim daftar
  **kategori** (4 baris), bukan 99 kendaraan.
- **Pemakaiannya menurun sendiri.** AI hanya ditanya untuk nama yang tidak ada di katalog;
  ada cache 30 hari; dan jawaban yang disimpan **masuk ke katalog**, sehingga kendaraan
  yang sudah pernah ditanya tidak pernah ditanya lagi.

Perkiraan biaya: **di bawah Rp 15rb per cucian per bulan**, dan terus mengecil.
Menyuruh owner membuat API key sendiri adalah hambatan onboarding demi menghemat biaya
yang besarnya di bawah 10% dari satu langganan. Tidak sepadan.

Dengan satu cPanel per cucian, key disimpan di `.env` tiap akun — satu key yang sama
dipasang di semua akun. Batas pemakaian per cucian otomatis terbentuk karena throttle
`20,1` sekarang berlaku per instalasi.

---

## 3. Biaya terbesar bukan server — tapi waktu

| Pos | Per cucian per bulan |
|---|---|
| Hosting (share reseller) | **~Rp 3–4rb** |
| Gemini | **< Rp 15rb** |
| Dukungan: 1–2 jam @ Rp 50rb | **Rp 50–100rb** |

Dukungan jauh di atas biaya teknis. **Harga langganan harus menutup waktu, bukan server.**
Ini juga yang menentukan lantai harga di bagian 4.

---

## 4. Harga — dan cara menentukannya

### Harga patokan

| Ukuran cucian | Patokan | Per bulan | Per tahun (2 bulan gratis) |
|---|---|---|---|
| Kecil | < 15 mobil/hari | **Rp 99rb** | Rp 990rb |
| Sedang | 15–35 mobil/hari | **Rp 199rb** | Rp 1,99jt |
| Besar | > 35 mobil/hari, atau > 1 cabang | **Rp 349rb** | Rp 3,49jt |

**Biaya pemasangan Rp 750rb**, sekali — ditagih saat klien jadi berlangganan, bukan
di awal percobaan.

Semua ukuran dapat **fitur yang sama persis**. Yang membedakan hanya ukuran usahanya.

### Dari mana angka ini

Tiga jangkar:

| Jangkar | Fungsi | Angka |
|---|---|---|
| Biaya + waktu | **lantai** — di bawah ini rugi | **~Rp 120rb** (dukungan 50–100rb + teknis ~20rb) |
| Kerugian yang dicegah | **plafon** | 2 pembatalan diam-diam/minggu × 40rb × 4 ≈ **Rp 320rb/bulan** |
| Pembanding di kepala owner | **rasa mahal/murah** | gaji satu karyawan (Rp 199rb ≈ 10% gaji Rp 2jt) |

Rp 199rb = **1,25% dari omzet OTIN**. Paket Kecil Rp 99rb sengaja **di bawah lantai** —
hanya layak kalau cucian kecil memang butuh dukungan lebih sedikit. Kalau ternyata
sama repotnya, naikkan.

### Kenapa per cucian, bukan per transaksi atau per akun

- **Per transaksi** memberi owner alasan **untuk tidak mencatat** — padahal catatan
  lengkap itulah produknya.
- **Per akun kasir** memberi owner alasan **berbagi satu akun** — padahal `voided_by`
  dan `created_by` cuma berguna kalau tiap orang punya akunnya sendiri.

Semua metrik berbasis pemakaian melawan produk ini sendiri. Flat per cucian tidak
menghukum apa pun yang justru ingin didorong.

### Kenapa patokannya mobil/hari, bukan omzet

- Owner sering enggan menyebut omzet, atau mengarangnya.
- Mobil/hari bisa dilihat sendiri saat berkunjung.
- Setelah masa percobaan, **aplikasinya sendiri yang menghitung** — tidak bisa diperdebatkan.

### Cara menentukan harga untuk satu calon klien

1. **Jangan sebut harga di pertemuan pertama.**
2. **Tawarkan percobaan gratis satu bulan** — produk penuh, tanggal akhir disepakati di
   depan, **satu percobaan aktif dalam satu waktu**.
3. **Akhiri percobaan dengan pertemuan, bukan pesan.** Buka Dashboard bersama:
   *"Bulan ini tercatat 480 mobil, omzet 15 juta, dan ada 6 transaksi dibatalkan."*
4. **Baru sebut harga**, sesuai ukuran yang terukur dari data percobaan itu.

Percobaan itu sendiri yang memproduksi bukti untuk harganya. Kalau selama sebulan tidak
ada satu pun pembatalan mencurigakan, cucian ini memang tidak butuh — dan tidak perlu dikejar.

### Aturan saat menyebut angka

- **Satu angka, bukan rentang.** "150–250rb" membuat owner mendengar 150 lalu menawar.
- **Bandingkan dengan gaji satu karyawan**, bukan dengan aplikasi lain.
- **Jangan turun harga tanpa menukar sesuatu.** Kalau ditawar: *"bisa, kalau bayar
  setahun di muka."*
- **Tiga klien pertama boleh harga perkenalan** — tapi ditulis hitam di atas putih bahwa
  itu harga untuk 3 pelanggan pertama, supaya menaikkannya nanti tidak terasa pengkhianatan.

### Cara tahu harganya salah

| Yang terjadi | Artinya |
|---|---|
| Semua bilang ya tanpa berpikir | **Kemurahan** — naikkan untuk calon berikutnya |
| Sekitar separuh bilang ya | Pas |
| Hampir semua menolak | Kemahalan — **atau** salah sasaran (owner yang jaga kasir sendiri) |

Kalau tiga calon pertama langsung setuju, harga untuk calon keempat harus naik.
Itu satu-satunya cara menemukan batas atas tanpa riset pasar.

---

## 5. Titik impas

Asumsi: Rp 199rb/cucian/bulan (ukuran Sedang), biaya tetap sesuai tahap di bagian 1,
biaya marginal ~Rp 20rb/cucian (Gemini, dibulatkan ke atas).

| Cucian | Paket | Masuk/bulan | Biaya | **Sisa** | Jam dukungan/bulan |
|---|---|---|---|---|---|
| **1** | Small | Rp 199rb | ~Rp 57rb | **~Rp 142rb** | 1–2 |
| 2 | Small | Rp 398rb | ~Rp 77rb | **~Rp 321rb** | 2–4 |
| 5 | Small | Rp 995rb | ~Rp 137rb | **~Rp 858rb** | 5–10 |
| 10 | Starter | Rp 1,99jt | ~Rp 264rb | **~Rp 1,73jt** | 10–20 |
| 20 | Enterprise | Rp 3,98jt | ~Rp 501rb | **~Rp 3,48jt** | **20–40** |
| 30 | Enterprise | Rp 5,97jt | ~Rp 701rb | **~Rp 5,27jt** | 30–60 |

Tiga hal yang harus dibaca jujur:

1. **Impas di klien pertama.** Satu langganan menutup seluruh infrastruktur dengan
   sisa ~Rp 142rb. Tidak ada periode merugi yang harus dibiayai lebih dulu.
2. **Hosting hampir tidak terasa.** Di 30 cucian, hosting ~Rp 83rb dari pemasukan
   Rp 5,97jt — **1,4%**. Biaya marginal terbesar justru Gemini, bukan server.
3. **Yang jebol duluan adalah jam dukungan.** Di 20 cucian, 20–40 jam sebulan setara
   kerja paruh waktu. Prioritasnya: kurangi jam dukungan per cucian, bukan optimasi server.

---

## 6. Kapan harus berhenti mengerjakan manual

| Jumlah cucian | Yang harus sudah ada |
|---|---|
| 1–2 | Nama bisnis jadi data (audit 1.1); backup diunduh berkala ke luar hosting |
| 3–5 | Skrip deploy berputar ke semua akun cPanel; daftar langkah onboarding tertulis |
| 5–10 | Bikin cucian baru lewat WHM API (satu perintah); panduan dalam aplikasi diperluas |
| **~10** | Penagihan otomatis (payment gateway) mulai layak; jalur dukungan yang dibatasi jam |
| 30+ | Baru pertimbangkan VPS — itu pun hanya kalau batas reseller benar-benar tersentuh |

Sebelum klien ke-10, mengerjakan manual masih lebih murah daripada membangun otomatisasi.

---

## 7. Yang sengaja TIDAK dilakukan

- **Tidak ada paket berjenjang menurut fitur** (basic/pro). Tingkatan hanya menurut
  ukuran usaha; semua dapat fitur yang sama.
- **Tidak ada VPS** sampai batas reseller hosting benar-benar tersentuh.
- **Klien pertama tidak digratiskan selamanya** sebagai imbalan jadi referensi.
  Beri harga loyalitas (mis. Rp 99rb selamanya) — klien gratis diam-diam berhenti
  menganggap produknya bernilai.
- **Tidak membangun pendaftaran mandiri sebelum ada yang mendaftar.** Untuk lima klien
  pertama, Rohan sendiri proses onboarding-nya — sekaligus mendengar langsung apa yang
  mereka bingungkan, mekanisme yang sama yang menghasilkan 14 perbaikan dari klien pertama.

---

## 8. Dampak ke audit multi-tenant

[AUDIT-MULTITENANT.md](AUDIT-MULTITENANT.md) ditulis dengan asumsi **satu aplikasi
melayani semua cucian**. Dengan **satu cPanel per cucian**, tiap cucian punya instalasi,
database, `.env`, dan folder `storage` sendiri. Beberapa temuan audit jadi mengecil:

| Temuan audit | Dengan satu cPanel per cucian |
|---|---|
| 1.1 Nama bisnis tertanam di 8 tempat | **Tetap perlu** — tiap instalasi harus bisa bernama lain |
| 1.2 Akun owner dari `.env` | **Hilang** — tiap cucian punya `.env` sendiri |
| 1.3 Backup menumpuk satu folder | **Hilang** — `storage/backups` terpisah per akun. Tapi salinan di luar hosting tetap wajib |
| 1.4 Satu API key Gemini | Sudah diputuskan (bagian 2); throttle otomatis per instalasi |
| 1.5 Zona waktu terkunci WIB | **Tetap perlu** kalau ada cucian di luar WIB |
| 2.1 Token tanpa id tenant | **Hilang** — cache di database tiap akun |
| Konsep tenant & tabel tenant | **Jauh lebih kecil** — cukup daftar akun untuk skrip deploy & WHM |
| **Baru:** deploy ke N akun | **Perlu** — skrip deploy berputar ke semua akun |

Audit perlu direvisi mengikuti keputusan ini.

---

## Yang masih perlu diverifikasi

1. ~~Harga hosting~~ — sudah: ArenHost Reseller Small/Starter/Enterprise (dicek 2026-09-15)
2. **Harga domain .id** (rencana rapiin.id) — belum dicek
3. Apakah harga ArenHost sudah termasuk PPN
4. Apakah WHM di paket reseller ArenHost membuka akses API untuk membuat akun cPanel
5. Apakah akun cPanel reseller bisa memakai subdomain (`nama.rapiin.id`) sebagai domain utamanya
6. Tarif Gemini 2.5 Flash terkini per juta token
7. Apakah omzet ~Rp 15,9 juta/bulan mewakili cucian lain — baru bisa dijawab setelah
   bicara dengan cucian kedua

---

## Lampiran — kenapa bukan VPS

Dicek 2026-09-15, disimpan sebagai pembanding:

| Penyedia | Paket | Spesifikasi | Efektif/bulan |
|---|---|---|---|
| ArenHost | KVM-2 (tahunan) | 1 CPU · 2 GB · 25 GB | Rp 50rb |
| Jagoan Hosting | Nebula (+PPN 11%) | 2 Core · 2 GB · 40 GB | Rp 111rb |

VPS sempat direkomendasikan dengan dua alasan, dan keduanya ternyata keliru:

- **"Lima cucian = lima kali deploy manual"** — tidak harus. Deploy bisa diskrip, dan
  beberapa domain bahkan bisa diarahkan ke satu docroot.
- **"Provisioning satu perintah butuh root"** — tidak. WHM di reseller hosting menyediakan
  pembuatan akun lewat API.

Yang tersisa dari VPS hanyalah kerja sysadmin tambahan dengan harga lebih mahal.
Dipertimbangkan lagi hanya kalau batas reseller hosting benar-benar tersentuh (30+ cucian).
