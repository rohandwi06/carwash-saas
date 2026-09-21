<section id="layarFnb" class="hidden">
      <div class="catatan">
        {{-- Pesanan yang sudah dicatat tapi belum dibayar. Blok ini sembunyi
             sendiri saat tidak ada draft — diisi JS lewat renderFnbDrafts(). --}}
        <div class="cat-blok hidden" id="blokDraftFnb">
          <h3>&#128203; Pesanan belum dibayar <span class="draft-jumlah" id="draftFnbJumlah"></span></h3>
          <div id="daftarDraftFnb"></div>
        </div>

        <div class="cat-blok">
          <h3>&#127860; Pilih item (tap untuk tambah)</h3>
          <div class="fnb-grid" id="gridFnb"></div>
        </div>
        <div class="cat-blok">
          <h3 id="judulKeranjang">&#128722; Pesanan</h3>
          <div id="keranjangFnb"></div>
        </div>
        <div>
          <div class="sec-label">Pembayaran</div>
          <div class="bayar-grid">
            <button id="fnbCash" class="btn-bayar aktif" onclick="setBayarFnb('cash')">&#128181; Cash</button>
            <button id="fnbTf" class="btn-bayar tf" onclick="setBayarFnb('tf')">&#128241; Transfer</button>
          </div>
        </div>
        {{-- Tip DI LUAR total menu: total F&B tetap harga menunya saja, tip
             punya barisnya sendiri di rekap — sama seperti tip cucian. --}}
        <div class="field" style="margin-bottom:12px">
          <label for="inTipFnb">Tip (Rp)</label>
          <input id="inTipFnb" type="number" inputmode="numeric" placeholder="0"
                 style="text-transform:none" oninput="gambarKeranjang()">
        </div>

        <div class="total-bar">
          <div class="tb-label">TOTAL F&amp;B</div>
          <div class="tb-nilai" id="totalFnb">Rp 0</div>
        </div>
        <div class="fnb-tip-info hidden" id="tipFnbInfo"></div>
        <button class="btn-besar" onclick="simpanFnb()">&#10003; Simpan Penjualan</button>
        {{-- Belum bayar: simpan sebagai draft dulu, penjualannya menyusul. --}}
        <button class="btn-draft" id="btnDraftFnb" onclick="simpanDraftFnb()">&#128190; Simpan Draft (belum bayar)</button>
        {{-- Muncul hanya saat sebuah draft sedang dibuka, supaya kasir bisa
             lepas dari draft itu tanpa harus menyimpan atau menghapusnya. --}}
        <button class="btn-draft hidden" id="btnBatalDraftFnb" onclick="batalDraftFnb()">&#10005; Lepas dari draft ini</button>
        <div class="cat-blok">
          <h3>&#129534; Penjualan F&amp;B hari ini</h3>
          <div id="riwayatFnb"></div>
        </div>
      </div>

      <!-- Modal QRIS: muncul saat pembayaran Transfer dipilih -->
      <div class="ai-overlay" id="qrisOverlay" onclick="if(event.target===this)tutupQris()">
        <div class="ai-modal" style="align-items:center;text-align:center">
          <div class="ai-judul" style="width:100%"><span>&#128241; Scan QRIS untuk bayar</span>
            <button class="ai-tutup" onclick="tutupQris()">&#10005;</button></div>
          <img id="qrisGambar" src="/img/qris.png" alt="Kode QRIS"
               style="width:100%;max-width:320px;border-radius:12px;border:2px solid var(--line)"
               onerror="this.outerHTML='<div class=&quot;gagal&quot;>File QRIS belum dipasang.<br>Simpan gambar QRIS toko sebagai <b>public/img/qris.png</b> di folder aplikasi.</div>'">
          <div class="void-ringkas" id="qrisTotal" style="width:100%"></div>
          <button class="btn-catat" style="background:var(--go);width:100%" onclick="tutupQris()">Selesai</button>
        </div>
      </div>
    </section>
