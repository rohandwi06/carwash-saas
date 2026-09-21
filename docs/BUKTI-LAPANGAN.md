# Bukti Lapangan — Apa yang Diajarkan Klien Pertama

Dokumen posisi produk, disusun dari 14 permintaan nyata owner OTIN Carwash
selama 7 minggu pemakaian harian (12 Juli – 2 September 2026).
Rincian per permintaan beserta commit-nya ada di [BACKLOG.md](BACKLOG.md).

Dipakai untuk: menentukan apa yang dijual (bukan sekadar apa yang dibangun),
dan sebagai bahan bukti saat menawarkan ke cucian berikutnya.

---

## Temuan utama

Dari 14 permintaan owner, **tidak satu pun** soal kecepatan aplikasi, tampilan,
atau alur mencuci mobil.

Sebarannya begini:

| Kelompok | Jumlah | Contoh |
|---|---|---|
| Kontrol & kepercayaan karyawan | 5 | "bisa ndak kalo d batalkan ada keterangan oleh siapa" |
| Uang harus ketemu | 6 | "pengeluaran g ngurangin uang cash" |
| Yang terpisah bikin bingung | 3 | "sift pagi sama sift malamnya g ketemu transaksinya" |
| Kecepatan / tampilan / alur cuci | **0** | — |

Dan permintaan yang paling mendesak — dua-duanya dikerjakan **di hari yang sama** —
keduanya dari kelompok kepercayaan, bukan dari kelompok fitur:

> "ini yang batalno karyawanku sndiri rohan / tanpa acc aku" (21/07)
> "pembatalan transaksi oleh karyawan perlu aproval dari owner" (27/07)

Nada permintaannya juga berbeda dari yang lain. Bandingkan:

> "input pengeluaran / lupa kmaren blum ta minta" — santai, bisa menunggu 4 hari
> "**aku kaget** barusan ngecek d cucian, ternyata areke bisa ngehapus juga" — panik

**Kesimpulan:** yang sebenarnya dibeli Mas Angga bukan aplikasi kasir.
Dia membeli **kepastian atas apa yang terjadi di cuciannya waktu dia tidak di sana.**
Kasir hanyalah cara data itu masuk.

---

## Kenapa temuan ini penting untuk jualan

Kalau produk ini diposisikan sebagai "aplikasi kasir cucian mobil", ia bersaing melawan
puluhan POS umum — di lapangan harga dan daftar fitur, yang tidak mungkin dimenangkan
pengembang satu orang.

Kalau diposisikan sebagai **pengawasan pemilik atas bisnis tunai yang tidak bisa dia tunggui**,
pesaingnya hampir tidak ada. POS umum tidak punya alur persetujuan pembatalan, tidak mengunci
kasir di luar jam shift, dan tidak bisa menunjukkan mobil mana saja yang jadi dasar upah
seorang pekerja — karena POS umum dibuat untuk toko di mana pemiliknya berdiri di belakang meja.

Cucian mobil tidak begitu. Pemiliknya datang sore, melihat angka, dan harus percaya.

### Kalimat pembuka yang sudah teruji

Jangan buka dengan daftar fitur. Buka dengan pertanyaan yang jawabannya sudah kamu tahu:

> "Kalau karyawan Bapak membatalkan transaksi jam 9 malam, Bapak tahu dari mana?"

Kalau dia menjawab "ya nggak tahu", kamu tidak perlu menjual apa pun lagi —
tinggal tunjukkan layarnya.

---

## Tiga prinsip desain yang lahir dari lapangan

Ketiganya bukan keputusan teknis yang diambil di depan laptop. Ketiganya dipaksa keluar
oleh kejadian nyata, dan ketiganya harus dibawa utuh ke produk SaaS.

### 1. Data tidak pernah hilang, hanya ditandai batal

**Pemicu:** "aku kaget barusan ngecek d cucian, ternyata areke bisa ngehapus juga" (21/07)

Sejak hari itu tidak ada satu pun jalur hard delete untuk transaksi di seluruh aplikasi.
Pembatalan menyimpan baris aslinya lengkap dengan `voided_by`, `void_reason`, `voided_at`.
Koreksi menyimpan `edited_by`, `edit_reason`, `edit_count` — jadi transaksi yang diubah
tiga kali kelihatan diubah tiga kali.

Efek sampingnya: **rekap kemarin tidak pernah berubah diam-diam.** Ini yang bikin owner
berani memakai angka aplikasi untuk bayar orang.

### 2. Aplikasi mengikuti uang fisik, bukan sebaliknya

**Pemicu:** "pengeluaran ikutin saldo aja nnyi, jadi di aplikasi kita input nominal
sendiri aja 100k / kayak manual kita nginput tiap pagi" (28/08)

Owner menolak saldo kas yang dihitung otomatis dan minta diganti input manual tiap pagi.
Kelihatan seperti kemunduran. Bukan.

