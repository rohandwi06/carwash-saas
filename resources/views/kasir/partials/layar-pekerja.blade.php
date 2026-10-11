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

        {{-- Upah & potongan: dulu dua blok (potongan dengan kalendernya, lalu
             kalender upah terpisah di bawah) yang menjawab pertanyaan yang sama
             — uang pekerja per hari. Kini satu kalender untuk semuanya:
             ketuk menambah/membuang satu tanggal (boleh loncat-loncat), tahan
             lalu ketuk tanggal lain menambahkan satu rentang; pilihan bertahan
             saat pindah bulan. Tidak memilih apa pun = hari ini, untuk kartu
             upah maupun Catat. Kartu upah per pekerja bisa diklik: cucian +
             setiap potongan dengan keterangannya.
             Owner-only (layar ini memang khusus owner; API potongan juga). --}}
        <div class="cat-blok hidden" id="blokPenyesuaian">
          <h3>&#128176; Upah &amp; potongan</h3>
          <div class="kal-nav">
            <button class="kal-panah" onclick="gantiBulanPenyesuaian(-1)">&#8249;</button>
            <div class="kal-bulan" id="penyKalJudul"></div>
            <button class="kal-panah" onclick="gantiBulanPenyesuaian(1)">&#8250;</button>
          </div>
          <div class="kal-grid" id="penyKalGrid"></div>
          <div class="kal-legenda">
            <span><span class="kal-titik"></span> ada upah</span>
            <span><span class="kal-titik potong"></span> ada potongan</span>
          </div>
          <div class="kal-info" id="penyKalInfo"></div>

          <div class="peny-sub">Potong / koreksi upah</div>
          <div class="peny-jenis">
            <button class="btn-jenis aktif" id="jenisPotongan" onclick="setJenisPenyesuaian('potongan')">
              &#10134; Potong upah</button>
            <button class="btn-jenis" id="jenisTimpa" onclick="setJenisPenyesuaian('timpa')">
              &#9998; Timpa angka</button>
          </div>
          <div class="peny-ket" id="penyKet"></div>

          {{-- Jumlah di baris sendiri: petunjuknya ("Potong per tanggal") harus
               terbaca utuh, dan di samping dropdown lebarnya tinggal ~85px. --}}
          <div class="form-keluar" style="margin:12px 0 6px">
            <select id="inPenyPekerja" class="inp-range" style="flex:1"
                    onchange="tampilkanUlangPenyesuaian()"></select>
          </div>
          <div class="form-keluar" style="margin-bottom:6px">
            <input id="inPenyJumlah" type="number" inputmode="numeric" placeholder="Jumlah (Rp)"
                   oninput="perbaruiBagiPeny()">
          </div>
          {{-- Potongan = TOTAL yang dibagi rata ke tanggal terpilih; baris ini
               menunjukkan bagian per tanggalnya sebelum dicatat. --}}
          <div class="peny-bagi" id="penyBagi"></div>
          <div class="form-keluar" style="margin-bottom:6px">
            <input id="inPenyAlasan" placeholder="Alasan" maxlength="160">
          </div>
          {{-- Tanggal tujuan Catat, tepat di atas tombolnya. Kalendernya ada jauh
               di atas (dipisah garis & judul), jadi tanpa baris ini form potong
               terbaca seperti "hanya untuk hari ini". --}}
          <div class="peny-tanggal" id="penyTanggal"></div>
          <button class="btn-catat" id="btnPenyCatat" style="background:var(--danger);width:100%;margin-bottom:12px"
                  onclick="tambahPenyesuaian()">&#10133; Catat</button>

          <div class="peny-catatan">Uang yang tidak jadi dibayarkan otomatis
            menambah <b>laba bersih</b> hari itu &mdash; laporan memakai upah
            setelah potongan.</div>

          <div id="daftarPenyesuaian" style="margin-top:16px"></div>
        </div>

        {{-- Kompensasi karyawan: "sangu" dari owner di luar upah cucian (uang
             makan, uang bensin). Memakai tanggal yang dipilih di kalender
             Upah & potongan di atas. Memotong laba bersih di barisnya sendiri;
             tidak menyentuh upah, omzet, maupun setoran cash.
             Owner-only, dibuka oleh renderKompensasi() di kasir.js. --}}
        <div class="cat-blok hidden" id="blokKompensasi">
          <h3>&#127873; Kompensasi karyawan</h3>
          <div class="peny-ket">Uang tambahan dari owner di luar upah cucian, misalnya uang makan
            atau uang bensin. Jumlahnya berlaku <b>per orang, per tanggal</b>.</div>

          <div class="form-keluar" style="margin:12px 0 6px">
            <select id="inKompPekerja" class="inp-range" style="flex:1" onchange="perbaruiTotalKomp()"></select>
          </div>
          <div class="form-keluar" style="margin-bottom:6px">
            <input id="inKompJumlah" type="number" inputmode="numeric" placeholder="Jumlah per orang (Rp)"
                   oninput="perbaruiTotalKomp()">
          </div>
          <div class="form-keluar" style="margin-bottom:6px">
            <input id="inKompAlasan" placeholder="Keterangan (mis. Uang makan)" maxlength="160" list="kompSaran">
            <datalist id="kompSaran"><option value="Uang makan"><option value="Uang bensin"></datalist>
          </div>
          {{-- Total yang akan tercatat = jumlah x orang x tanggal, sebelum disimpan. --}}
          <div class="peny-bagi" id="kompTotal"></div>
          <div class="peny-tanggal" id="kompTanggal"></div>
          <button class="btn-catat" id="btnKompCatat" style="background:var(--go);width:100%;margin-bottom:12px"
                  onclick="tambahKompensasi()">&#10133; Catat kompensasi</button>

          <div class="peny-catatan">Kompensasi memotong <b>laba bersih</b> hari itu di barisnya
            sendiri. Upah, omzet, dan setoran cash kasir tidak berubah &mdash; uangnya dari owner,
            bukan dari laci.</div>

          <div id="daftarKompensasi" style="margin-top:16px"></div>
        </div>
      </div>
    </section>
