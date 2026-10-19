<header class="top">
  <div class="top-isi">
    <div style="display:flex;align-items:center;gap:6px">
      <button class="burger" onclick="bukaMenu()" aria-label="Buka menu">&#9776;</button>
      <div class="brand"><span class="logo">&#128167;</span>@include('kasir.partials.brand')</div>
    </div>
    {{-- Menggantikan chip antrean: sekarang menunjukkan toko buka sampai jam
         berapa. Diisi JS lewat renderChipShift(); disembunyikan bila jam
         operasional tidak diaktifkan owner. --}}
    <div class="shift-chip hidden" id="shiftChip"></div>
  </div>
</header>

{{-- Langganan belum aktif (cucian yang mendaftar sendiri). Pesan netral dari
     server, tanpa harga/tautan; diisi JS lewat muatLangganan(). --}}
<div class="pita-langganan hidden" id="pitaLangganan" role="status"></div>

{{-- Nama halaman yang sedang dibuka — diisi JS lewat tampilkan() --}}
<div class="judul-halaman"><span id="judulHalaman"></span></div>