Kas kecil adalah uang di laci. Kalau aplikasi menghitungnya sendiri, ia akan selalu benar
menurut dirinya sendiri dan tidak pernah bisa dibantah — persis sifat yang bikin owner
tidak percaya. Dengan input manual, angka aplikasi bisa **dicocokkan** dengan laci,
dan selisihnya kelihatan.

Prinsip: untuk apa pun yang berbentuk uang fisik, aplikasi berperan sebagai pencatat,
bukan penentu. Serah-terima buku kas antar shift juga tetap manual karena alasan yang sama —
harus ada manusia yang menghitung laci.

### 3. Satu kejadian nyata = satu baris di layar

**Pemicu:** "pajero nyuci abis 40k trs tambah golda 1 jadi total = 44k ... innova nyuci 40k
trs tambah golda varian lain = 44k / nah ini bingung aku transaksi 44k yang satu punya siapa" (26/07)

Dua transfer masuk 44.000, dan owner tidak bisa membedakan yang mana punya siapa —
karena di aplikasi cucian dan minimarket tercatat terpisah.

Sekarang satu struk = satu kendaraan beserta item F&B-nya. Pembelian F&B tanpa cuci tetap
bisa berdiri sendiri, karena itu memang kejadian yang berbeda.

Prinsip: pemisahan yang rapi menurut struktur database belum tentu rapi menurut orang yang
sedang mencocokkan mutasi rekening.

---

## Yang didapat klien berikutnya di hari pertama

Ini yang membedakan penawaranmu dari "saya bikinkan aplikasi untuk Bapak" —
klien kedua tidak sedang membiayai percobaan, dia mewarisi tujuh minggu perbaikan
yang sudah dibayar orang lain.

- Pembatalan transaksi mencatat siapa yang membatalkan, kapan, dan alasannya
- Pembatalan oleh kasir perlu persetujuan owner sebelum berlaku
- Tidak ada cara bagi siapa pun untuk menghapus transaksi secara permanen
- Koreksi transaksi tanggal lampau tercatat jejaknya, termasuk berapa kali diubah
- Kasir terkunci di luar jam shift; owner tetap bisa masuk kapan saja
- Rekap upah bisa ditelusuri sampai ke mobil mana saja yang jadi dasarnya
- Upah pekerja training terpisah tarifnya; potongan & koreksi upah ada menunya
- Pengeluaran langsung memotong omzet, tidak perlu dihitung ulang di kepala
- Saldo kas kecil diinput manual tiap pagi sebagai pembanding uang di laci
- Item F&B menempel ke struk cucian, jadi mutasi rekening bisa dicocokkan
- Pembatalan F&B mengembalikan stok dan menarik uangnya dari rekap
- Tip terpisah penuh dari perhitungan upah, jadi pembagiannya tetap hak owner
- Harga, layanan, kategori kendaraan, dan tarif upah diatur sendiri lewat menu Pengaturan

Poin terakhir yang membuat semuanya bisa dijual ulang: sejak awal harga dan tarif upah
sudah pindah dari file konfigurasi ke database dan dikelola owner sendiri. Cucian berikutnya
tidak perlu menunggu developer untuk memasang tarifnya.

---

## Rekam jejak respons

Angka ini bukan fitur, tapi inilah yang sebenarnya dibeli pemilik usaha kecil
saat memilih pengembang perorangan dibanding perusahaan software.

| | |
|---|---|
| Permintaan tercatat | 14 |
| Selesai | 14 — tidak ada yang tersisa |
| Selesai di hari yang sama | 2 |
| Selesai dalam ≤ 2 hari | 6 |
| Selang waktu terlama | 8 hari |

Dua keluhan paling mendesak — pembatalan diam-diam oleh karyawan, dan pembatalan
tanpa persetujuan — keduanya selesai di hari keluhan itu dikirim.

---

## Aplikasi sekarang menjelaskan dirinya sendiri

Dua permintaan terakhir bukan permintaan fitur, melainkan tanda bahwa **owner tidak punya
tempat bertanya selain WhatsApp**:

> "sama kasih cara hapusnya ya" (02/09)
> "Tip itu masuknya kemana?" (02/09) — fiturnya sudah benar sejak 20/08, yang kurang
> cuma tempat bertanya

Keduanya dijawab layar **Panduan** di dalam aplikasi (14/09): 15 pertanyaan dalam 6 kelompok,
terbuka untuk owner maupun kasir.

Kenapa ini penting untuk penjualan berikutnya, bukan sekadar kerapian: selama klien cuma satu,
pengembangnya **adalah** dokumentasi — Mas Angga tinggal WhatsApp dan dijawab. Model itu tidak
bisa diulang untuk lima klien; tiap pertanyaan yang dulu selesai lewat chat akan jadi tiket
dukungan tanpa bayaran, selamanya. Panduan yang hidup di dalam aplikasi ikut sendiri ke tiap
cucian baru tanpa perlu dikirim apa-apa.

Rincian tiap permintaan ada di [BACKLOG.md](BACKLOG.md); yang menghalangi cucian kedua
secara teknis ada di [AUDIT-MULTITENANT.md](AUDIT-MULTITENANT.md).
