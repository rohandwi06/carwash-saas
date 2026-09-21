<section id="layarConfirm" class="hidden">
      <button class="kembali" onclick="keHome()">&larr; Kembali</button>

      <div class="konfirm-kartu" style="margin:14px 0 18px">
        <div id="konfirmSiluet"></div>
        <div class="kk-nama" id="konfirmNama"></div>
        <div class="kk-kat" id="konfirmKat"></div>
      </div>

      <div id="blokLayanan" style="margin-bottom:18px">
        <div class="sec-label">Pilih layanan</div>
        <div class="layanan-list" id="listLayanan"></div>
      </div>

      <div id="blokAddon" style="margin-bottom:18px">
        <div class="sec-label">Layanan tambahan (opsional, boleh lebih dari satu)</div>
        <div class="addon-list" id="listAddon"></div>
      </div>

      <div style="margin-bottom:18px">
        <div class="sec-label">Dikerjakan oleh (tap nama, bisa lebih dari satu)</div>
        <div class="pk-pilih" id="pilihPekerja"></div>
      </div>

      {{-- Makanan/minuman yang dipesan sambil menunggu cucian. Masuk ke resi
           yang sama, tapi di laporan tetap terhitung sebagai penjualan F&B.

           Di sini hanya yang SUDAH dipilih yang ditampilkan; daftar menunya
           sendiri ada di modal (tombol + di bawah). Layar konfirmasi ini sudah
           panjang — layanan, add-on, pekerja, bayar, plat, tip — jadi grid menu
           yang selalu terbuka membuat tombol Simpan terdorong jauh ke bawah. --}}
      <div id="blokFnbCuci" style="margin-bottom:18px">
        <div class="sec-label">Makanan &amp; minuman (opsional)</div>
        <div class="fnb-pilih-list" id="listFnbCuci"></div>
        <button class="btn-tambah-fnb" onclick="bukaFnbModal()">
          <span class="btf-plus">+</span> <span id="btnTambahFnbTeks">Tambah Makanan/Minuman</span>
        </button>
        <div class="fnb-mini-total hidden" id="totalFnbCuci"></div>
      </div>

      <div style="margin-bottom:18px">
        <div class="sec-label">Pembayaran</div>
        <div class="bayar-grid">
          <button id="btnCash" class="btn-bayar aktif" onclick="setBayar('cash')">&#128181; Cash</button>
          <button id="btnTf" class="btn-bayar tf" onclick="setBayar('tf')">&#128241; Transfer</button>
        </div>
      </div>

      <div class="baris-input" style="margin-bottom:18px">
        <div class="field">
          <label for="inPlat">Plat nomor</label>
          <input id="inPlat" placeholder="n1234ab (boleh kosong)" autocomplete="off"
                 maxlength="20" autocapitalize="characters" oninput="rapikanPlat(this)">
        </div>
        <div class="field">
          <label for="inTip">Tip (Rp)</label>
          <input id="inTip" type="number" inputmode="numeric" placeholder="0" style="text-transform:none">
        </div>
      </div>

      <div class="total-bar" style="margin-bottom:18px">
        <div class="tb-label">TOTAL BAYAR</div>
        <div class="tb-nilai" id="totalBayar"></div>
      </div>

      <button class="btn-besar" onclick="konfirmasi()">&#10003; Simpan Transaksi</button>
      {{-- Belum bayar: simpan sebagai draft dulu, transaksinya menyusul. --}}
      <button class="btn-draft" onclick="simpanDraft()">&#128190; Simpan Draft (belum bayar)</button>
    </section>
