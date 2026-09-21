<div class="login-layar hidden-login" id="layarLogin">
  <div class="login-kotak">
    <h2>&#128274; OTIN CARWASH</h2>
    <p>Masuk untuk membuka kasir</p>
    <div class="login-err" id="loginErr"></div>
    <input id="inputUsername" type="text" autocomplete="username" autocapitalize="none"
           maxlength="64" placeholder="Username"
           onkeydown="if(event.key==='Enter')document.getElementById('inputPassword').focus()">
    <input id="inputPassword" type="password" autocomplete="current-password"
           maxlength="255" placeholder="Password"
           onkeydown="if(event.key==='Enter')kirimLogin()">
    <button onclick="kirimLogin()">MASUK</button>
  </div>
</div>
