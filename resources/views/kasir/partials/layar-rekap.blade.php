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

        {{-- Empat kartu, sama dengan ringkasan sehari di Pembukuan:
             Uang masuk - Uang keluar = Laba bersih, lalu Setoran cash sendiri
             (hanya uang fisik di laci). Isinya dibuat renderRekap() di kasir.js.
             Transaksi cucian hari ini ada di dalam dropdown Cash/TF/QRIS. --}}
        <div class="cat-blok">
          <h3>&#128176; Uang masuk</h3>
          <div id="rekapCuci"></div>

          <div class="sub-blok">
            <h4>🍔 F&B</h4>
            <div id="rekapFnb"></div>
          </div>

          <div class="sub-blok" id="rekapMasukTotal"></div>
        </div>

        <div class="cat-blok">
          <h3>&#128184; Uang keluar</h3>
          <div id="rekapKeluar"></div>
        </div>

        <div class="cat-blok blok-laba">
          <h3>&#9989; Laba bersih</h3>
          <div id="rekapLaba"></div>
        </div>

        <div class="cat-blok">
          <h3>&#128181; Setoran cash</h3>
          <div id="rekapSetoran"></div>
        </div>
        <div class="stat-grid">
          {{-- Omzet = uang masuk kotor (cucian + F&B + tip), tanpa dikurangi
               pengeluaran; sama dengan baris UANG MASUK di kartu atas. --}}
          {{-- Bisa dibuka: omzet dipecah Cash / TF / QRIS (gambarOmzetRekap
               di kasir.js). --}}
          <div class="stat masuk stat-buka" id="statOmzet" onclick="bukaTutupOmzet()">
            <div class="s-label">Omzet <span class="trx-panah">&#9662;</span></div>
            <div class="s-nilai" id="statMasuk">Rp 0</div>
            <div class="rincian-omzet hidden" id="rincianOmzet"></div>
          </div>
          <div class="stat"><div class="s-label">Transaksi</div><div class="s-nilai" id="statTrx">0</div></div>
          <div class="stat laba"><div class="s-label">Laba Bersih</div><div class="s-nilai" id="statLaba">Rp 0</div></div>
        </div>
        <button class="btn-export" onclick="unduhCSV()">&#128190; Unduh Laporan Hari Ini (CSV)</button>
      </div>
    </section>
