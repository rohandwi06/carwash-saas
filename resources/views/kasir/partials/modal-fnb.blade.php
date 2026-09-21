{{-- Pemilih makanan/minuman untuk cucian yang sedang dikonfirmasi.
     Dipanggil dari tombol "+ Tambah Makanan/Minuman" di layar konfirmasi.

     Jumlah tiap menu langsung tersimpan ke pesanan begitu ditekan — tidak ada
     tombol "Batal" di sini, karena modal ini bukan form terpisah melainkan
     jendela ke daftar menu. Salah tekan dibetulkan lewat tombol minus. --}}
<div class="ai-overlay" id="fnbOverlay" onclick="if(event.target===this)tutupFnbModal()">
  <div class="ai-modal">
    <div class="ai-judul"><span>&#127860; Pilih Makanan &amp; Minuman</span>
      <button class="ai-tutup" onclick="tutupFnbModal()">&#10005;</button></div>

    <input id="fnbModalCari" class="cari-kecil" style="margin-bottom:0"
           placeholder="&#128269; Cari menu&hellip;" autocomplete="off"
           oninput="cariFnbCuci(this.value)">

    <div class="fnb-mini-grid" id="listFnbModal"></div>
    <div class="hal-nav" id="fnbCuciNav"></div>

    <div class="fnb-modal-kaki">
      <div class="fnb-mini-total" id="totalFnbModal"></div>
      <button class="btn-besar" style="margin:0" onclick="tutupFnbModal()">&#10003; Selesai</button>
    </div>
  </div>
</div>
