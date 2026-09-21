<div class="overlay" id="overlay" onclick="tutupMenu()"></div>
  <nav class="drawer" id="drawer" aria-label="Menu utama">
    <div class="dr-head"><span>OTIN <span class="kuning">CARWASH</span></span>
      <button class="dr-tutup" onclick="tutupMenu()" aria-label="Tutup menu">&#10005;</button>
    </div>
    <div class="dr-list">
      {{-- Dashboard owner-only: isinya laba bersih & total upah pekerja,
           angka yang tidak perlu dilihat kasir. Mulai HIDDEN supaya tidak
           sempat berkelip sebelum terapkanBatasRole() jalan. --}}
      <button class="dr-item hidden" id="drItemDashboard" data-layar="layarDashboard" onclick="pergi('layarDashboard')"><span class="ikon">&#128202;</span> Dashboard</button>
      <div class="dr-sep"></div>
      <button class="dr-item" data-layar="layarHome" onclick="pergi('layarHome')"><span class="ikon">&#128663;</span> Kasir</button>
      <button class="dr-item" data-layar="layarFnb" onclick="pergi('layarFnb')"><span class="ikon">&#127860;</span> Jual Makanan/Minuman</button>
      <div class="dr-sep"></div>
      <button class="dr-item" data-layar="layarRekap" onclick="pergi('layarRekap')"><span class="ikon">&#128218;</span> Rekap Hari Ini</button>
      <button class="dr-item" data-layar="layarPengeluaran" onclick="pergi('layarPengeluaran')"><span class="ikon">&#128184;</span> Pengeluaran</button>
      <button class="dr-item" data-layar="layarBuku" onclick="pergi('layarBuku')"><span class="ikon">&#128197;</span> Pembukuan</button>
      <div class="dr-sep"></div>
      {{-- Pekerja & Upah owner-only: disembunyikan terapkanBatasRole() untuk
           kasir. Mulai HIDDEN supaya tidak sempat berkelip sebelum JS jalan. --}}
      <button class="dr-item hidden" id="drItemPekerja" data-layar="layarPekerja" onclick="pergi('layarPekerja')"><span class="ikon">&#128119;</span> Pekerja &amp; Upah</button>
      {{-- Pengaturan owner-only, sama seperti Pekerja & Upah di atas.
           Tandanya = jumlah kendaraan yang masuk katalog dari tebakan AI dan
           belum dibenarkan owner. Tanpa tanda itu, owner tidak punya alasan
           untuk membuka layar katalog — dan tebakan AI mengendap diam-diam
           jadi harga permanen. --}}
      <button class="dr-item hidden" id="drItemPengaturan" data-layar="layarMenuFnb" onclick="pergi('layarMenuFnb')"><span class="ikon">&#9881;&#65039;</span> Pengaturan<span class="dr-tanda hidden" id="drTandaKatalog"></span></button>
      <div class="dr-sep"></div>
      {{-- Panduan sengaja terbuka untuk SEMUA role: kasir justru perlu tahu
           kenapa pembatalannya menunggu persetujuan owner dan kenapa buku kas
           ditutup manual — kalau tidak, pertanyaannya lari ke owner. --}}
      <button class="dr-item" data-layar="layarPanduan" onclick="pergi('layarPanduan')"><span class="ikon">&#10067;</span> Panduan</button>
    </div>
    <div class="dr-foot">Terhubung ke server OTIN.<br>Login: <b id="roleBadge">-</b> &middot; <a href="#" onclick="keluarApp();return false" style="color:#C0392B;font-weight:900">Keluar</a></div>
  </nav>
