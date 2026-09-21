# Backlog Lapangan — OTIN CARWASH

Sumber: percakapan WhatsApp dengan Mas Angga (owner OTIN Carwash), 12 Juli – 2 September 2026.
Setiap baris ditelusuri sampai commit yang menyelesaikannya.

Dokumen ini punya dua fungsi:

1. **Catatan bug & keluhan** — arsip permanen dari sesuatu yang selama ini hanya ada di chat.
2. **Spesifikasi produk untuk klien berikutnya** — daftar ini bukan tebakan fitur, ini hal yang
   benar-benar diminta owner cucian setelah memakai aplikasi tiap hari.

Status: `SELESAI` · `TERBUKA` · `KEPUTUSAN DESAIN` (sengaja tidak diotomatiskan)

---

## 1. Kontrol & kepercayaan karyawan

Kelompok terbesar. Lima dari empat belas permintaan ada di sini, dan semuanya muncul
bukan dari daftar fitur — tapi dari owner yang kaget melihat sesuatu di cucian.

### 1.1 Pembatalan transaksi tanpa sepengetahuan owner

> *21/07 15.54* — "ini yang batalno karyawanku sndiri rohan / tanpa acc aku / bisa ndak kalo d batalkan ada keterangan oleh siapa"

**Status:** SELESAI — hari yang sama
**Bukti:** commit `fc78c86` (2026-07-21) "Tambah tracking nama user yang membatalkan transaksi"
**Implementasi:** kolom `voided_by`, `void_reason`, `voided_at` di `transactions`;
kolom `created_by` supaya ketahuan juga siapa yang membuat.
**Selang waktu: 0 hari.**

### 1.2 Karyawan bisa menghapus data

> *21/07 16.08* — "aku kaget barusan ngecek d cucian, ternyata areke bisa ngehapus juga"

**Status:** SELESAI
**Bukti:** tidak ada satu pun jalur hard delete untuk transaksi di seluruh aplikasi.
Pembatalan = soft void yang tetap menyimpan baris aslinya (`app/Services/TransactionService.php:237`).
**Catatan desain:** ini keluhan yang mengubah arsitektur, bukan sekadar menambah tombol.
Sejak titik ini aplikasi menganut aturan **data tidak pernah hilang, hanya ditandai batal**.

### 1.3 Pembatalan perlu persetujuan owner

> *27/07 10.13* — "pembatalan transaksi oleh karyawan perlu aproval dari owner. soalnya beberapa kali di batal-batalkan."

**Status:** SELESAI — hari yang sama
**Bukti:** migrasi `2026_07_27_000002_add_void_approval_to_transactions.php`
(kolom `void_requested_at`), method `TransactionService::approveVoid()`,
`FnbService::approveVoid()`.
**Alur:** kasir mengajukan pembatalan → transaksi masuk status menunggu →
owner menyetujui. Owner sendiri membatalkan langsung tanpa approval
(`TransactionService.php:220` — "Dipakai owner — langsung, tanpa perlu approval siapa pun").
**Selang waktu: 0 hari.**

### 1.4 Detail gaji harus bisa ditelusuri ke transaksinya

> *27/07 10.13* — "detail gaji ini bisa tak tap garap mana aja / ikut transaksi yang mana aja. soale ini rawan ada yang maen di sini nantinya han. kek malem-malem moro-moro nginput-nginput sndiri pas numpang wifi"

**Status:** SELESAI
**Bukti:** `WageService::dailyRecap()` mengembalikan `breakdown` per pekerja, tiap baris
membawa `transaction_id` (`app/Services/WageService.php:243,259`).
Owner bisa menekan nama pekerja dan melihat persis mobil mana saja yang jadi dasar upahnya.
**Catatan:** kecurigaan "nginput sendiri pas numpang wifi" juga dijawab oleh
penguncian jam operasional (`ShiftGuard` + `ShiftService`) — di luar shift, akun kasir
tidak bisa memakai aplikasi sama sekali. Owner tetap bisa masuk kapan saja.

### 1.5 Input stok hanya boleh owner

> *20/07 13.53* — "yang bisa input hanya owner ya"

**Status:** SELESAI
**Bukti:** middleware `app/Http/Middleware/OwnerOnly.php`, dipasang pada rute stok/produk.

