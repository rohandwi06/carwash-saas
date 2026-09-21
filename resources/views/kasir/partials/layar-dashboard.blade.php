<section id="layarDashboard" class="hidden">
      <div class="catatan">
        <div class="cat-blok">
          <h3>&#128202; Laporan &amp; statistik</h3>
          <div class="baris-filter">
            <button class="chip-filter aktif" data-p="harian"   onclick="setPeriode('harian')">Harian</button>
            <button class="chip-filter"       data-p="mingguan" onclick="setPeriode('mingguan')">Mingguan</button>
            <button class="chip-filter"       data-p="bulanan"  onclick="setPeriode('bulanan')">Bulanan</button>
          </div>
          <div class="cat-kosong" id="statLabel"></div>
          <div class="stat-grid" style="margin-top:12px">
            <div class="stat masuk"><div class="s-label">Omzet</div><div class="s-nilai" id="dashOmzet">Rp 0</div></div>
            <div class="stat"><div class="s-label">Kendaraan</div><div class="s-nilai" id="dashKendaraan">0</div></div>
            <div class="stat"><div class="s-label">Rata-rata omzet</div><div class="s-nilai" id="dashRata">Rp 0</div></div>
            <div class="stat"><div class="s-label">Rata-rata/kendaraan</div><div class="s-nilai" id="dashTiket">Rp 0</div></div>
            <div class="stat laba"><div class="s-label">Laba bersih</div><div class="s-nilai" id="dashLaba">Rp 0</div></div>
          </div>
          <div class="cat-baris" style="margin-top:10px"><span>Periode terbaik</span><b id="dashTerbaik">&mdash;</b></div>
        </div>

        <div class="cat-blok">
          <h3>&#128200; Tren omzet &amp; laba</h3>
          <div class="grafik-box"><canvas id="grafikTren"></canvas></div>
        </div>

        <div class="cat-blok">
          <h3>&#129513; Sumber pemasukan</h3>
          <div class="grafik-box grafik-donat"><canvas id="grafikKomposisi"></canvas></div>
          <div id="dashRincian" style="margin-top:12px"></div>
        </div>

        <div class="cat-blok">
          <h3>&#127942; Layanan terlaris</h3>
          <div id="dashLayanan"></div>
        </div>

        <div class="cat-blok">
          <h3>&#128663; Jenis kendaraan terbanyak</h3>
          <div id="dashKategori"></div>
        </div>

        <div class="cat-blok">
          <h3>&#10024; Layanan tambahan terlaris</h3>
          <div id="dashAddon"></div>
        </div>
      </div>
    </section>
