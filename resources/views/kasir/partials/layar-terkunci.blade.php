{{-- Ditampilkan JS saat server membalas 423 (di luar jam operasional).
     Tidak ada tombol tutup: kasir memang tidak boleh memakai aplikasi
     sampai jam buka berikutnya, atau sampai owner mengubah jamnya. --}}
<div class="kunci-layar hidden-login" id="layarTerkunci">
  <div class="kunci-kartu">
    <div class="kunci-ikon">&#128274;</div>
    <div class="kunci-judul">Shift Sudah Tutup</div>
    {{-- Pesan yang ditulis owner di Pengaturan. Sembunyi bila belum diisi. --}}
    <div class="kunci-catatan hidden" id="kunciCatatan"></div>
    <div class="kunci-pesan" id="kunciPesan"></div>
    <div class="kunci-jam" id="kunciJam"></div>
    <button class="btn-besar" onclick="cekShiftLagi()">&#128260; Coba Lagi</button>
    {{-- Hanya muncul saat owner memakai tombol Pratinjau di Pengaturan. --}}
    <button class="btn-keluar-kunci hidden" id="kunciPratinjau" onclick="tutupPratinjau()">
      &larr; Tutup pratinjau</button>
    <button class="btn-keluar-kunci" onclick="keluarApp()">Keluar &amp; ganti akun</button>
  </div>
</div>