---

## 2. Uang harus ketemu

Pola kedua: tiap keluhan di sini soal **angka di aplikasi tidak cocok dengan uang di laci**.

### 2.1 Input pengeluaran

> *23/07 13.06* — "input pengeluaran / lupa kmaren blum ta minta"

**Status:** SELESAI — 4 hari
**Bukti:** commit `00eba37` (2026-07-27) "Tambah 7 fitur operasional";
model `Expense`, `ExpenseController`.

### 2.2 Pengeluaran tidak mengurangi uang cash

> *22/08 19.54* — "rohan, pengeluaran g ngurangin uang cash"

**Status:** SELESAI — 2 hari
**Bukti:** commit `1d9ca57` (2026-08-24) "Pengeluaran terlihat memotong omzet,
dan Pendapatan ikut hitung F&B", dilanjut `65e019b` "Omzet jadi angka bersih setelah pengeluaran".

### 2.3 Saldo kas diinput manual tiap pagi, bukan dihitung aplikasi

> *28/08 21.33* — "pengeluaran ikutin saldo aja nnyi, jadi di aplikasi kita input nominal sendiri aja 100k / kayak manual kita nginput tiap pagi"

**Status:** SELESAI — 1 hari
**Bukti:** commit `55f7a54` (2026-08-29) "Saldo kas kecil per buku: input manual tiap pagi,
murni pelacak uang di tangan"; migrasi `2026_08_25_000001_add_opening_balance_to_cash_books_table`.
**Pelajaran produk:** owner menolak angka yang dihitung otomatis untuk kas fisik.
Dia ingin aplikasi **mengikuti** hitungan tangannya, bukan mendikte. Ini bukan kemunduran
fitur — ini cara owner memverifikasi bahwa aplikasinya jujur.

### 2.4 Tip masuk ke mana?

> *02/09 20.50* — "Tip itu masuknya kemana? Masuk ke gajinya arek-arek apa masuk ke cash ku ya?"
> *02/09 20.53* — "Soale aku ngedum e itu manual kalo ini. Oke deh bagus / Tak pikir km masukkan gaji"

**Status:** SELESAI (fitur sudah ada sebelum ditanya)
**Bukti:** commit `40d1118` (2026-08-20) "Tip di penjualan F&B";
kolom `tip` di `fnb_sales` dan `fnb_drafts`, terpisah penuh dari perhitungan `WageService`.
**Catatan penting:** ini bukan permintaan fitur — ini **pertanyaan yang tidak terjawab
oleh antarmuka**. Owner harus bertanya lewat WhatsApp untuk tahu perilaku aplikasinya sendiri.
Lihat item 4.1.

### 2.5 Pembatalan F&B tidak mengembalikan stok

> *19/08 19.53* — "pembatalan blanja FnB g kembali ke stok awal"

**Status:** SELESAI — 1 hari
**Bukti:** commit `9a4abb6` (2026-08-20) "Kembalikan stok F&B dan keluarkan uangnya
dari rekap saat void". Diuji di `tests/Feature/FnbVoidTest.php` dan `FnbSaleVoidTest.php`.

### 2.6 Koreksi & pembatalan catatan tanggal lampau

> *02/09 11.58* — "aku mau hapus transaksi kemarin. 1 ini uange g masuk. tapi kalo aku ttep nulis ruginya kan 2x / mana listrik, mana bayar arek-arek / tolong hapuskan. sama kasih cara hapusnya ya"

**Status:** SELESAI — 7 hari (fiturnya), 12 hari (cara pakainya)
**Bukti:** commit `58cfc03` (2026-09-09) "Pembukuan bisa mengoreksi & membatalkan catatan
tanggal lampau"; migrasi `2026_09_09_000001_add_edit_trail_to_transactions_table`
(`edited_at`, `edited_by`, `edit_reason`, `edit_count`).
**Bagian "kasih cara hapusnya ya"** dijawab layar Panduan (2026-09-14) — lihat item 4.1.
Di sana ditegaskan juga untuk memakai Batal, bukan mencatat pengeluaran tandingan,
persis kekhawatiran "ruginya kan 2x" yang dia tulis.

### 2.7 Mobil masuk kategori yang salah, harganya kurang 5rb

