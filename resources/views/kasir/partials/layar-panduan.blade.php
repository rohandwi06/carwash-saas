{{-- Panduan pemakaian, hidup DI DALAM aplikasi dan bukan sebagai dokumen
     terpisah. Alasannya ada di docs/BACKLOG.md item 4.1: owner tidak akan
     mencari file PDF waktu bingung jam 9 malam — pertanyaannya muncul saat
     dia sedang memegang aplikasinya. Dua permintaan nyata yang melahirkan
     layar ini:
       "sama kasih cara hapusnya ya"        (02/09) -> bagian Membatalkan
       "Tip itu masuknya kemana?"           (02/09) -> bagian Tip

     Sengaja TIDAK owner-only. Kasir justru perlu tahu kenapa pembatalannya
     harus disetujui owner dan kenapa buku kas ditutup manual — dua hal yang
     paling sering ditanyakan ke owner, lalu diteruskan ke pengembang. --}}
<section id="layarPanduan" class="hidden">
      <div class="catatan">

        <div class="cat-blok">
          <h3>&#10067; Panduan Pemakaian</h3>
          <div class="pd-intro">Ketuk pertanyaan untuk membuka jawabannya.
            Tanda <span class="pd-badge">OWNER</span> berarti hanya bisa dilakukan akun owner.</div>
        </div>

        {{-- ---------- SIAPA BOLEH APA ---------- --}}
        <div class="cat-blok">
          <h3>&#128100; Siapa boleh apa</h3>

          <details class="pd-item">
            <summary>Apa bedanya akun owner dan akun kasir?</summary>
            <div class="pd-isi">
              <p>Kasir bisa melayani cucian, menjual makanan/minuman, mencatat pengeluaran,
                 membuka Rekap dan Pembukuan.</p>
              <p>Yang <b>hanya bisa owner</b>: Dashboard (laba bersih &amp; total upah),
                 Pekerja &amp; Upah, Pengaturan (harga, layanan, daftar mobil, menu F&amp;B, stok, titip jual, akun kasir),
                 dan <b>menyetujui pembatalan transaksi</b>.</p>
              <p>Di luar jam shift, akun kasir tidak bisa memakai aplikasi sama sekali.
                 Akun owner tetap bisa masuk kapan saja, termasuk tengah malam.</p>
            </div>
          </details>
        </div>

        {{-- ---------- MEMBATALKAN & MENGOREKSI ---------- --}}
        <div class="cat-blok">
          <h3>&#10060; Membatalkan &amp; mengoreksi</h3>

          <details class="pd-item">
            <summary>Bagaimana cara membatalkan transaksi cucian?</summary>
            <div class="pd-isi">
              <p>Buka <b>Rekap Hari Ini</b> &rarr; cari transaksinya &rarr; tekan tombol
                 <b>Batal</b> di barisnya &rarr; tulis alasannya.</p>
              <p><b>Kalau Anda kasir:</b> transaksinya belum langsung batal. Statusnya jadi
                 <i>menunggu persetujuan</i>, dan owner yang memutuskan.</p>
              <p><b>Kalau Anda owner:</b> langsung batal saat itu juga, tanpa menunggu siapa pun.</p>
            </div>
          </details>

          <details class="pd-item">
            <summary>Kenapa transaksi tidak bisa dihapus permanen?</summary>
            <div class="pd-isi">
              <p>Memang sengaja. Transaksi yang dibatalkan tidak dibuang — dia disimpan dan
                 ditandai batal, lengkap dengan <b>siapa yang membatalkan, kapan, dan alasannya</b>.</p>
              <p>Angkanya sudah keluar dari semua hitungan omzet, upah, dan laba, jadi hasilnya
                 sama saja dengan dihapus. Bedanya: jejaknya masih ada kalau suatu saat perlu dicek.</p>
              <p>Ini yang bikin rekap kemarin tidak pernah berubah diam-diam.</p>
            </div>
          </details>

          <details class="pd-item">
            <summary>Ada catatan salah di tanggal yang sudah lewat. Bisa diperbaiki?</summary>
            <div class="pd-isi">
              <p>Bisa. Buka <b>Pembukuan</b> &rarr; pilih tanggalnya di kalender &rarr;
                 cari catatannya &rarr; <b>Koreksi</b> (kalau angkanya salah) atau
                 <b>Batal</b> (kalau uangnya memang tidak masuk).</p>
              <p>Pakai <b>Batal</b>, bukan mencatat pengeluaran tandingan. Kalau Anda mencatat
                 pengeluaran untuk menutup transaksi yang batal, ruginya terhitung dua kali dan
                 bercampur dengan pengeluaran asli seperti listrik atau upah.</p>
              <p>Tiap koreksi tercatat: siapa yang mengubah, kapan, alasannya, dan sudah berapa
                 kali catatan itu diubah.</p>
            </div>
          </details>

          <details class="pd-item">
            <summary>Saya batalkan penjualan makanan/minuman. Stoknya bagaimana?</summary>
            <div class="pd-isi">
              <p>Kembali sendiri. Barangnya masuk lagi ke stok, dan uangnya keluar dari rekap
                 hari itu. Tidak perlu menambah stok manual.</p>
              <p>Kalau penjualan itu menempel pada sebuah cucian, aplikasi memperingatkan lebih
                 dulu — supaya struk cuciannya tidak ikut kacau tanpa Anda sadari.</p>
            </div>
          </details>
        </div>

        {{-- ---------- UANG ---------- --}}
        <div class="cat-blok">
          <h3>&#128176; Uang &amp; pembukuan</h3>

          <details class="pd-item">
            <summary>Tip masuk ke mana — gaji pekerja atau kas owner?</summary>
            <div class="pd-isi">
              <p><b>Masuk kas owner.</b> Tip sama sekali tidak ikut perhitungan upah pekerja.</p>
              <p>Aplikasi hanya mencatat berapa tip yang masuk. Mau dibagi bagaimana ke pekerja,
                 itu sepenuhnya keputusan owner dan dilakukan di luar aplikasi.</p>
            </div>
          </details>

          <details class="pd-item">
            <summary>Saldo kas kecil itu apa, dan kenapa harus diisi manual tiap pagi?</summary>
            <div class="pd-isi">
              <p>Saldo kas kecil = <b>uang yang benar-benar ada di laci</b> saat buku dibuka pagi itu.
                 Diisi di layar <b>Pengeluaran</b>, sekali tiap buku baru.</p>
              <p>Sengaja tidak dihitung otomatis. Kalau aplikasi menghitungnya sendiri, angkanya
                 akan selalu merasa benar dan tidak pernah bisa dibantah. Dengan diisi manual,
                 angka aplikasi bisa <b>dicocokkan</b> dengan isi laci — dan kalau ada selisih,
                 selisihnya kelihatan.</p>
              <p>Saldo kas kecil tidak menyentuh Omzet maupun Laba Bersih di layar mana pun.
                 Dia murni pelacak uang di tangan.</p>
            </div>
          </details>

          <details class="pd-item">
            <summary>Pengeluaran mengurangi apa?</summary>
            <div class="pd-isi">
              <p>Pengeluaran langsung memotong <b>omzet</b>, jadi angka yang Anda lihat di Rekap
                 sudah bersih. Tidak perlu dikurangi lagi di kepala.</p>
              <p>Pengeluaran masuk ke tanggal yang sedang aktif di layar. Kalau Anda sedang
                 membuka tanggal lain di kalender, catatannya masuk ke tanggal <b>itu</b> —
                 bukan hari ini. Tanggalnya selalu ditulis di atas form.</p>
            </div>
          </details>

          <details class="pd-item">
            <summary>Ada dua transfer masuk dengan jumlah sama. Bagaimana tahu punya siapa?</summary>
            <div class="pd-isi">
              <p>Buka <b>Rekap Hari Ini</b>. Makanan/minuman yang dibeli sambil mencuci
                 <b>menempel pada struk cuciannya</b>, jadi satu baris = satu kendaraan
                 beserta belanjaannya.</p>
              <p>Contoh: Pajero cuci 40rb + Golda 4rb tampil sebagai satu baris 44rb atas nama
                 Pajero. Innova dengan total sama tampil sebagai barisnya sendiri.</p>
              <p>Pembelian makanan/minuman tanpa cuci tetap berdiri sendiri, karena itu memang
                 kejadian yang berbeda.</p>
            </div>
          </details>
        </div>

        {{-- ---------- SHIFT ---------- --}}
        <div class="cat-blok">
          <h3>&#128337; Shift &amp; buku kas</h3>

          <details class="pd-item">
            <summary>Shift pindah otomatis atau harus ditutup manual?</summary>
            <div class="pd-isi">
              <p>Dua hal berbeda, dan ini sering tertukar:</p>
              <p><b>Penggolongan transaksi ke shift &mdash; otomatis.</b> Tiap transaksi masuk ke
                 shift sesuai jamnya sendiri. Anda tidak perlu melakukan apa-apa.</p>
              <p><b>Tutup buku kas &mdash; manual.</b> Ini serah-terima <b>uang fisik</b> antar shift.
                 Harus ada orang yang menghitung laci dan menyatakan jumlahnya. Kalau aplikasi
                 menutupnya sendiri, dia hanya akan mengarang angka kas yang tidak pernah
                 dihitung siapa pun.</p>
              <p>Singkatnya: shiftnya pindah sendiri, uangnya tidak.</p>
            </div>
          </details>

          <details class="pd-item">
            <summary>Kasir bilang layarnya terkunci. Kenapa?</summary>
            <div class="pd-isi">
              <p>Jamnya di luar shift yang aktif. Ini penjaga supaya tidak ada transaksi yang
                 masuk di jam toko sudah tutup.</p>
              <p>Owner mengatur jam shift di <b>Pengaturan</b>, termasuk menyalakan/mematikan
                 penguncian dan menulis pesan yang muncul di layar terkunci.
                 <span class="pd-badge">OWNER</span></p>
              <p>Owner sendiri tidak pernah terkena kunci ini.</p>
            </div>
          </details>
        </div>

        {{-- ---------- PEKERJA ---------- --}}
        <div class="cat-blok">
          <h3>&#128119; Pekerja &amp; upah</h3>

          <details class="pd-item">
            <summary>Upah seorang pekerja kelihatan aneh. Bisa dicek dari mana angkanya?
              <span class="pd-badge">OWNER</span></summary>
            <div class="pd-isi">
              <p>Buka <b>Pekerja &amp; Upah</b> &rarr; ketuk nama pekerjanya. Yang muncul bukan
                 totalnya saja, tapi <b>daftar mobil yang jadi dasar upah itu</b>, satu per satu.</p>
              <p>Jadi kalau ada angka yang tidak wajar, bisa ditelusuri sampai ke transaksi
                 mana yang menyebabkannya.</p>
            </div>
          </details>

          <details class="pd-item">
            <summary>Cara memotong atau mengganti upah seseorang
              <span class="pd-badge">OWNER</span></summary>
            <div class="pd-isi">
              <p>Di <b>Pekerja &amp; Upah</b> ada <b>Potongan</b> (mengurangi upah hari itu) dan
                 <b>Timpa</b> (mengganti angkanya dengan nominal yang Anda tentukan sendiri).</p>
              <p>Keduanya hanya berlaku untuk tanggal tempat Anda mencatatnya, dan bisa dihapus
                 kalau salah. Potongan tidak akan membuat upah jadi minus.</p>
              <p>Pekerja training punya tarif upahnya sendiri, diatur terpisah.</p>
            </div>
          </details>
        </div>

        {{-- ---------- KASIR SEHARI-HARI ---------- --}}
        <div class="cat-blok">
          <h3>&#128663; Kasir sehari-hari</h3>

          <details class="pd-item">
            <summary>Resi pelanggan hilang atau lupa dicetak. Bisa dicetak lagi?</summary>
            <div class="pd-isi">
              <p>Bisa, oleh kasir maupun owner. Buka <b>Rekap Hari Ini</b> (atau <b>Pembukuan</b>
                 untuk tanggal lain) &rarr; ketuk transaksinya &rarr; <b>Lihat Resi</b> &rarr; periksa isinya &rarr; <b>Cetak Resi</b>.</p>
              <p>Untuk jajan tanpa cuci, tekan tombol &#129534; di riwayat
                 <b>Jual Makanan/Minuman</b> hari itu.</p>
              <p>Resi cetak ulang bertuliskan <b>*** SALINAN ***</b>, lengkap dengan jam cetak
                 dan nama yang mencetak — supaya tidak bisa diserahkan seolah transaksi baru.
                 Transaksi yang sudah dibatalkan tidak bisa dicetak lagi.</p>
              <p>Tiap resi punya <b>No. Nota</b> (misalnya C-00557). Nomor yang sama tampil di
                 detail transaksi, jadi transfer bernilai sama bisa dicocokkan ke catatannya.</p>
            </div>
          </details>

          <details class="pd-item">
            <summary>Kendaraannya tidak ketemu waktu dicari</summary>
            <div class="pd-isi">
              <p>Ketik saja apa adanya, salah eja tidak apa-apa. Kalau pencarian tidak menemukan
                 yang cocok, AI ikut ditanya sendiri dan jawabannya muncul di bawah.</p>
              <p>Jawaban AI bisa salah — periksa dulu sebelum dipakai. Kalau sudah benar dan
                 disimpan, kendaraan itu langsung bisa dicari seperti biasa dan AI tidak perlu
                 ditanya lagi lain kali.</p>
              <p>Mobil yang <b>sudah ada di daftar</b> selalu memakai jenis yang ditetapkan owner,
                 bukan tebakan AI. Kalau keduanya berbeda, kartu AI menyebutkannya terang-terangan
                 dan yang dipakai tetap yang dari daftar.</p>
              <p>Kalau memang tidak ada sama sekali, pakai pilihan jenis kendaraan di bawah
                 kolom pencarian.</p>
            </div>
          </details>

          <details class="pd-item">
            <summary>Membetulkan mobil yang jenisnya salah <span class="pd-badge">OWNER</span></summary>
            <div class="pd-isi">
              <p><b>Pengaturan &rarr; Kendaraan &rarr; Daftar mobil &amp; jenisnya.</b> Cari nama mobilnya,
                 lalu ganti jenis di kotak bawah namanya. Berlaku untuk cucian berikutnya;
                 transaksi yang sudah tercatat tidak ikut berubah &mdash; aplikasi memberi tahu
                 ada berapa, dan itu dibetulkan satu per satu di Pembukuan.</p>
              <p>Angka merah di menu <b>Pengaturan</b> = ada mobil yang jenisnya masih tebakan AI
                 dan belum Anda periksa. Selama belum dicek, harganya ditentukan mesin.</p>
            </div>
          </details>

          <details class="pd-item">
            <summary>Menjual barang titipan orang <span class="pd-badge">OWNER</span></summary>
            <div class="pd-isi">
              <p>Barang titipan muncul di layar jual seperti menu lain, cuma tombolnya
                 <b>ungu</b> dan menyebut nama pemiliknya. Kasir tidak perlu berbuat apa-apa
                 yang berbeda &mdash; tap, simpan, selesai.</p>
              <p><b>Uangnya dihitung begini:</b> barang laku Rp 7.000 dengan harga setor
                 Rp 5.000 &rarr; uang di laci bertambah <b>Rp 7.000</b> (kasir tetap menyetor
                 segitu), tapi yang masuk <b>laba cuma Rp 2.000</b>. Rp 5.000 itu uang penitip,
                 dan langsung tercatat sebagai utang ke dia.</p>
              <p>Waktu disetorkan ke penitipnya, uang keluar dari laci dan tercatat di
                 Pengeluaran &mdash; tapi <b>laba tidak berkurang lagi</b>, karena bagian itu
                 memang tidak pernah dihitung sebagai laba sejak awal.</p>
              <p><b>Pengaturan &rarr; Makanan &rarr; Titip jual:</b> tambah penitip, lihat utang
                 berjalan, buka Rincian (masuk / laku / retur / sisa) untuk dicocokkan bersama
                 penitipnya, lalu tekan Setor.</p>
              <p>Stok barang titipan <b>tidak diketik langsung</b>. Pakai tombol sisa di rak
                 pada daftar menu &rarr; <b>Barang masuk</b> waktu penitip menambah, <b>Retur</b>
                 waktu dia mengambil kembali yang belum laku. Itu yang membuat hitungan
                 "masuk dikurangi laku dikurangi retur" selalu bisa dipertanggungjawabkan.</p>
            </div>
          </details>

          <details class="pd-item">
            <summary>Menambah stok makanan/minuman <span class="pd-badge">OWNER</span></summary>
            <div class="pd-isi">
              <p><b>Pengaturan</b> &rarr; menu makanan/minuman. Di sana bisa menambah item baru,
                 mengubah harga, dan mengisi jumlah stok.</p>
              <p>Kasir tidak bisa mengubah stok — dia hanya bisa menjual, dan stoknya berkurang
                 sendiri tiap penjualan.</p>
            </div>
          </details>
        </div>

        <div class="cat-blok">
          <div class="pd-intro">Ada yang belum terjawab di sini? Catat pertanyaannya —
            pertanyaan yang muncul berulang akan ditambahkan ke halaman ini.</div>
        </div>

      </div>
    </section>
