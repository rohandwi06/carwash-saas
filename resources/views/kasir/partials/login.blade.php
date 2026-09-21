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
      <div class="login-demo">
        <b>Versi demo</b> &mdash; silakan dicoba, data dikembalikan tiap malam.<br>
        Owner: <code>{{ config('carwash.owner.username') }}</code> / <code>{{ config('carwash.owner.password') }}</code><br>
        Kasir: <code>dina</code> / <code>kasir123</code>
      </div>
    @endif
  </div>
</div>