> *15/09* — "ada momen momen tertentu ketika kasir itu search suatu mobil kek corvette dan calya,
> yang harusnya harganya adalah mobil biasa dengan harga 40k, malah masuk ke mobil kecil dengan
> harga 35k. hal ini menyebabkan kerugian"

**Status:** SELESAI — 3 hari
**Bukti:** migrasi `2026_09_18_000001_add_needs_review_to_vehicles_table`,
`app/Http/Controllers/VehicleController.php`, `app/Http/Controllers/AiVehicleController.php`,
`tests/Feature/VehicleCatalogTest.php` (9 test).

Penelusuran menemukan **dua sebab berbeda**, bukan satu:

1. **Tebakan AI mengalahkan katalog owner.** Kartu AI memakai kategori versi Gemini walaupun
   mobilnya sudah ada di katalog dengan kategori lain — dan tidak ada satu baris pun di layar
   yang menyebut bahwa katalog berkata lain. Terbukti di data produksi: transaksi **#666 Toyota
   Raize** dan **#670 Honda City** (30/08, kasir Rafi) tercatat `kecil` 35rb padahal katalog
   menyebut keduanya `sedang` 40rb. Pemicunya salah ketik satu huruf — `citi`/`raise` memberi
   skor 0,83–0,88, di bawah ambang yakin 0,9, sehingga AI ikut ditanya.
   **Perbaikan:** `AiVehicleController::classify()` memulangkan kategori KATALOG bila mobilnya
   sudah terdaftar; tebakan AI tetap ditampilkan, tapi dicoret dan diberi keterangan.

2. **Katalog kendaraan tidak punya layar sama sekali.** `Toyota Calya` = `kecil` sejak seeder
   pemasangan, dan satu-satunya cara membetulkan adalah SQL manual — sementara kasir justru
   bisa menulis aturan harga baru ke katalog lewat tombol "Simpan ke Database & Pakai".
   **Perbaikan:** Pengaturan → Kendaraan → **Daftar mobil & jenisnya** (tambah/ubah/pindah
   jenis/hapus, owner-only). Kendaraan hasil tebakan AI masuk bertanda `needs_review` dan
   dihitung sebagai angka merah di menu Pengaturan sampai owner membenarkannya.

**Keputusan yang tetap di tangan owner:** daftar mana saja yang sebenarnya "mobil biasa".
Toyota Calya sudah dipindahkan ke `sedang` sesuai keluhan di atas; 22 baris `kecil` lainnya
menunggu owner memeriksa.

### 2.8 Titip jual: uang orang menginap di laci

> *18/09* — permintaan fitur baru, bukan bug.

**Status:** SELESAI
**Bukti:** migrasi `2026_09_18_000002_create_consignment_tables` &
`..._000003_add_consignment_to_products_sales_expenses`,
`app/Services/ConsignmentService.php`, `tests/Feature/ConsignmentTest.php` (14 test).

Bahaya utamanya bukan fiturnya, melainkan **laba yang membesar palsu**. Laba dihitung
`cucian + tip + F&B − upah − pengeluaran`, dan modal barang tidak pernah ikut dikurangi
(untuk barang sendiri itu benar — belanjanya sudah masuk Pengeluaran waktu beli). Kalau
barang titipan lewat jalur F&B yang sama, seluruh harga jual masuk laba, padahal sebagian
itu uang orang. Labanya kelihatan bagus tiap hari, lalu jeblok sekali waktu penitip disetor.

Aturan yang dipasang, dan dijaga tesnya:

| | Kas | Laba | Utang ke penitip |
|---|---|---|---|
| Barang laku Rp 7.000 (setor Rp 5.000) | +7.000 | **+2.000** | +5.000 |
| Setoran ke penitip Rp 5.000 | −5.000 | **0** | lunas |

Hak penitip **dibekukan di baris penjualan** (`fnb_sale_items.consignor_share`) — harga setor
boleh dinegosiasi ulang besok, utang yang terlanjur lahir tidak ikut bergerak. Setoran lahir
sebagai baris Pengeluaran bertanda `is_consignment` supaya saldo buku kas tetap ketemu dengan
uang fisik, tapi tidak memotong laba untuk kedua kalinya.

