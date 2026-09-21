{{-- Tidak ada lagi tombol/modal "Tanya AI" terpisah di sini: AI sudah menyatu
     dengan kolom pencarian kendaraan di layar kasir, dan ditanya sendiri saat
     pencarian lokal tidak menemukan apa pun — lihat tanyaAiOtomatis() di
     kasir.js. Kelas .ai-* di bawah tetap dipakai modal pembatalan. --}}
  <div class="ai-overlay" id="voidOverlay" onclick="if(event.target===this)tutupVoid()">
    <div class="ai-modal">
      {{-- Judul, tombol, dan catatan diisi JS: kasir MENGAJUKAN pembatalan,
           owner membatalkan langsung. Lihat voidTrx() di kasir.js. --}}
      <div class="ai-judul"><span>&#10005; <span id="voidJudul">Batalkan transaksi</span></span>
        <button class="ai-tutup" onclick="tutupVoid()">&#10005;</button></div>
      <div class="void-ringkas" id="voidRingkas"></div>
      <div class="ai-form">
        <input id="voidAlasan" placeholder="Alasan (salah input, pelanggan batal...)"
               maxlength="120" autocomplete="off"
               onkeydown="if(event.key==='Enter')kirimVoid()">
      </div>
      <div class="login-err" id="voidErr"></div>
      <button class="btn-catat" style="background:var(--danger);width:100%" id="voidKirim"
              onclick="kirimVoid()">Batalkan</button>
      <div class="ai-catatan" id="voidCatatan"></div>
    </div>
  </div>

  {{-- Pembatalan penjualan F&B. Kembaran modal di atas; peringatan bahwa
       pesanan menempel pada sebuah cucian muncul LEBIH DULU lewat Swal,
       sebelum modal ini terbuka — lihat voidFnb() di kasir.js. --}}
  <div class="ai-overlay" id="voidFnbOverlay" onclick="if(event.target===this)tutupVoidFnb()">
    <div class="ai-modal">
      <div class="ai-judul"><span>&#10005; <span id="voidFnbJudul">Batalkan penjualan</span></span>
        <button class="ai-tutup" onclick="tutupVoidFnb()">&#10005;</button></div>
      <div class="void-ringkas" id="voidFnbRingkas"></div>
      <div class="ai-form">
        <input id="voidFnbAlasan" placeholder="Alasan (salah input, pelanggan batal...)"
               maxlength="120" autocomplete="off"
               onkeydown="if(event.key==='Enter')kirimVoidFnb()">
      </div>
      <div class="login-err" id="voidFnbErr"></div>
      <button class="btn-catat" style="background:var(--danger);width:100%" id="voidFnbKirim"
              onclick="kirimVoidFnb()">Batalkan</button>
      <div class="ai-catatan" id="voidFnbCatatan"></div>
    </div>
  </div>
