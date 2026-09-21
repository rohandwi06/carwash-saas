<section id="layarPekerja" class="hidden">
      <div class="catatan">
        <div class="cat-blok">
          <h3>&#128119; Pekerja</h3>
          <div class="form-keluar" style="margin-bottom:12px">
            <input id="inNamaPk" placeholder="Nama pekerja baru">
            <button class="btn-catat" style="background:var(--go)" onclick="tambahPekerja()">Tambah</button>
          </div>
          <div id="daftarPekerja"></div>
        </div>

        {{-- Deposit pekerja ke kas. Owner-only: blok ini disembunyikan
             renderDeposit() untuk role lain, dan API-nya pun owner-only. --}}
        <div class="cat-blok hidden" id="blokDeposit">
          <h3>&#128176; Deposit pekerja ke kas</h3>
          <div class="form-keluar" style="margin-bottom:6px">
            <select id="inDepositPekerja" class="inp-range" style="flex:1"></select>
            <input id="inDepositJumlah" type="number" inputmode="numeric" placeholder="Jumlah (Rp)">
          </div>
          <div class="form-keluar" style="margin-bottom:6px">
            <input id="inDepositCatatan" placeholder="Catatan (opsional)" maxlength="160">
            <input id="inDepositTgl" type="date" class="inp-range">
          </div>
          <button class="btn-catat" style="background:var(--go);width:100%;margin-bottom:12px"
                  onclick="tambahDeposit()">&#10133; Catat Deposit</button>

          {{-- Kalender deposit: sama seperti Pembukuan, Upah, dan Pengeluaran.
               Ketuk satu tanggal untuk melihat setoran hari itu, tahan lalu
               ketuk tanggal lain untuk rentang. Menggantikan sepasang input
               tanggal + tombol Cari yang dulu ada di sini. --}}
          <div class="kal-nav">
            <button class="kal-panah" onclick="gantiBulanDeposit(-1)">&#8249;</button>
            <div class="kal-bulan" id="depositKalJudul"></div>
            <button class="kal-panah" onclick="gantiBulanDeposit(1)">&#8250;</button>
          </div>
          <div class="kal-grid" id="depositKalGrid"></div>
          <div class="kal-info" id="depositKalInfo"></div>

          <div id="daftarDeposit" style="margin-top:12px"></div>
        </div>

        {{-- Potongan & koreksi upah. Owner-only sama seperti blok Deposit di
             atas: disembunyikan renderPenyesuaian() untuk role lain, dan
             API-nya pun owner-only. --}}
        <div class="cat-blok hidden" id="blokPenyesuaian">
          <h3>&#9878;&#65039; Potongan &amp; koreksi upah</h3>
          <div class="peny-jenis">
            <button class="btn-jenis aktif" id="jenisPotongan" onclick="setJenisPenyesuaian('potongan')">
              &#10134; Potong upah</button>
            <button class="btn-jenis" id="jenisTimpa" onclick="setJenisPenyesuaian('timpa')">
              &#9998; Timpa angka</button>
          </div>
          <div class="peny-ket" id="penyKet"></div>

          <div class="form-keluar" style="margin-bottom:6px">
            <select id="inPenyPekerja" class="inp-range" style="flex:1"></select>
            <input id="inPenyJumlah" type="number" inputmode="numeric" placeholder="Jumlah (Rp)">
          </div>
          <div class="form-keluar" style="margin-bottom:6px">
            <input id="inPenyAlasan" placeholder="Alasan (telat, merusak alat...)" maxlength="160">
            <input id="inPenyTgl" type="date" class="inp-range">
          </div>
          <button class="btn-catat" style="background:var(--danger);width:100%;margin-bottom:12px"
                  onclick="tambahPenyesuaian()">&#10133; Catat</button>

          <div class="peny-catatan">Uang yang tidak jadi dibayarkan otomatis
            menambah <b>laba bersih</b> hari itu &mdash; laporan memakai upah
            setelah potongan.</div>

          <div id="daftarPenyesuaian" style="margin-top:12px"></div>
        </div>

        {{-- Blok "Upah Pekerja — Hari Ini" dihapus: kalender di bawah sudah
             menampilkan upah hari mana pun, termasuk hari ini, jadi keduanya
             menjawab pertanyaan yang sama dua kali. --}}
        <div class="cat-blok">
          <div class="kal-nav">
            <button class="kal-panah" onclick="gantiBulanUpah(-1)">&#8249;</button>
            <div class="kal-bulan" id="upahKalJudul"></div>
            <button class="kal-panah" onclick="gantiBulanUpah(1)">&#8250;</button>
          </div>
          <div class="kal-grid" id="upahKalGrid"></div>
          <div class="kal-info" id="upahKalInfo"></div>
        </div>

        <div id="upahDetailHari" style="margin-top:0"></div>
        <div id="upahRangeHasil" style="margin-top:0"></div>
      </div>
    </section>
