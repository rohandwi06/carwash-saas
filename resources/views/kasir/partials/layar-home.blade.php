<section id="layarHome" class="hidden">
      {{-- Kendaraan yang sudah dicatat tapi belum dibayar. Blok ini sembunyi
           sendiri saat tidak ada draft — diisi JS lewat renderDrafts(). --}}
      <div class="cat-blok hidden" id="blokDraft" style="margin-bottom:18px">
        <h3>&#128203; Belum dibayar <span class="draft-jumlah" id="draftJumlah"></span></h3>
        <div id="daftarDraft"></div>
      </div>

      <div style="margin-bottom:18px">
        <div class="cari">
          <span class="lup">&#128269;</span>
          <input id="inputCari" placeholder="Ketik nama mobil&hellip; salah eja tidak apa-apa" aria-label="Cari nama mobil" autocomplete="off">
        </div>
        <div class="hint">Contoh: ketik <b>penter</b> &rarr; tetap ketemu <b>Panther</b>.
          Boleh rapat tanpa spasi: <b>mazdarx7</b> &rarr; <b>Mazda RX-7</b>.
          Cocokkan gambarnya dengan kendaraan di depan Anda.</div>
      </div>

      <div id="hasilCari" class="hasil" style="margin-bottom:18px"></div>

      {{-- Jawaban AI hidup di kotaknya SENDIRI, bukan menimpa #hasilCari.
           Sebabnya: AI kini ikut ditanya walau pencarian lokal mengembalikan
           hasil, asal hasil itu kurang meyakinkan (mis. "masda tiga" yang cuma
           mengunci kata "mazda" lalu menyodorkan Mazda 2). Kedua daftar harus
           bisa tampil berdampingan supaya kasir yang memilih. --}}
      <div id="hasilAi" style="margin-bottom:18px"></div>

      <div>
        <div class="sec-label" id="labelUkuran">Atau langsung pilih jenis kendaraan</div>
        <div class="grid-ukuran" id="gridUkuran"></div>
      </div>
    </section>