Dua mode bagi hasil (harga setor tetap / persen), stok titipan hanya bergerak lewat
**barang masuk & retur** supaya "masuk − laku − retur = sisa" bisa dicocokkan bersama
penitipnya, dan penitip tidak bisa dihapus selama masih punya utang atau barang di rak.

---

## 3. Yang terpisah bikin bingung

Pola ketiga: owner tidak kesulitan memakai fiturnya. Dia kesulitan **mencocokkan dua layar**.

### 3.1 Pemisah shift

> *25/07 17.46* — "sama fitur pemisah sift rohan / ini bikin bingung anak-anak sift pagi sama sift malamnya g ketemu transaksinya"

**Status:** SELESAI — 2 hari
**Bukti:** commit `00eba37` (2026-07-27) "rombak shift"; tabel `shifts`,
migrasi `2026_07_27_000010_add_shift_to_transactions_and_fnb_sales`.
Dilanjut `32263c9` (2026-08-10) "Laporan per shift jadi tab".

### 3.2 Transaksi minimarket terpisah dari cucian

> *26/07 19.39* — "ada tambahan minimarket di transaksi cuci mobil karna beberapa kali kepisah, ngecek ya malah bingung"
> *26/07 19.44* — "pajero nyuci abis 40k trs tambah golda 1 jadi total = 44k ... innova nyuci 40k trs tambah golda varian lain = 44k / nah ini bingung aku transaksi 44k yang satu punya siapa yang satunya punya siapa? soalnya kadang ada lagi orang beli golda aja g nyuci"

**Status:** SELESAI
**Bukti:** F&B menempel ke transaksi cuci — migrasi `2026_09_03_000001_add_fnb_and_tip_to_transaction_drafts`;
commit `9b1406f` (2026-08-12) "cari/stok F&B kasir"; `a5284bc` (2026-08-20)
"Pembatalan penjualan F&B, dengan peringatan kalau menempel ke cucian".
**Bentuk akhir:** satu struk = satu kendaraan + item F&B-nya. Pembelian F&B tanpa cuci
tetap bisa berdiri sendiri. Waktu owner mengecek transfer masuk 44k, dia langsung tahu
itu Pajero atau Innova.

### 3.3 Perpindahan shift tidak otomatis

> *04/08 16.26* — "G otomatis pindah shift ini ya?"

**Status:** SELESAI + KEPUTUSAN DESAIN — 8 hari
**Bukti:** commit `9b1406f` (2026-08-12) "Cocokkan jam aplikasi WIB, buku kas pengganti shift".

**Dua hal yang dipisahkan di sini — dan pemisahan ini adalah inti arsitekturnya:**

| | Otomatis? | Alasan |
|---|---|---|
| **Penggolongan transaksi ke shift** | Ya, otomatis by jam | `ShiftService::shiftPada()` menggolongkan tiap transaksi berdasarkan jamnya. Sengaja tidak ikut sakelar penguncian, supaya owner yang tidak memakai penguncian tetap punya rekap per shift. |
| **Serah-terima buku kas (`CashBook`)** | Tidak — manual | Ini serah-terima **uang fisik**. Harus ada manusia yang menghitung laci dan menyatakan jumlahnya. Mengotomatiskan ini berarti aplikasi mengarang angka kas. |

Jawaban untuk pertanyaan Mas Angga: shift-nya pindah sendiri, uangnya tidak — dan memang
tidak boleh. Perlu dikonfirmasi ulang apakah antarmuka sudah menjelaskan perbedaan ini
dengan cukup jelas. Lihat 4.1.

---

## 4. Terbuka

### 4.1 Tidak ada panduan untuk owner

**Status:** SELESAI (2026-09-14) — layar **Panduan** di dalam aplikasi
**Bukti:** `resources/views/kasir/partials/layar-panduan.blade.php`, menu Panduan di drawer.
15 pertanyaan dalam 6 kelompok, tiap jawaban tertutup dulu supaya muat di layar HP.
Dua permintaan yang melahirkannya terjawab langsung: "kasih cara hapusnya ya" (bagian
Membatalkan & mengoreksi) dan "Tip itu masuknya kemana?" (bagian Uang & pembukuan).

