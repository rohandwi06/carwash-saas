{{-- Modal QRIS: muncul saat pembayaran QRIS dipilih, di layar konfirmasi cuci
     maupun Jual F&B — pelanggan langsung bisa scan. Sengaja di luar <section>
     mana pun: section yang sedang tidak tampil ikut menyembunyikan isinya.
     Gambar QRIS toko disimpan sebagai public/img/qris.png. --}}
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
