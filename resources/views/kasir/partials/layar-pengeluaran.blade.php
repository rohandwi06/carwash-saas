<section id="layarPengeluaran" class="hidden">
      <div class="catatan">
        {{-- Saldo kas kecil buku yang sedang berjalan. Diisi manual sekali
             tiap buku baru dibuka (biasanya pagi) — MURNI pelacak uang di
             tangan kasir, tidak menyentuh Omzet/Laba Bersih di layar mana
             pun. Sembunyi sendiri kalau tidak ada buku terbuka hari ini
             (mis. sedang melihat tanggal lampau). --}}
        <div class="cat-blok hidden" id="blokSaldoAwal">
          <h3>&#128176; Saldo Kas <span id="saldoBukuLabel"></span></h3>
          <div class="form-keluar" style="margin-bottom:8px">
            <input id="inSaldoAwal" type="number" inputmode="numeric" placeholder="Saldo awal pagi ini (Rp)">
            <button class="btn-catat" style="background:var(--go)" onclick="simpanSaldoAwal()">Simpan</button>
          </div>
          <div id="saldoAwalInfo"></div>
        </div>

        <div class="cat-blok">
          <h3>&#128184; Catat pengeluaran</h3>
          {{-- Tanggal aktif ditegaskan di sini: kalau kasir sedang membuka
               tanggal lain di kalender, catatannya masuk ke tanggal ITU. --}}
          <div class="keluar-tgl-aktif">Dicatat untuk: <b id="keluarTglAktif"></b></div>
          <div class="form-keluar" style="margin-bottom:8px">
            <input id="inKeluarKet" placeholder="Keterangan (beli sabun...)" maxlength="160">
            <input id="inKeluarJumlah" type="number" inputmode="numeric" placeholder="Jumlah (Rp)">
          </div>
          <button class="btn-catat" style="background:var(--go);width:100%" onclick="tambahPengeluaran()">Catat Pengeluaran</button>
        </div>

        <div class="cat-blok">
          <div class="kal-nav">
            <button class="kal-panah" onclick="gantiBulanKeluar(-1)">&#8249;</button>
            <div class="kal-bulan" id="keluarKalJudul"></div>
            <button class="kal-panah" onclick="gantiBulanKeluar(1)">&#8250;</button>
          </div>
          <div class="kal-grid" id="keluarKalGrid"></div>
          <div class="kal-info" id="keluarKalInfo"></div>
        </div>

        {{-- Ringkasan bulan ditaruh TEPAT DI BAWAH kalendernya, bukan di
             paling bawah layar: keduanya menjelaskan bulan yang sama, dan
             kalau dipisah oleh detail harian yang panjang, angka bulanan
             baru terlihat setelah menggulir jauh. --}}
        <div class="cat-blok">
          <h3 id="keluarJudulRingkas">&#128200; Bulan ini</h3>
          <div id="keluarRingkas"></div>
        </div>

        <div id="keluarDetailHari"></div>
        <div id="keluarRangeHasil"></div>
      </div>
    </section>