Sengaja terbuka untuk semua role, bukan owner-only: kasir justru perlu tahu kenapa
pembatalannya menunggu persetujuan dan kenapa buku kas ditutup manual — kalau tidak,
pertanyaannya tetap lari ke owner, lalu diteruskan ke pengembang.

Panduan ini ikut sendiri ke tiap tenant baru, tidak perlu dikirim terpisah.

*Catatan riwayat — kondisi awal saat backlog ini disusun:*

**Status:** TERBUKA
**Bukti permintaan langsung:** *02/09 11.58* — "sama kasih cara hapusnya ya"
**Bukti tidak langsung:** *02/09 20.50* — "Tip itu masuknya kemana?"
(owner tidak tahu perilaku aplikasinya sendiri sampai bertanya lewat chat)

Seluruh dokumentasi di repo saat ini ditujukan untuk developer (`README.md`, `DEPLOY.md`,
`deploy/*/README.md`). Tidak ada satu pun halaman yang menjelaskan cara memakai aplikasi
kepada owner.

**Kenapa ini pemblokir SaaS, bukan sekadar kekurangan:**
Selama klien cuma satu, kamu adalah dokumentasinya — Mas Angga tinggal WhatsApp dan dijawab.
Model itu tidak bisa dipakai untuk lima klien. Tiap pertanyaan yang hari ini selesai lewat
chat akan menjadi tiket dukungan yang kamu tangani gratis, selamanya.

### 4.2 Fitur AI pencarian kendaraan belum selesai

**Status:** SELESAI (2026-09-14)
**Bukti:** commit `71b27a2` "Satukan AI dengan kolom pencarian, dan pasang batas kuota di server".
Tombol melayang "Tanya AI" dibuang; AI ikut ditanya sendiri dari kolom pencarian saat hasil
lokal kosong atau kurang meyakinkan (skor di bawah `search_confident` 0.9), jawabannya tampil
berdampingan dengan hasil lokal. Throttle `20,1` dipasang di server sebagai jaring terakhir
kuota Gemini, karena permintaan AI kini lahir dari kasir mengetik, bukan dari tombol ditekan.

**Bug lama yang ikut ketahuan:** penangan exception di `bootstrap/app.php` menelan
`HttpException` (turunan `RuntimeException`), sehingga 429, 404, dan `abort(403)` selama ini
semuanya sampai ke kasir sebagai 422. Sudah diperbaiki di commit yang sama.

### 4.3 Deployment masih manual

**Status:** TERBUKA (internal, bukan permintaan owner)
Deploy ke cPanel dikerjakan tangan lewat `deploy/cpanel` + `buat-update.ps1`.
Lima klien = lima cPanel yang diurus manual.

### 4.4 Salah jenis kendaraan lewat grid tidak bisa diaudit

**Status:** TERBUKA (internal, lanjutan dari 2.7)
**575 dari 697 transaksi (82%)** dicatat dengan menekan kotak jenis kendaraan langsung —
"Mobil" 395x, "Mobil Kecil" 107x, "Motor" 71x — tanpa nama kendaraan sama sekali. Salah tap
di jalur itu tidak meninggalkan jejak apa pun untuk dicocokkan, dan perbaikan 2.7 tidak
menyentuhnya. Dua hal yang direncanakan: (a) jalur "Mobil Kecil" meminta nama kendaraan,
(b) laporan owner "cucian di bawah harga standar" — transaksi yang kategorinya berbeda dari
katalog, plus jumlah "Mobil Kecil" per kasir per hari.

---

## Ringkasan angka

| | |
|---|---|
| Permintaan owner tercatat | 16 |
| Selesai | 15 |
| Terbuka | 0 — semua permintaan owner terjawab |
| Selesai di hari yang sama | 2 |
| Selesai dalam ≤ 2 hari | 6 |
| Selang waktu terlama | 8 hari |
| Rentang pengamatan | 12 Juli – 18 September 2026 (~9 minggu) |

*Permintaan tertanggal 12/07 (custom item & stok F&B) dikerjakan sebelum repo ini
mulai dicatat di git pada 20/07, sehingga tidak punya commit penanda.
Hasilnya ada sebagai model `Product`, `FnbSale`, `FnbSaleItem`, dan `ProductSeeder`.*
