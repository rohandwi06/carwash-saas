<section id="layarRekap" class="hidden">
      <div class="catatan">
        {{-- Penyaring BUKU KAS untuk SELURUH layar ini, bukan satu blok saja.
             Ditaruh paling atas seperti tab Pengaturan; jumlah tabnya
             mengikuti berapa buku sudah dibuka hari itu, jadi dibuat di JS.
             Menggantikan shift sebagai satuan laporan — shift sendiri (jam
             operasional yang mengunci kasir) tetap ada, hanya di Pengaturan. --}}
        <div class="set-tabs hidden" id="rekapBukuTabs"></div>
        {{-- Status buku yang sedang dipilih: tombol tutup buku bila masih
             terbuka, atau lencana menunggu/disetor/ditolak. Kosong di tab
             "Semua" — tidak ada satu buku tunggal untuk ditindak di sana. --}}
        <div id="rekapBukuAksi"></div>

        {{-- Hanya owner yang melihat blok ini, dan hanya kalau ada pengajuan
             pembatalan dari kasir yang menunggu keputusan. --}}
        <div class="cat-blok blok-approval hidden" id="blokApproval">
          <h3>&#9888;&#65039; Permintaan pembatalan <span class="draft-jumlah" id="approvalJumlah"></span></h3>
          <div id="daftarApproval"></div>
        </div>

        {{-- Hanya owner, dan hanya kalau ada kasir yang mengajukan setoran
             buku kas yang belum diputuskan. --}}
        <div class="cat-blok blok-approval hidden" id="blokApprovalSetoran">
          <h3>&#128176; Setoran menunggu persetujuan <span class="draft-jumlah" id="approvalSetoranJumlah"></span></h3>
          <div id="daftarApprovalSetoran"></div>
        </div>

        {{-- Tiga laporan dalam SATU kartu, dipisah garis titik-titik.
             Urutannya disengaja: cuci -> F&B -> pengeluaran. Dua yang pertama
             uang masuk, yang ketiga uang keluar sekaligus penutup hitungannya
             (uang masuk - pengeluaran = omzet), jadi angkanya terbaca
             berurutan tanpa berpindah kartu. --}}
        <div class="cat-blok">
          <h3>🚗 Laporan Cuci Mobil/Motor</h3>
          <div id="rekapCuci"></div>

          <div class="sub-blok">
            <h4>🍔 Laporan F&B</h4>
            <div id="rekapFnb"></div>
          </div>

          <div class="sub-blok">
            <h4>&#128184; Laporan Pengeluaran</h4>
            <div id="rekapKeluar"></div>
          </div>
        </div>
        <div class="stat-grid">
          {{-- "Omzet" bukan "Pendapatan": angkanya sudah dikurangi
               pengeluaran, jadi bukan lagi sekadar uang yang masuk. --}}
          <div class="stat masuk"><div class="s-label">Omzet</div><div class="s-nilai" id="statMasuk">Rp 0</div></div>
          <div class="stat"><div class="s-label">Transaksi</div><div class="s-nilai" id="statTrx">0</div></div>
          <div class="stat laba"><div class="s-label">Laba Bersih</div><div class="s-nilai" id="statLaba">Rp 0</div></div>
        </div>
        <div class="cat-blok">
          <h3>&#129534; Riwayat transaksi hari ini</h3>
          <div id="daftarRiwayat"></div>
        </div>
        <button class="btn-export" onclick="unduhCSV()">&#128190; Unduh Laporan Hari Ini (CSV)</button>
      </div>
    </section>
