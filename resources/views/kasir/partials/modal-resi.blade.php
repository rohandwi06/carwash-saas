{{-- Pratinjau resi. Semua jalan menuju resi lewat sini dulu — tombol
     "Lihat Resi" di layar Transaksi Tersimpan, di detail transaksi Rekap &
     Pembukuan, dan di riwayat F&B — baru kemudian "Cetak Resi". Kasir bisa
     memeriksa isinya sebelum kertasnya keluar, dan pelanggan yang cuma ingin
     melihat nota tidak perlu dicetakkan.

     Isi #resiPreview adalah salinan #resi (yang dipakai saat mencetak), jadi
     yang terlihat di sini persis yang akan keluar dari printer. --}}
  <div class="ai-overlay" id="resiOverlay" onclick="if(event.target===this)tutupResi()">
    <div class="ai-modal">
      <div class="ai-judul"><span>&#129534; <span id="resiJudul">Resi</span></span>
        <button class="ai-tutup" onclick="tutupResi()" aria-label="Tutup">&#10005;</button></div>
      <div class="resi-kertas" id="resiPreview"></div>
      <button class="btn-catat" style="background:var(--go);width:100%" onclick="cetakResi()">
        &#128424;&#65039; Cetak Resi</button>
      {{-- Nomor terisi sendiri kalau transaksinya mencatat telepon pelanggan;
           kalau tidak, kasir mengetiknya di sini. --}}
      <div class="resi-wa">
        <input id="resiWaNomor" type="tel" inputmode="tel" autocomplete="off" maxlength="20"
               placeholder="No. WhatsApp pelanggan" aria-label="Nomor WhatsApp pelanggan"
               oninput="rapikanTelepon(this)">
        <button class="btn-catat" onclick="kirimResiWa()">&#128172; Kirim PDF ke WhatsApp</button>
      </div>
      <div class="resi-wa-pesan" id="resiWaPesan"></div>
    </div>
  </div>
