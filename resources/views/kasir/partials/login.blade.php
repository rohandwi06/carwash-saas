<div class="login-layar hidden-login" id="layarLogin">
  <div class="login-kotak">
    <h2>&#128274; <span class="nama-usaha-polos">{{ $usaha['name'] }}</span></h2>
    <p>Masuk untuk membuka kasir</p>
    <div class="login-err" id="loginErr"></div>
    <input id="inputUsername" type="text" autocomplete="username" autocapitalize="none"
           maxlength="64" placeholder="Username"
           onkeydown="if(event.key==='Enter')document.getElementById('inputPassword').focus()">
    <input id="inputPassword" type="password" autocomplete="current-password"
           maxlength="255" placeholder="Password"
           onkeydown="if(event.key==='Enter')kirimLogin()">
    <button onclick="kirimLogin()">MASUK</button>
    {{-- Hanya di situs demo (APP_ENV=demo): calon klien perlu bisa masuk
         tanpa menunggu diberi tahu. Di instalasi cucian sungguhan blok ini
         tidak pernah dirender, jadi password owner tidak ikut tampil. --}}
    @if (app()->environment('demo'))
      {{-- Pengunjung demo belum tahu bedanya owner & kasir, dan password owner
           acak panjang — jadi tiap akun dijelaskan singkat dan bisa diketuk
           untuk mengisi kolom di atas, bukan diketik ulang. --}}
      <div class="login-demo">
        <b>Versi demo</b> &mdash; silakan dicoba sepuasnya. Semua data di sini hanya contoh,
        dipakai bersama pengunjung lain, dan dikembalikan seperti semula tiap malam.
        <div class="akun-demo-judul">Pilih akun, ketuk untuk mengisi otomatis, lalu tekan MASUK:</div>
        <button type="button" class="akun-demo" onclick="isiAkunDemo(this)"
                data-u="{{ config('carwash.owner.username') }}" data-p="{{ config('carwash.owner.password') }}">
          <span class="akun-demo-peran">&#128081; Owner &middot; pemilik usaha</span>
          <span class="akun-demo-ket">Semua yang bisa kasir, ditambah Dashboard (laba &amp; tren),
            upah &amp; potongan pekerja, persetujuan pembatalan, dan pengaturan harga.</span>
          <span class="akun-demo-kunci"><code>{{ config('carwash.owner.username') }}</code> /
            <code>{{ config('carwash.owner.password') }}</code></span>
        </button>
        <button type="button" class="akun-demo" onclick="isiAkunDemo(this)" data-u="dina" data-p="kasir123">
          <span class="akun-demo-peran">&#129534; Kasir &middot; karyawan jaga</span>
          <span class="akun-demo-ket">Catat cucian &amp; jajanan, terima bayar cash/transfer,
            cetak resi. Tidak bisa membuka Dashboard, upah pekerja, maupun pengaturan.</span>
          <span class="akun-demo-kunci"><code>dina</code> / <code>kasir123</code></span>
        </button>
      </div>
      <script>
        function isiAkunDemo(el){
          document.getElementById('inputUsername').value = el.dataset.u;
          document.getElementById('inputPassword').value = el.dataset.p;
          document.getElementById('loginErr').textContent = '';
        }
      </script>
    @endif
  </div>
</div>
