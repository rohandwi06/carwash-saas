<section id="layarBarang" class="hidden">
      <div class="catatan">
        {{-- Barang perlengkapan cuci (sabun, semir, lap) — bukan menu F&B.
             Kasir boleh mencatat pakai/beli/hitung sisa; tombol tambah &
             atur jenis barang hanya muncul untuk owner (renderBarang). --}}
        <div class="cat-blok">
          <h3>&#129532; Stok barang</h3>
          <div class="brg-petunjuk">Sisa sabun, semir, lap, dan perlengkapan lain. Stok turun saat
            dicatat <b>Pakai</b>, saat <b>Hitung sisa</b>, dan otomatis tiap cucian bila takarannya diatur.</div>
          <button class="btn-catat hidden" id="btnBarangBaru" style="background:var(--go);width:100%;margin-top:12px"
                  onclick="aturBarang(null)">+ Barang baru</button>
        </div>
        <div id="daftarBarang"></div>
      </div>
    </section>
