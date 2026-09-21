<section id="layarMenuFnb" class="hidden">
      <div class="catatan">
        {{-- Pengaturan dulunya satu gulungan panjang berisi delapan blok:
             untuk mengubah jam shift, owner harus melewati seluruh katalog
             kendaraan dan daftar menu dulu. Sekarang dipecah empat tab, dan
             tab terakhir yang dibuka diingat supaya kembali ke sini tidak
             berarti menggulir dari awal lagi. --}}
        <div class="set-tabs" id="setTabs">
          <button class="set-tab" data-tab="kendaraan" onclick="setTabPengaturan('kendaraan')">
            <span class="set-tab-ikon">&#128663;</span>Kendaraan<span class="set-tab-tanda hidden" id="setTabTandaKendaraan"></span></button>
          <button class="set-tab" data-tab="makanan" onclick="setTabPengaturan('makanan')">
            <span class="set-tab-ikon">&#127860;</span>Makanan</button>
          <button class="set-tab" data-tab="karyawan" onclick="setTabPengaturan('karyawan')">
            <span class="set-tab-ikon">&#128119;</span>Karyawan</button>
          <button class="set-tab" data-tab="shift" onclick="setTabPengaturan('shift')">
            <span class="set-tab-ikon">&#8986;</span>Shift</button>
          <button class="set-tab" data-tab="akun" onclick="setTabPengaturan('akun')">
            <span class="set-tab-ikon">&#128272;</span>Akun</button>
        </div>

        {{-- ================= KENDARAAN ================= --}}
        <div class="set-grup" data-grup="kendaraan">
          <div class="cat-blok">
            <h3>&#128663; Jenis kendaraan</h3>
            <div id="daftarKategori"></div>
            <button class="btn-catat" style="background:var(--go);width:100%;margin-top:10px"
                    onclick="editKategori(null)">&#10133; Tambah Jenis Kendaraan</button>
          </div>

          {{-- Daftar MOBIL-nya, bukan jenisnya. Ini yang dipakai pencarian
               kasir untuk menentukan harga, dan sampai sekarang tidak ada
               layar untuk mengubahnya: isinya ditanam seeder saat pemasangan
               lalu ditambahi tebakan AI dari layar kasir. Akibatnya Toyota
               Calya tetap "Mobil Kecil" walau owner menagihnya sebagai
               "Mobil", dan satu-satunya cara membetulkan adalah SQL. --}}
          <div class="cat-blok">
            <h3>&#128665; Daftar mobil &amp; jenisnya</h3>
            <div class="hint" style="margin-bottom:10px">Yang muncul saat kasir mengetik nama mobil.
              Kalau ada mobil yang harganya salah, pindahkan jenisnya di sini &mdash; berlaku untuk cucian berikutnya.</div>

            {{-- Kendaraan yang masuk dari tebakan AI. Sengaja di atas dan
                 berwarna: inilah baris yang harganya belum pernah diputuskan
                 siapa pun kecuali mesin. --}}
            <div id="kendaraanPerluCek"></div>

            <div class="form-keluar" style="margin-bottom:10px">
              <input id="inKendaraanNama" placeholder="Nama mobil (Toyota Calya...)" maxlength="100">
              <select id="inKendaraanKat" class="pilih-kat"></select>
              <button class="btn-catat" style="background:var(--go)" onclick="tambahKendaraan()">Tambah</button>
            </div>

            <div class="cari" style="margin-bottom:10px">
              <span class="lup">&#128269;</span>
              <input id="cariKendaraan" placeholder="Cari mobil di daftar&hellip;" autocomplete="off"
                     oninput="jadwalCariKendaraan()" aria-label="Cari mobil di katalog">
            </div>
            <div id="daftarKendaraan"></div>
          </div>

          <div class="cat-blok">
            <h3>&#128704; Jenis layanan cuci</h3>
            <div id="daftarLayanan"></div>
            <button class="btn-catat" style="background:var(--go);width:100%;margin-top:10px"
                    onclick="tambahLayanan()">&#10133; Tambah Jenis Layanan</button>
          </div>

          <div class="cat-blok">
            <h3>&#10024; Layanan tambahan (add-on)</h3>
            <div class="form-keluar" style="margin-bottom:10px">
              <input id="inAddonNama" placeholder="Nama (Semir Ban...)" maxlength="60">
              <input id="inAddonHarga" type="number" inputmode="numeric" placeholder="Harga (Rp)">
              <button class="btn-catat" style="background:var(--go)" onclick="tambahAddon()">Tambah</button>
            </div>
            <div id="daftarAddon"></div>
          </div>
        </div>

        {{-- ================= MAKANAN & MINUMAN ================= --}}
        <div class="set-grup" data-grup="makanan">

          {{-- Titip jual ditaruh PALING ATAS, bukan di ekor tab: utang ke
               penitip adalah uang orang lain yang menginap di laci, dan itu
               tidak boleh baru terlihat setelah menggulir lima puluh menu. --}}
          <div class="cat-blok">
            <h3>&#129309; Titip jual <span class="titip-utang-total" id="titipUtangTotal"></span></h3>
            <div class="hint" style="margin-bottom:10px">Barang orang yang dijualkan di sini.
              Uangnya masuk laci seperti biasa, tapi <b>tidak dihitung sebagai laba</b> &mdash;
              yang jadi laba cuma bagian cucian.</div>
            <div id="daftarPenitip"></div>
            <button class="btn-catat" style="background:var(--go);width:100%;margin-top:10px"
                    onclick="editPenitip(null)">&#10133; Tambah Penitip</button>
          </div>

          <div class="cat-blok">
            <h3>&#128203; Tambah menu</h3>
            <div class="form-keluar" style="margin-bottom:6px">
              <input id="inFnbNama" placeholder="Nama (Kopi, Mie...)">
              <input id="inFnbHarga" type="number" inputmode="numeric" placeholder="Harga (Rp)">
              <input id="inFnbStok" type="number" inputmode="numeric" placeholder="Stok awal">
            </div>
            {{-- Pemilik barang. Diisi JS (isiPilihanPenitip) karena daftarnya
                 hidup; "Milik cucian" selalu jadi pilihan pertama supaya alur
                 lama tidak berubah sama sekali bagi yang tidak menerima titipan.
                 Harga setor muncul-sembunyi mengikuti pilihan ini. --}}
            <div class="form-keluar" style="margin-bottom:10px">
              <select id="inFnbPemilik" class="pilih-kat" onchange="gantiPemilikBaru()"></select>
              <input id="inFnbSetor" type="number" inputmode="numeric"
                     placeholder="Harga setor (Rp)" class="hidden">
            </div>
            <div class="bayar-grid" style="margin-bottom:10px">
              <button id="jenisMakanan" class="btn-bayar" onclick="setJenisFnb('makanan')">&#127836; Makanan</button>
              <button id="jenisMinuman" class="btn-bayar aktif" onclick="setJenisFnb('minuman')">&#129380; Minuman</button>
            </div>
            <button class="btn-catat" style="background:var(--go);width:100%" onclick="tambahProduk()">Tambah ke Menu</button>
          </div>

          <div class="cat-blok">
            <h3>Daftar menu</h3>
            <input id="menuCariIn" class="cari-kecil" placeholder="&#128269; Cari nama menu&hellip;"
                   oninput="menuCari=this.value;renderDaftarProduk()">
            <div class="baris-filter">
              <button class="chip-filter aktif" data-f="semua" onclick="setFilterMenu('semua')">Semua</button>
              <button class="chip-filter" data-f="makanan" onclick="setFilterMenu('makanan')">&#127836; Makanan</button>
              <button class="chip-filter" data-f="minuman" onclick="setFilterMenu('minuman')">&#129380; Minuman</button>
            </div>
            <div id="daftarProduk"></div>
          </div>
        </div>

        {{-- ================= KARYAWAN (TRAINING) ================= --}}
        <div class="set-grup" data-grup="karyawan">
          <div class="cat-blok">
            <h3>&#127891; Karyawan training</h3>
            <div id="daftarUpahTraining"></div>
          </div>

          <div class="cat-blok">
            <h3>&#128119; Status pekerja</h3>
            <div id="daftarStatusPekerja"></div>
          </div>
        </div>

        {{-- ================= SHIFT ================= --}}
        <div class="set-grup" data-grup="shift">
          <div class="cat-blok" id="blokShift">
            <h3>&#8986; Jam operasional (shift)</h3>
            {{-- Daftar shift. Satu hari boleh punya berapa pun shift; jam-jamnya
                 diatur di sini, bukan lagi satu pasang jam buka/tutup. --}}
            <div id="daftarShift" style="margin-bottom:10px"></div>
            <button class="btn-catat" style="background:var(--go);width:100%;margin-bottom:12px"
                    onclick="editShift(null)">&#10133; Tambah Shift</button>

            {{-- Peringatan bila penguncian dinyalakan tapi tidak ada shift aktif. --}}
            <div class="shift-waspada hidden" id="shiftWaspada"></div>

            {{-- Pesan bebas dari owner, muncul di layar terkunci milik kasir.
                 Boleh dikosongkan — layar terkunci tetap punya kalimat bawaan. --}}
            <div class="range-input-group" style="margin-bottom:10px">
              <label for="inShiftPesan">Pesan di layar terkunci (opsional)</label>
              <textarea id="inShiftPesan" class="inp-pesan" rows="2" maxlength="200"
                        placeholder="Contoh: Shift udah tutup ya, besok lagi!"
                        oninput="hitungSisaPesan()"></textarea>
              <div class="pesan-sisa" id="shiftPesanSisa"></div>
            </div>
            <button class="btn-hadir" id="tglShiftAktif" style="width:100%;margin-bottom:10px"
                    onclick="toggleShiftAktif()"></button>
            <button class="btn-catat" style="background:var(--go);width:100%" onclick="simpanShift()">Simpan Pengaturan Kunci</button>
            <button class="btn-export" style="margin-top:8px" onclick="pratinjauTerkunci()">&#128065;&#65039; Lihat Pratinjau Layar Terkunci</button>
            <div class="cat-kosong" style="margin-top:8px" id="shiftStatusInfo"></div>
          </div>
        </div>

        {{-- ================= AKUN ================= --}}
        <div class="set-grup" data-grup="akun">
          {{-- Akun owner sendiri. Password lama wajib diisi — sesi yang sudah
               terbuka saja tidak cukup untuk mengambil alih akun ini. --}}
          <div class="cat-blok hidden" id="blokAkunOwner">
            <h3>&#128081; Akun owner</h3>
            <div class="cat-kosong" style="margin-bottom:10px" id="akunOwnerInfo"></div>
            <div class="range-input-group" style="margin-bottom:6px">
              <label for="inOwnerUsername">Username owner</label>
              <input id="inOwnerUsername" class="inp-range" maxlength="64"
                     autocapitalize="none" style="text-transform:none" placeholder="owner">
            </div>
            <div class="range-input-group" style="margin-bottom:6px">
              <label for="inOwnerPasswordBaru">Password baru (kosongkan bila tidak diganti)</label>
              <input id="inOwnerPasswordBaru" type="password" class="inp-range"
                     maxlength="255" placeholder="min. 6 karakter">
            </div>
            <div class="range-input-group" style="margin-bottom:10px">
              <label for="inOwnerPasswordLama">Password owner sekarang (wajib)</label>
              <input id="inOwnerPasswordLama" type="password" class="inp-range"
                     maxlength="255" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;">
            </div>
            <button class="btn-catat" style="background:var(--go);width:100%"
                    onclick="simpanAkunOwner()">Simpan Akun Owner</button>
          </div>

          <div class="cat-blok" id="blokAkunKasir">
            <h3>&#128272; Akun kasir</h3>
            <div id="formAkunKasir">
              <div class="form-keluar" style="margin-bottom:6px">
                <input id="inAkunNama" placeholder="Nama (Budi...)" maxlength="64">
                <input id="inAkunUsername" placeholder="Username" maxlength="64" autocapitalize="none" style="text-transform:none">
              </div>
              <div class="form-keluar" style="margin-bottom:10px">
                <input id="inAkunPassword" type="password" placeholder="Password (min. 4 karakter)" maxlength="255">
                <button class="btn-catat" style="background:var(--go)" onclick="tambahAkunKasir()">Tambah</button>
              </div>
            </div>
            <div id="daftarAkunKasir"></div>
          </div>
        </div>
      </div>
    </section>
