<section id="layarBuku" class="hidden">
      <div class="catatan">
        <div class="cat-blok">
          <div class="kal-nav">
            <button class="kal-panah" onclick="gantiBulan(-1)">&#8249;</button>
            <div class="kal-bulan" id="kalJudul"></div>
            <button class="kal-panah" onclick="gantiBulan(1)">&#8250;</button>
          </div>
          <div class="kal-grid" id="kalGrid"></div>
          {{-- Menggantikan sepasang input tanggal + tombol Cari: rentang
               sekarang dipilih langsung di kalender (tahan lalu ketuk).
               Baris ini yang memberi tahu caranya & rentang yang sedang aktif. --}}
          <div class="kal-info" id="kalInfo"></div>
        </div>

        <div id="detailHari"></div>
        <div id="detailRange" style="display:none"></div>

        <div class="cat-blok">
          <h3 id="judulSummary">&#128200; Bulan ini</h3>
          <div id="totalBulan"></div>
        </div>
      </div>
    </section>
