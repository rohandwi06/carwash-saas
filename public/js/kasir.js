/* ============================================================
   OTIN CARWASH — Frontend API client
   Semua data hidup di server Laravel (bukan localStorage).
   ============================================================ */

const API = "/api"; // sama-origin: file ini disajikan dari public/ Laravel

/* Penanda error yang layarnya sudah ditangani sendiri (login / shift tutup),
   supaya gagal() tidak menumpuk alert di atas layar tersebut. */
const ERR_LOGIN = "Belum login.";
const ERR_SHIFT = "Shift tutup.";

let TOKEN = localStorage.getItem("otin_token") || "";
let ROLE  = localStorage.getItem("otin_role")  || "";
let NAMA  = localStorage.getItem("otin_name")  || "";

/* opts.penuh = true -> kembalikan SELURUH badan JSON, bukan cuma .data.
   Dipakai endpoint yang ikut mengirim ringkasan hitungan dari server
   (mis. /worker-deposits dengan 'summary'), supaya angka uang tetap
   dihitung di satu tempat saja dan frontend tidak menjumlahkan ulang.
   Ia dilepas dari opts sebelum diteruskan ke fetch, yang tidak mengenalnya. */

async function api(path, opts = {}) {
  const { penuh, ...fetchOpts } = opts;
  const headers = { "Content-Type": "application/json", "Accept": "application/json" };
  if (TOKEN) headers["Authorization"] = "Bearer " + TOKEN;

  /* --- Menembus WAF shared hosting (ArenHost/LiteSpeed) --------------------
     Aplikasi ini lahir di LAN toko, di mana DELETE dan PATCH lewat begitu
     saja. Begitu pindah ke hosting, mod_security memasang dua aturan yang
     memutus separuh aplikasi SEBELUM Laravel sempat jalan — jadi yang
     kembali bukan JSON melainkan halaman HTML 403, dan kasir cuma melihat
     "Gagal terhubung ke server (403)":

       1. Metode DELETE / PATCH / PUT diblokir seluruhnya.
       2. POST tanpa badan permintaan diblokir (mis. /logout).

     Keduanya diakali di sini, bukan di tiap pemanggil:

       1. DELETE/PATCH/PUT dikirim sebagai POST + header
          X-HTTP-Method-Override. Symfony (dasar Laravel) membaca header itu
          lebih dulu daripada REQUEST_METHOD, jadi rute & controller tetap
          yang semula — tidak ada rute baru yang perlu dibuat.
       2. Badan permintaan tidak pernah kosong: minimal "{}".

     Aman juga di luar hosting: di lokal header ini cuma mengubah POST
     kembali jadi metode yang sama, hasilnya identik. */
  const metode = (fetchOpts.method || "GET").toUpperCase();
  const disamarkan = metode === "DELETE" || metode === "PATCH" || metode === "PUT";
  if (disamarkan) {
    headers["X-HTTP-Method-Override"] = metode;
    fetchOpts.method = "POST";
  }
  const res = await fetch(API + path, {
    headers,
    ...fetchOpts,
    body: metode === "GET" ? undefined : JSON.stringify(opts.body || {}),
  });
  if (res.status === 401) {           // sesi habis / belum login
    simpanSesi("", "");
    tampilkanLogin("Sesi berakhir. Silakan login lagi.");
    throw new Error(ERR_LOGIN);
  }
  if (res.status === 423) {           // di luar jam operasional (kasir saja)
    let j = null;
    try { j = await res.json(); } catch(e){}
    tampilkanTerkunci(j && j.message, j && j.shift);
    throw new Error(ERR_SHIFT);
  }
  if (!res.ok) {
    let msg = "Gagal terhubung ke server ("+res.status+")";
    try { const j = await res.json(); msg = j.message || msg; } catch(e){}
    throw new Error(msg);
  }
  const badan = await res.json();
  return penuh ? badan : badan.data;
}

/* ---------- LOGIN PIN ---------- */
function simpanSesi(token, role, nama){
  TOKEN = token; ROLE = role; NAMA = nama || "";
  token ? localStorage.setItem("otin_token", token) : localStorage.removeItem("otin_token");
  role  ? localStorage.setItem("otin_role", role)   : localStorage.removeItem("otin_role");
  NAMA  ? localStorage.setItem("otin_name", NAMA)   : localStorage.removeItem("otin_name");
  const badge = document.getElementById("roleBadge");
  if (badge) badge.textContent = role ? role.toUpperCase()+(NAMA?" · "+NAMA:"") : "-";
  terapkanBatasRole();
}

/* Layar yang hanya boleh dibuka owner:
   - Dashboard      : laba bersih, total upah pekerja, dan tren omzet —
                      angka pemilik usaha, bukan alat kerja kasir.
   - Pekerja & Upah : upah tiap orang + setoran kas, bukan urusan kasir.
   - Pengaturan     : harga, tarif upah, katalog, jam operasional, dan akun.
                      Seluruh isinya sudah owner-only di server, jadi bagi
                      kasir layar itu cuma deretan tombol yang berbalas 403.
   Ini semata perapian TAMPILAN; penjaga sebenarnya ada di middleware
   'owner' pada routes/api.php, yang tetap menolak walau menu dipaksa muncul
   dari console browser. */
const LAYAR_OWNER = ["layarDashboard", "layarPekerja", "layarMenuFnb"];
const MENU_OWNER  = {layarDashboard: "drItemDashboard",
                     layarPekerja:   "drItemPekerja",
                     layarMenuFnb:   "drItemPengaturan"};
/* Layar pembuka tiap role. Kasir mendarat di Kasir karena Dashboard —
   bekas layar pembuka lama — kini tertutup untuknya. */
function layarAwal(){ return ROLE==="owner" ? "layarDashboard" : "layarHome"; }

function terapkanBatasRole(){
  Object.values(MENU_OWNER).forEach(id => {
    const item = document.getElementById(id);
    if (item) item.classList.toggle("hidden", ROLE !== "owner");
  });
}
function tampilkanLogin(pesan){
  document.getElementById("layarLogin").classList.remove("hidden-login");
  document.getElementById("loginErr").textContent = pesan || "";
  document.getElementById("inputPassword").value = "";
  const inp = document.getElementById("inputUsername");
  inp.value = ""; setTimeout(()=>inp.focus(), 50);
}
async function kirimLogin(){
  const username = document.getElementById("inputUsername").value.trim();
  const password = document.getElementById("inputPassword").value;
  if(!username || !password) return;
  try{
    const r = await api("/login", {method:"POST", body:{username, password}});
    simpanSesi(r.token, r.role, r.name);
    document.getElementById("layarLogin").classList.add("hidden-login");
    // Toast pojok kanan atas — tidak diblokir/await supaya Dashboard tetap
    // langsung terbuka, bukan menunggu alert ditutup dulu.
    Swal.fire({
      toast: true, position: "top-end", icon: "success",
      title: "Login berhasil", text: r.name+" · "+r.role.toUpperCase(),
      showConfirmButton: false, timer: 2500, timerProgressBar: true,
    });
    mulaiAplikasi();
  }catch(e){
    document.getElementById("loginErr").textContent = e.message;
    document.getElementById("inputPassword").value = "";
  }
}
async function keluarApp(){
  try{ await api("/logout", {method:"POST"}); }catch(e){}
  simpanSesi("", "");
  location.reload();
}
function gagal(e){
  // Layar login & layar terkunci sudah muncul sendiri — jangan ditimpa alert.
  if(e.message===ERR_LOGIN || e.message===ERR_SHIFT) return;
  alert("⚠ " + e.message + "\nPeriksa koneksi ke server."); console.error(e);
}

async function konfirmasiHapus(){
  const result = await Swal.fire({
    title: 'Yakin ingin menghapus data?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d33',
    cancelButtonColor: '#3085d6',
    confirmButtonText: 'Ya, Hapus!',
    cancelButtonText: 'Batal'
  });
  return result.isConfirmed;
}

/* ---------- Presentasi kategori ----------
   Bentuk siluet & contoh kendaraan datang dari database (diatur owner di
   Pengaturan), bukan lagi daftar tetap di sini. */
const BENTUK_ADA = ["moto","hatch","mpv","van"];
function bentukKat(kat){
  const s = CFG && CFG.categories[kat] ? CFG.categories[kat].shape : null;
  return BENTUK_ADA.includes(s) ? s : "hatch";
}
function contohKat(kat){
  return (CFG && CFG.categories[kat] && CFG.categories[kat].examples) || "";
}

/* ---------- STATE (cache dari server) ---------- */
let CFG = null;          // {categories, services, wage_rates}
let pekerja = [];        // dari /api/workers
let pilihan = null;      // {nama, kat}
let layananAktif = "reguler";
let metode = "cash";
let pekerjaPilih = new Set(); // worker IDs
let addonPilih = new Set();   // addon IDs
let fnbCuci = {};             // {product_id: qty} — F&B yang dipesan bareng cucian
let draftAktif = null;        // id draft yang sedang dilanjutkan (dihapus server saat tersimpan)
let fnbCuciCari = "";         // pencarian menu F&B di modal pemilih (layar konfirmasi)
let fnbCuciHal = 1;
const FNB_CUCI_PER_HAL = 8;   // 2 kolom x 4 baris — muat di modal tanpa perlu digulir
let trxTerakhir = null;
let SHIFT = null;             // status jam operasional dari /api/shift

/* ---------- SILUET SVG ---------- */
function siluetSVG(shape,size){
  const w=size, h=size*0.55;
  const paths={
    hatch:"M8 44 L12 34 Q14 30 20 29 L30 27 Q40 18 52 18 L66 18 Q76 18 82 26 L88 30 Q94 32 95 38 L96 44 Q96 48 92 48 L86 48 A8 8 0 0 1 70 48 L36 48 A8 8 0 0 1 20 48 L12 48 Q8 48 8 44 Z",
    mpv:"M6 44 L9 34 Q11 29 17 28 L26 26 Q34 15 48 14 L74 14 Q84 14 90 24 L94 30 Q99 33 100 39 L100 44 Q100 48 96 48 L88 48 A8 8 0 0 1 72 48 L36 48 A8 8 0 0 1 20 48 L10 48 Q6 48 6 44 Z",
    van:"M5 44 L6 20 Q6 12 14 12 L88 12 Q95 12 98 20 L102 32 L103 44 Q103 48 99 48 L91 48 A9 9 0 0 1 73 48 L35 48 A9 9 0 0 1 17 48 L9 48 Q5 48 5 44 Z",
  };
  if(shape==="moto"){
    return '<svg width="'+w+'" height="'+h+'" viewBox="0 0 108 54" aria-hidden="true">'
      +'<circle cx="24" cy="42" r="10" fill="none" stroke="#141414" stroke-width="5"/>'
      +'<circle cx="84" cy="42" r="10" fill="none" stroke="#141414" stroke-width="5"/>'
      +'<path d="M24 42 L40 26 Q44 22 50 24 L64 28 L74 20 L80 20 L84 42" fill="none" stroke="#141414" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>'
      +'<path d="M38 27 Q48 12 60 14" fill="none" stroke="#141414" stroke-width="5" stroke-linecap="round"/>'
      +'<path d="M70 21 L64 10 L74 10" fill="none" stroke="#141414" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>'
      +'<rect x="44" y="20" width="18" height="6" rx="3" fill="#FFD34D"/>'
      +'</svg>';
  }
  const windows={
    hatch:'<path d="M34 28 Q42 21 52 21 L64 21 Q72 21 77 27 L34 28 Z"/>',
    mpv:'<rect x="36" y="18" width="16" height="9" rx="2"/><rect x="56" y="18" width="16" height="9" rx="2"/><path d="M76 18 L84 18 Q88 20 90 26 L76 27 Z"/>',
    van:'<rect x="14" y="17" width="16" height="11" rx="2"/><rect x="34" y="17" width="16" height="11" rx="2"/><rect x="54" y="17" width="16" height="11" rx="2"/><path d="M74 17 L88 17 Q92 20 94 28 L74 28 Z"/>',
  };
  const roda2x = shape==="hatch"?78:81;
  return '<svg width="'+w+'" height="'+h+'" viewBox="0 0 108 54" aria-hidden="true">'
    +'<path d="'+paths[shape]+'" fill="#141414"/>'
    +'<g fill="#FFD34D">'+windows[shape]+'</g>'
    +'<circle cx="28" cy="48" r="7.5" fill="#141414" stroke="#FFD34D" stroke-width="3"/>'
    +'<circle cx="'+roda2x+'" cy="48" r="7.5" fill="#141414" stroke="#FFD34D" stroke-width="3"/>'
    +'</svg>';
}

const rp = n => "Rp " + Number(n||0).toLocaleString("id-ID");
const esc = s => String(s??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
/* Untuk string yang ditaruh di dalam onclick="...'DI SINI'..." — lolos dua lapis:
   kutip/backslash untuk JS, lalu esc() untuk atribut HTML-nya. */
const jsStr = s => esc(String(s??"").replace(/\\/g,"\\\\").replace(/'/g,"\\'").replace(/\r?\n/g," "));
const jam = d => new Date(d).toLocaleTimeString("id-ID",{hour:"2-digit",minute:"2-digit"});
const hariIni = () => { const d=new Date(); return d.getFullYear()+"-"+String(d.getMonth()+1).padStart(2,"0")+"-"+String(d.getDate()).padStart(2,"0"); };
/* Tanggal dari server datang sebagai ISO ("2026-07-27T00:00:00.000000Z") —
   yang dipakai di layar hanya bagian tanggalnya. */
const tglSaja = d => String(d||"").slice(0,10);
/* Batas awal & akhir sebuah bulan, format YYYY-MM-DD. */
function batasBulan(tahun, bulan){
  const dua = n => String(n).padStart(2,"0");
  return [
    tahun+"-"+dua(bulan+1)+"-01",
    tahun+"-"+dua(bulan+1)+"-"+dua(new Date(tahun, bulan+1, 0).getDate()),
  ];
}
/* Satu sel tanggal kalender. Dipakai bersama oleh kalender Pembukuan,
   Pekerja & Upah, dan Pengeluaran supaya tampilannya persis sama.
   Tanggalnya ditaruh di data-tgl, bukan onclick — penanganannya terpusat di
   pasangKalender() supaya ketuk-biasa dan tahan-untuk-rentang tidak saling
   mendahului. Hari 'mati' (Pembukuan: tidak ada transaksi) TETAP membawa
   data-tgl: tidak ada yang bisa dibuka di situ, tapi rentang tetap boleh
   dimulai atau diakhiri di hari kosong. */
function selKalender(tgl, angka, opsi){
  const cls = "kal-hari"+(opsi.ada?" ada":"")+(tgl===hariIni()?" ini":"")
    + (opsi.pilih?" pilih":"") + (opsi.mati?" mati":"");
  return '<button class="'+cls+'" data-tgl="'+tgl+'">'+angka
    + (opsi.ada? '<span class="kal-titik"></span>' : '')+'</button>';
}

/* ---------- KALENDER: KETUK SATU HARI, TAHAN UNTUK RENTANG ----------
   Dulu rentang tanggal punya sepasang input <date> + tombol Cari terpisah di
   bawah kalender. Di layar HP itu berarti dua alat untuk satu maksud, dan
   yang bawah menuntut mengetik tanggal padahal kalendernya sudah terpampang.
   Sekarang keduanya jadi satu: TAHAN sebuah tanggal untuk mengunci awal
   rentang, lalu KETUK tanggal lain untuk menutupnya.

   Keadaan tiap kalender disimpan per id grid, jadi Pembukuan, Upah, dan
   Pengeluaran tidak saling mengganggu. */
const KAL = {};
const KAL_TAHAN_MS = 450;   // di bawah ~400ms ketukan biasa mulai terbaca "tahan"

function pasangKalender(gridId, penangan){
  const grid = $(gridId);
  if(!grid) return;
  KAL[gridId] = Object.assign({jangkar:null, dari:null, sampai:null}, KAL[gridId], penangan);

  if(grid.dataset.holdSiap) return;   // delegasi cukup dipasang sekali seumur halaman
  grid.dataset.holdSiap = "1";

  let timer = null, tahanTerjadi = false, selAktif = null;
  const lepas = () => {
    clearTimeout(timer); timer = null;
    if(selAktif){ selAktif.classList.remove("menahan"); selAktif = null; }
  };

  grid.addEventListener("pointerdown", e => {
    const sel = e.target.closest(".kal-hari[data-tgl]");
    if(!sel) return;
    tahanTerjadi = false;
    selAktif = sel; sel.classList.add("menahan");
    timer = setTimeout(() => {
      tahanTerjadi = true;                    // tandai supaya klik sisa diabaikan
      KAL[gridId].jangkar = sel.dataset.tgl;
      KAL[gridId].dari = KAL[gridId].sampai = null;
      lepas();
      tandaiRentang(gridId);
      // Getar singkat: di HP, tanpa umpan balik fisik tidak ada tanda bahwa
      // tahanannya sudah cukup lama dan jarinya boleh diangkat.
      if(navigator.vibrate) navigator.vibrate(30);
    }, KAL_TAHAN_MS);
  });
  ["pointerup","pointerleave","pointercancel"].forEach(ev => grid.addEventListener(ev, lepas));
  // Tahan lama di HP memunculkan menu salin/pilih teks yang menutupi kalender.
  grid.addEventListener("contextmenu", e => { if(e.target.closest(".kal-hari")) e.preventDefault(); });

  grid.addEventListener("click", e => {
    const sel = e.target.closest(".kal-hari[data-tgl]");
    if(!sel) return;
    if(tahanTerjadi){ tahanTerjadi = false; return; }  // ini klik sisa dari tahan tadi

    const st = KAL[gridId], tgl = sel.dataset.tgl;

    if(st.jangkar){
      // Ketuk jangkarnya sendiri = batal, supaya tidak ada jalan buntu ketika
      // rentang terlanjur dimulai di tanggal yang salah.
      if(tgl === st.jangkar){ hapusRentang(gridId); return; }
      const [dari, sampai] = tgl < st.jangkar ? [tgl, st.jangkar] : [st.jangkar, tgl];
      st.jangkar = null; st.dari = dari; st.sampai = sampai;
      tandaiRentang(gridId);
      st.onRange && st.onRange(dari, sampai);
      return;
    }

    if(sel.classList.contains("mati")) return;         // hari kosong: tidak ada yang dibuka

    // Memilih satu hari MENGGANTIKAN rentang, bukan menumpuknya: kalau
    // sorotan rentang lama dibiarkan, kalender memperlihatkan 21-24 tersorot
    // sementara isi layar cuma tanggal 22 — dua jawaban berbeda sekaligus.
    if(st.dari){ st.dari = st.sampai = null; tandaiRentang(gridId); }
    st.onSingle && st.onSingle(tgl);
  });
}

/** Buang rentang/jangkar kalender ini dan kembalikan tampilannya ke semula. */
function hapusRentang(gridId){
  const st = KAL[gridId];
  if(!st) return;
  st.jangkar = st.dari = st.sampai = null;
  tandaiRentang(gridId);
  st.onClear && st.onClear();
}

/**
 * Warnai jangkar & rentang, lalu perbarui baris keterangannya.
 * Dipanggil ulang tiap kalender selesai di-render, karena innerHTML baru
 * menghapus kelas yang sudah dipasang sebelumnya.
 */
function tandaiRentang(gridId){
  const st = KAL[gridId];
  const grid = $(gridId);
  if(!st || !grid) return;

  grid.querySelectorAll(".kal-hari[data-tgl]").forEach(sel => {
    const t = sel.dataset.tgl;
    sel.classList.toggle("jangkar", t === st.jangkar);
    const diDalam = st.dari && st.sampai && t >= st.dari && t <= st.sampai;
    sel.classList.toggle("rentang", !!diDalam && t !== st.dari && t !== st.sampai);
    sel.classList.toggle("rentang-ujung", !!diDalam && (t === st.dari || t === st.sampai));
  });

  const info = $(st.info || "");
  if(!info) return;
  if(st.jangkar){
    info.className = "kal-info menunggu";
    info.innerHTML = "Mulai <b>"+fmtTglPendek(st.jangkar)+"</b> &mdash; ketuk tanggal akhirnya"
      + ' <button class="kal-info-x" onclick="hapusRentang(\''+gridId+'\')">batal</button>';
  }else if(st.dari){
    info.className = "kal-info aktif";
    info.innerHTML = "Rentang <b>"+fmtTglPendek(st.dari)+" &ndash; "+fmtTglPendek(st.sampai)+"</b>"
      + ' <button class="kal-info-x" onclick="hapusRentang(\''+gridId+'\')">&#10005; hapus</button>';
  }else{
    info.className = "kal-info";
    info.innerHTML = "Ketuk tanggal untuk lihat harian &middot; <b>tahan</b> untuk pilih rentang";
  }
}

/** "21 Jul" — cukup untuk baris keterangan yang sempit. */
function fmtTglPendek(t){
  const d = new Date(t+"T00:00:00");
  return d.getDate()+" "+["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"][d.getMonth()];
}
/* Baris nama hari (Sen..Min) di atas grid kalender. */
const kepalaKalender = () =>
  ["Sen","Sel","Rab","Kam","Jum","Sab","Min"].map(h=>'<div class="kal-hdr">'+h+'</div>').join("");
/* Sel kosong sebelum tanggal 1, supaya kolomnya jatuh di hari yang benar. */
function awalanKalender(tahun, bulan){
  const offset = (new Date(tahun, bulan, 1).getDay()+6)%7;
  return '<div class="kal-kosong"></div>'.repeat(offset);
}

/* ---------- PLAT NOMOR ----------
   Kasir cukup ketik "n1234ab" — spasi & huruf besar diurus di sini.
   Pola plat Indonesia: [1-2 huruf wilayah] [1-4 angka] [0-3 huruf].
   Sisa karakter di luar pola tidak dibuang, hanya dipisah spasi. */
function formatPlat(mentah){
  const bersih = String(mentah??"").toUpperCase().replace(/[^A-Z0-9]/g,"");
  const m = bersih.match(/^([A-Z]{0,2})(\d{0,4})([A-Z]{0,3})(.*)$/);
  if(!m) return bersih;
  return [m[1], m[2], m[3], m[4]].filter(Boolean).join(" ");
}
/* Format sambil diketik, kursor tetap di posisi logis (bukan lompat ke akhir). */
function rapikanPlat(el){
  const alnumSebelumKursor = el.value.slice(0, el.selectionStart).replace(/[^A-Za-z0-9]/g,"").length;
  el.value = formatPlat(el.value);
  let n = 0, pos = el.value.length;
  if(alnumSebelumKursor === 0){
    pos = 0;
  }else{
    for(let i=0; i<el.value.length; i++){
      if(el.value[i] !== " ") n++;
      if(n === alnumSebelumKursor){ pos = i+1; break; }
    }
  }
  el.setSelectionRange(pos, pos);
}

/* ---------- NAV ---------- */
const $ = id => document.getElementById(id);
const layarIds = ["layarHome","layarConfirm","layarDone","layarFnb","layarMenuFnb","layarPekerja","layarRekap","layarPengeluaran","layarBuku","layarDashboard","layarPanduan"];
/* Nama halaman yang tampil di pojok kiri atas, di bawah header. */
const JUDUL_LAYAR = {
  layarHome:      "Kasir",
  layarConfirm:   "Detail Cucian",
  layarDone:      "Transaksi Selesai",
  layarFnb:       "Jual Makanan/Minuman",
  layarMenuFnb:   "Pengaturan",
  layarPekerja:   "Pekerja &amp; Upah",
  layarRekap:     "Rekap Hari Ini",
  layarPengeluaran: "Pengeluaran",
  layarBuku:      "Pembukuan",
  layarDashboard: "Dashboard",
  layarPanduan:   "Panduan",
};
function tampilkan(id){
  layarIds.forEach(x => $(x).classList.toggle("hidden", x!==id));
  document.querySelectorAll(".dr-item[data-layar]").forEach(b =>
    b.classList.toggle("aktif", b.dataset.layar===id));
  $("judulHalaman").innerHTML = JUDUL_LAYAR[id] || "";
  // Pindah layar membatalkan tanya-AI yang sedang mengantre: kasir sudah tidak
  // melihat kolom pencarian lagi, jadi jawabannya tidak akan terpakai — dan
  // panggilan yang tidak terpakai tetap memakan kuota.
  if(id!=="layarHome") batalTanyaAi();
  if(id==="layarHome"){ $("inputCari").focus(); renderDrafts(); }
}
/* Sidebar model geser: selain drawer muncul, isi layar (.app) didorong ke kanan. */
function bukaMenu(){
  $("drawer").classList.add("buka");
  $("overlay").classList.add("buka");
  document.querySelector(".app").classList.add("geser");
}
function tutupMenu(){
  $("drawer").classList.remove("buka");
  $("overlay").classList.remove("buka");
  document.querySelector(".app").classList.remove("geser");
}
function pergi(id){
  tutupMenu();
  // Kasir bisa sampai di sini tanpa lewat menu: halaman terakhir tersimpan di
  // localStorage, jadi akun owner yang logout lalu diganti kasir akan
  // membuka layar owner. Dibelokkan ke layar awalnya, bukan dibiarkan kosong.
  if(LAYAR_OWNER.includes(id) && ROLE!=="owner") id = layarAwal();
  if(id==="layarFnb") renderFnb();
  if(id==="layarMenuFnb") renderMenuFnb(true);
  if(id==="layarPekerja"){ initDepositRange(); renderPekerja(); renderUpahKalender(); }
  // Masuk layar Rekap selalu mulai dari "Semua". Penyaring yang tertinggal
  // dari kunjungan sebelumnya membuat owner membaca laba sebagian hari dan
  // mengiranya laba sehari penuh — risiko salah baca angka uang.
  if(id==="layarRekap"){ rekapBukuPilih = null; renderRekap(); }
  if(id==="layarPengeluaran"){
    keluarTgl = hariIni();
    keluarKalTahun = new Date().getFullYear();
    keluarKalBulan = new Date().getMonth();
    $("keluarRangeHasil").innerHTML = "";
    renderPengeluaran();
  }
  if(id==="layarBuku") renderBuku();
  if(id==="layarDashboard") renderDashboard();
  tampilkan(id);
  localStorage.setItem("otinLastPage", id);
}
function keHome(){ tampilkan("layarHome"); }

/* ---------- HOME: grid + search ---------- */
function renderGrid(){
  $("gridUkuran").innerHTML = Object.entries(CFG.categories).map(([key,kt]) =>
    '<button class="btn-ukuran'+(key==="motor"?" motor":"")+'" onclick="pilihManual(\''+key+'\')">'
    + siluetSVG(bentukKat(key),110)
    + '<div class="bu-label">'+esc(kt.label)+'</div>'
    + '<div class="bu-contoh">'+esc(contohKat(key))+'</div>'
    + '<div class="tag bu-harga">'+rp(kt.price)+'</div>'
    + '</button>'
  ).join("");
}

let cariTimer = null;
function jadwalCari(){
  clearTimeout(cariTimer);
  batalTanyaAi();      // tiap huruf baru membatalkan antrean AI dari huruf sebelumnya
  cariTimer = setTimeout(renderHasil, 250); // debounce: jangan hujani server tiap huruf
}
async function renderHasil(){
  const q = $("inputCari").value.trim();
  $("labelUkuran").innerHTML = q ? "Atau pilih jenis secara manual" : "Atau langsung pilih jenis kendaraan";
  if(!q){ $("hasilCari").innerHTML=""; kosongkanAi(); return; }
  try{
    const hasil = await api("/vehicles/search?q="+encodeURIComponent(q));
    if($("inputCari").value.trim()!==q) return; // sudah kadaluarsa
    kosongkanAi();
    if(hasil.length===0){
      $("hasilCari").innerHTML =
        '<div class="gagal">Tidak ketemu "'+esc(q)+'" di daftar kendaraan.'
        + '<br>Pilih jenis kendaraannya di bawah &#128071;</div>';
      jadwalTanyaAi(q);
      return;
    }
    $("hasilCari").innerHTML = hasil.map(m =>
      '<button class="kartu-mobil" onclick="pilihHasil(\''+esc(m.name).replace(/'/g,"\\'")+'\',\''+m.category+'\')">'
      + siluetSVG(bentukKat(m.category),92)
      + '<div class="km-info">'
      +   '<div class="km-nama">'+esc(m.name)+'</div>'
      +   '<div class="km-kat">'+esc(CFG.categories[m.category].label)+'</div>'
      + '</div>'
      + '<div class="tag">'+rp(CFG.categories[m.category].price)+'</div>'
      + '</button>'
    ).join("");

    /* Ada hasil TIDAK otomatis berarti ketemu. Pencarian fuzzy cuma butuh
       kemiripan 0.45, jadi "masda tiga" mengunci kata "mazda" lalu menyodorkan
       Mazda 2 — padahal yang dicari Mazda 3, yang memang tidak ada di katalog.
       Selama syaratnya "ada hasil = jangan tanya AI", kendaraan semacam itu
       tidak akan pernah bisa ditemukan.

       Maka yang dipakai bukan ADA/TIDAKNYA hasil, melainkan seberapa yakin
       hasil teratasnya. Di bawah ambang yakin, AI ikut ditanya sebagai
       pendamping — daftar lokalnya tetap tampil, kasir yang memutuskan. */
    const skorTerbaik = Math.max(...hasil.map(m => Number(m.score) || 0));
    if(skorTerbaik < (CFG.search_confident || 0.9)) jadwalTanyaAi(q);
    else batalTanyaAi();
  }catch(e){ gagal(e); }
}

/* ---------- CONFIRM ---------- */
function resetConfirm(){
  metode="cash";
  pekerjaPilih = new Set();
  addonPilih = new Set();
  fnbCuci = {};
  draftAktif = null;
  fnbCuciCari = ""; fnbCuciHal = 1;   // konfirmasi baru = pencarian F&B kosong lagi
  $("inPlat").value=""; $("inTip").value="";
  tutupFnbModal();   // jangan sampai modal menu ikut terbawa ke cucian berikutnya
}
function pilihHasil(nama, kat){
  pilihan = { nama, kat };
  resetConfirm(); renderConfirm(); tampilkan("layarConfirm");
}
async function pilihManual(kat){
  const q = $("inputCari").value.trim();
  if(q){ api("/vehicles/failed-search",{method:"POST",body:{query:q}}).catch(()=>{}); }
  pilihan = { nama:null, kat };
  resetConfirm(); renderConfirm(); tampilkan("layarConfirm");
}
/* Harga TOTAL satu kategori x layanan. Kalau tidak ada barisnya, berarti
   layanan itu memang tidak tersedia untuk kategori tsb. */
function hargaLayanan(kat, svc){
  const kt = CFG.categories[kat];
  return (kt && kt.prices && kt.prices[svc]!==undefined) ? kt.prices[svc] : null;
}
/** Daftar slug layanan yang tersedia untuk satu kategori, urut sesuai CFG.services. */
function layananTersedia(kat){
  const kt = CFG.categories[kat];
  if(!kt || !kt.prices) return [];
  return Object.keys(CFG.services).filter(id => kt.prices[id]!==undefined);
}
function totalAddon(){
  return (CFG.addons||[]).filter(a=>addonPilih.has(a.id)).reduce((t,a)=>t+a.price, 0);
}
/* Total makanan/minuman yang dipesan bareng cucian ini. */
function totalFnbCuci(){
  return Object.entries(fnbCuci).reduce((t,[id,qty])=>{
    const p = produk.find(x=>x.id==id); return t + (p? p.price*qty : 0);
  },0);
}
function hitungTotalPreview(){
  // angka resmi tetap dihitung server; ini cuma supaya kasir lihat perkiraan
  return (hargaLayanan(pilihan.kat, layananAktif) || 0) + totalAddon() + totalFnbCuci();
}
function renderConfirm(){
  const kt = CFG.categories[pilihan.kat];
  const tersedia = layananTersedia(pilihan.kat);
  // Kategori bisa saja tidak punya layanan yang sedang aktif (mis. baru pindah
  // dari mobil ke motor) — pakai layanan pertama yang tersedia.
  if(!tersedia.includes(layananAktif)) layananAktif = tersedia[0] || "reguler";

  $("konfirmSiluet").innerHTML = siluetSVG(bentukKat(pilihan.kat),170);
  $("konfirmNama").textContent = pilihan.nama || kt.label;
  $("konfirmKat").innerHTML = esc(kt.label)+" &middot; mulai "+rp(kt.price);

  // Cuma satu pilihan layanan? Tidak perlu ditampilkan (mis. motor).
  $("blokLayanan").classList.toggle("hidden", tersedia.length<2);
  if(tersedia.length>1){
    $("listLayanan").innerHTML = tersedia.map(id =>
      '<button class="btn-layanan'+(layananAktif===id?" aktif":"")+'" onclick="pilihLayanan(\''+id+'\')">'
      + '<span class="radio"></span>'
      + '<div style="flex:1"><div class="bl-nama">'+esc(CFG.services[id].label)+'</div></div>'
      + '<div class="bl-tambah">'+rp(hargaLayanan(pilihan.kat,id))+'</div>'
      + '</button>'
    ).join("");
  }

  const addons = CFG.addons || [];
  $("blokAddon").classList.toggle("hidden", addons.length===0);
  $("listAddon").innerHTML = addons.map(a =>
    '<button class="btn-addon'+(addonPilih.has(a.id)?" aktif":"")+'" onclick="toggleAddon('+a.id+')">'
    + '<span class="cek-kotak">'+(addonPilih.has(a.id)?"&#10003;":"")+'</span>'
    + '<span class="ad-nama">'+esc(a.name)+'</span>'
    + '<span class="ad-harga">+'+rp(a.price)+'</span>'
    + '</button>'
  ).join("");

  $("pilihPekerja").innerHTML = pekerja.length===0
    ? '<div class="cat-kosong">Belum ada pekerja. Tambahkan di menu &#9776; &rarr; Pekerja &amp; Upah. (Boleh dilewati)</div>'
    : pekerja.map(p =>
        '<button class="btn-pk'+(pekerjaPilih.has(p.id)?" aktif":"")+'" onclick="togglePk('+p.id+')">'+esc(p.name)+'</button>'
      ).join("");

  gambarFnbCuci();

  $("btnCash").classList.toggle("aktif", metode==="cash");
  $("btnTf").classList.toggle("aktif", metode==="tf");

  $("totalBayar").textContent = rp(hitungTotalPreview());
}

/* F&B di layar konfirmasi cucian dipecah dua:
     - gambarFnbCuci()  : ringkasan yang MENEMPEL di layar konfirmasi, isinya
                          cuma menu yang sudah dipilih + tombol tambah.
     - gambarFnbModal() : daftar menu lengkap, hanya hidup selama modal terbuka.

   Pencarian & halaman disaring di sisi klien (bukan permintaan baru ke
   server): daftar produk sudah termuat utuh untuk layar kasir, jadi
   menyaringnya di sini tidak menambah jeda. Item yang sedang dipilih (qty>0)
   TIDAK ikut hilang kalau kepencar ke halaman lain — kartunya di modal saja
   yang tidak terlihat, jumlahnya tetap tersimpan di `fnbCuci`, tetap tampil di
   ringkasan layar konfirmasi, dan tetap terhitung di totalnya. */
function fnbTersedia(){ return produk.filter(p => p.is_active && p.stock>0); }

function gambarFnbCuci(){
  const semua = fnbTersedia();
  $("blokFnbCuci").classList.toggle("hidden", semua.length===0);
  if(semua.length===0) return;

  // Ringkasan diurutkan mengikuti urutan menu, bukan urutan penekanan: kalau
  // kasir menambah qty menu lama, barisnya tidak melompat ke bawah.
  const dipilih = semua.filter(p => fnbCuci[p.id]);

  $("listFnbCuci").innerHTML = dipilih.map(p => {
    const qty = fnbCuci[p.id];
    return '<div class="fnb-pilih">'
      + '<span class="fp-kiri">'
      +   '<span class="fp-nama">'+esc(p.name)+'</span>'
      +   '<span class="fp-rinci">'+rp(p.price)+' &times; '+qty+'</span>'
      + '</span>'
      + '<span class="fp-atur">'
      +   '<button class="btn-qty" onclick="ubahQtyFnbCuci('+p.id+',-1)">&minus;</button>'
      +   '<b>'+qty+'</b>'
      +   '<button class="btn-qty" onclick="ubahQtyFnbCuci('+p.id+',1)">+</button>'
      + '</span>'
      + '<span class="fp-sub">'+rp(p.price*qty)+'</span>'
      + '</div>';
  }).join("");

  $("btnTambahFnbTeks").textContent = dipilih.length
    ? "Tambah menu lain" : "Tambah Makanan/Minuman";

  const total = totalFnbCuci();
  $("totalFnbCuci").classList.toggle("hidden", total===0);
  $("totalFnbCuci").innerHTML = 'Makanan &amp; minuman: <b>'+rp(total)+'</b>';

  if($("fnbOverlay").classList.contains("buka")) gambarFnbModal();
}

function gambarFnbModal(){
  const semua = fnbTersedia();
  const q = fnbCuciCari.trim().toLowerCase();
  const cocok = q ? semua.filter(p => p.name.toLowerCase().includes(q)) : semua;

  const totalHal = Math.max(1, Math.ceil(cocok.length/FNB_CUCI_PER_HAL));
  if(fnbCuciHal>totalHal) fnbCuciHal = totalHal;
  const mulai = (fnbCuciHal-1)*FNB_CUCI_PER_HAL;
  const potong = cocok.slice(mulai, mulai+FNB_CUCI_PER_HAL);

  $("listFnbModal").innerHTML = potong.length===0
    ? '<div class="cat-kosong">Tidak ada menu yang cocok dengan "'+esc(fnbCuciCari)+'".</div>'
    : potong.map(p => {
        const qty = fnbCuci[p.id]||0;
        return '<div class="fnb-mini'+(qty?" aktif":"")+(p.consignor_id?" titipan":"")+'">'
          + '<button class="fm-utama" onclick="tambahFnbCuci('+p.id+')">'
          +   '<span class="fm-nama">'+esc(p.name)
          +     (p.consignor_id? ' <span class="fm-titip">&#129309; '+esc(namaPenitip(p))+'</span>' : '')
          +   '</span>'
          +   '<span class="fm-harga">'+rp(p.price)+'</span>'
          +   '<span class="fm-stok">Stok '+p.stock+'</span>'
          + '</button>'
          + (qty
              ? '<span class="fm-atur">'
                + '<button class="btn-qty" onclick="ubahQtyFnbCuci('+p.id+',-1)">&minus;</button>'
                + '<b>'+qty+'</b>'
                + '<button class="btn-qty" onclick="ubahQtyFnbCuci('+p.id+',1)">+</button>'
                + '</span>'
              : '')
          + '</div>';
      }).join("");

  $("fnbCuciNav").innerHTML = totalHal<=1 ? "" :
    '<button class="hal-btn" '+(fnbCuciHal<=1?'disabled':'')+' onclick="gantiHalFnbCuci(-1)">&#8249;</button>'
    +'<span class="hal-info">Hal '+fnbCuciHal+' / '+totalHal+' &middot; '+cocok.length+' menu</span>'
    +'<button class="hal-btn" '+(fnbCuciHal>=totalHal?'disabled':'')+' onclick="gantiHalFnbCuci(1)">&#8250;</button>';

  const total = totalFnbCuci();
  $("totalFnbModal").innerHTML = total===0
    ? '<span class="fmt-kosong">Belum ada menu dipilih</span>'
    : 'Makanan &amp; minuman: <b>'+rp(total)+'</b>';
}

function bukaFnbModal(){
  // Buka selalu dari keadaan bersih: pencarian sisa pembukaan sebelumnya
  // menyembunyikan sebagian besar menu tanpa alasan yang jelas bagi kasir.
  fnbCuciCari = ""; fnbCuciHal = 1;
  $("fnbModalCari").value = "";
  $("fnbOverlay").classList.add("buka");
  gambarFnbModal();
}
function tutupFnbModal(){ $("fnbOverlay").classList.remove("buka"); }

function cariFnbCuci(v){ fnbCuciCari = v; fnbCuciHal = 1; gambarFnbModal(); }
function gantiHalFnbCuci(d){ fnbCuciHal += d; gambarFnbModal(); }
function tambahFnbCuci(id){ ubahQtyFnbCuci(id, 1); }
function ubahQtyFnbCuci(id, d){
  const p = produk.find(x=>x.id==id);
  if(!p) return;
  const next = (fnbCuci[id]||0)+d;
  if(d>0 && next>p.stock){
    Swal.fire({icon:"warning", title:"Stok tidak cukup", text:"Sisa stok "+p.name+": "+p.stock, confirmButtonColor:"#1B9E62"});
    return;
  }
  if(next<=0) delete fnbCuci[id]; else fnbCuci[id]=next;
  renderConfirm();   // ikut menyegarkan modal kalau sedang terbuka
}
function toggleAddon(id){
  if(addonPilih.has(id)) addonPilih.delete(id); else addonPilih.add(id);
  renderConfirm();
}
function pilihLayanan(id){ layananAktif=id; renderConfirm(); }
function setBayar(m){ metode=m; renderConfirm(); }
function togglePk(id){
  if(pekerjaPilih.has(id)) pekerjaPilih.delete(id); else pekerjaPilih.add(id);
  renderConfirm();
}

/* ---------- SIMPAN TRANSAKSI (server yang hitung total & antrian) ---------- */
async function konfirmasi(){
  const kt = CFG.categories[pilihan.kat];
  const btns = document.querySelectorAll("#layarConfirm .btn-besar");
  btns.forEach(b=>b.disabled=true);
  try{
    const body = {
      vehicle_name: pilihan.nama || kt.label,
      category: pilihan.kat,
      service: layananAktif,
      payment_method: metode,
      plate: formatPlat($("inPlat").value) || null,
      tip: Math.max(0, parseInt($("inTip").value,10)||0),
      worker_ids: Array.from(pekerjaPilih),
      addon_ids: Array.from(addonPilih),
      fnb_items: Object.entries(fnbCuci).map(([id,qty])=>({product_id:+id, qty})),
    };
    if(draftAktif) body.draft_id = draftAktif; // draft ikut terhapus di server
    const trx = await api("/transactions", { method:"POST", body });
    draftAktif = null; // draftnya sudah tidak ada — jangan dipakai lagi
    trxTerakhir = trx;
    const namaAddon = (trx.addons||[]).map(a=>esc(a.pivot? a.pivot.name : a.name)).join(", ");
    const totalSemua = totalTrx(trx);
    $("doneRingkas").innerHTML =
      esc(trx.vehicle_name)+" &middot; "+esc(trx.plate || "plat kosong")+"<br>"
      + (trx.payment_method==="cash"?"Cash":"Transfer")+" &mdash; <b>"+rp(totalSemua)+"</b>"
      + (trx.tip? " + tip "+rp(trx.tip) : "")
      + (namaAddon? "<br><span class='waktu'>+ "+namaAddon+"</span>" : "")
      + (itemFnb(trx).length? "<br><span class='waktu'>&#127860; "+itemFnb(trx).map(i=>esc(i.product_name)+" x"+i.qty).join(", ")+"</span>" : "");
    tampilkan("layarDone");
    produk = await api("/products?active=1"); // stok sudah berkurang di server
  }catch(e){ gagal(e); }
  finally{ btns.forEach(b=>b.disabled=false); }
}
/* Semua item F&B yang menempel pada satu transaksi cuci. */
function itemFnb(trx){
  return (trx.fnb_sales||[]).flatMap(s => s.items||[]);
}
/* Cucian + add-on (trx.total) + makanan/minuman yang menempel. */
function totalTrx(trx){
  return (trx.total||0) + (trx.fnb_sales||[]).reduce((t,s)=>t+(s.total||0), 0);
}
/* ---------- RESI ----------
   Satu pembuat resi untuk dua jenis nota: cucian (beserta makanan/minuman
   yang menempel) dan penjualan F&B yang berdiri sendiri. Dipakai tepat
   setelah transaksi tersimpan, dan untuk CETAK ULANG dari Rekap, Pembukuan,
   atau riwayat F&B — resi yang dicetak ulang diberi tanda SALINAN.

   Nama usaha sengaja dikumpulkan di satu tempat: kelak diambil dari
   pengaturan tiap cucian (docs/AUDIT-MULTITENANT.md temuan 1.1). */
const RESI_USAHA = {nama: "OTIN CARWASH", sub: "Cuci Mobil &amp; Motor"};

/* Nomor nota = id catatannya, diberi awalan jenis. Cucian dan F&B hidup di
   tabel berbeda dengan urutan id masing-masing, jadi awalan itulah yang
   membedakan C-00733 dari F-00733. Nomor yang sama tampil di detail Rekap,
   supaya dua transfer bernilai sama bisa dicocokkan ke catatannya. */
function nomorNota(jenis, id){ return jenis+"-"+String(id).padStart(5, "0"); }

function barisResi(kiri, kanan, kelas){
  return '<div class="r-baris'+(kelas?" "+kelas:"")+'"><span>'+kiri+'</span><span>'+kanan+'</span></div>';
}
const GARIS_RESI = '<div class="r-garis"></div>';

/* Isi resi sebuah transaksi cuci. Harga cucian dasar tidak disimpan
   terpisah: total transaksi = cucian + add-on (TransactionService::create),
   jadi cuciannya adalah sisanya. Tanpa baris ini resi hanya merinci add-on
   dan F&B, dan totalnya tidak cocok dengan rinciannya sendiri. */
function dataResiCucian(trx){
  const kt = CFG.categories[trx.category];
  const sv = CFG.services[trx.service];
  const layanan = trx.category==="motor" ? "Cuci Motor" : (sv? sv.label : trx.service);
  const addons = trx.addons || [];
  const hargaAddon = a => a.pivot? a.pivot.price : a.price;
  const cuci = (trx.total||0) - addons.reduce((t,a)=>t+hargaAddon(a), 0);
  return {
    nomor: nomorNota("C", trx.id),
    waktu: trx.created_at,
    atas: barisResi("Kendaraan", esc(trx.vehicle_name))
        + barisResi("Plat", esc(trx.plate || "-"))
        // Kasir yang langsung memilih jenis (tanpa mencari nama mobil) membuat
        // nama kendaraannya sama dengan jenisnya — jangan ditulis dua kali.
        + ((kt && kt.label !== trx.vehicle_name)? barisResi("Jenis", esc(kt.label)) : ""),
    rincian: barisResi(esc(layanan), rp(cuci))
        + addons.map(a=>barisResi("+ "+esc(a.pivot?a.pivot.name:a.name), rp(hargaAddon(a)))).join("")
        + itemFnb(trx).map(i=>barisResi(esc(i.product_name)+" x"+i.qty, rp(i.subtotal))).join(""),
    belanja: totalTrx(trx),
    // F&B yang menempel selalu tersimpan dengan tip 0 (tipnya ada di
    // transaksi cuci) — dijumlahkan juga supaya tetap benar kalau suatu saat berubah.
    tip: (trx.tip||0) + (trx.fnb_sales||[]).reduce((t,s)=>t+(s.tip||0), 0),
    bayar: trx.payment_method,
    kasir: trx.created_by,
    catatan: "Simpan resi untuk ambil kendaraan",
  };
}
/* Isi resi penjualan makanan/minuman tanpa cuci. */
function dataResiFnb(sale){
  return {
    nomor: nomorNota("F", sale.id),
    waktu: sale.created_at,
    atas: "",
    rincian: (sale.items||[]).map(i=>barisResi(esc(i.product_name)+" x"+i.qty, rp(i.subtotal))).join(""),
    belanja: sale.total || 0,
    tip: sale.tip || 0,
    bayar: sale.payment_method,
    kasir: sale.created_by,
    catatan: "",
  };
}
/**
 * @param salinan true untuk cetak ulang. Resi lama yang dicetak lagi tanpa
 *   tanda bisa diserahkan seolah transaksi baru — tanda SALINAN, jam cetak
 *   ulang, dan nama pencetaknya menutup celah itu.
 */
function isiResi(d, salinan){
  const waktu = new Date(d.waktu || Date.now());
  const sekarang = new Date();
  $("resi").innerHTML =
    '<div class="r-tengah r-judul">'+RESI_USAHA.nama+'</div>'
    +'<div class="r-tengah">'+RESI_USAHA.sub+'</div>'
    +(salinan? '<div class="r-tengah r-salinan">*** SALINAN ***</div>' : '')
    +GARIS_RESI
    +barisResi("No. Nota", d.nomor)
    +barisResi(waktu.toLocaleDateString("id-ID"), jam(waktu))
    +GARIS_RESI
    +(d.atas? d.atas+GARIS_RESI : '')
    +d.rincian
    +GARIS_RESI
    // Dengan tip, TOTAL ditegaskan sudah termasuk tip lewat baris Subtotal &
    // Tip di atasnya — sebelumnya tip ditulis di BAWAH total dan pelanggan
    // tidak bisa tahu yang dibayar itu total saja atau total + tip.
    +(d.tip? barisResi("Subtotal", rp(d.belanja)) + barisResi("Tip", rp(d.tip)) : '')
    +barisResi("TOTAL", rp(d.belanja + (d.tip||0)), "r-besar")
    +barisResi("Bayar", d.bayar==="cash" ? "CASH" : "TRANSFER")
    +(d.kasir? barisResi("Kasir", esc(d.kasir)) : '')
    +GARIS_RESI
    +(salinan
        ? '<div class="r-tengah">Dicetak ulang '+sekarang.toLocaleDateString("id-ID")+' '+jam(sekarang)
          +'<br>oleh '+esc(NAMA || "-")+'</div>'+GARIS_RESI
        : '')
    +'<div class="r-tengah">Terima kasih!</div>'
    +(d.catatan? '<div class="r-tengah">'+d.catatan+'</div>' : '');
}
/* ---------- PRATINJAU & CETAK ----------
   Dua langkah: "Lihat Resi" membuka pratinjau, baru dari sana "Cetak Resi".
   Resi yang sedang dipratinjau disimpan di resiAktif supaya saat dicetak
   #resi diisi ulang — jam "Dicetak ulang" pada salinan jadi jam cetak yang
   sebenarnya, bukan jam pratinjau dibuka. */
let resiAktif = null;

function lihatResi(data, salinan){
  resiAktif = {data, salinan: !!salinan};
  isiResi(data, salinan);
  $("resiPreview").innerHTML = $("resi").innerHTML;
  $("resiJudul").textContent = "Resi "+data.nomor+(salinan? " · salinan" : "");
  $("resiOverlay").classList.add("buka");
}
function tutupResi(){ $("resiOverlay").classList.remove("buka"); }
function cetakResi(){
  if(resiAktif) isiResi(resiAktif.data, resiAktif.salinan);
  window.print();
}

/* Layar Transaksi Tersimpan: resi asli, bukan salinan. */
function lihatResiTerakhir(){
  if(trxTerakhir) lihatResi(dataResiCucian(trxTerakhir));
}
/* Dari baris yang sedang tampil — datanya sudah ada di layar (trxTampil /
   penjualanFnb), jadi tidak perlu menembak server lagi. Selalu salinan:
   transaksinya sudah lewat, resi aslinya pernah (atau semestinya) keluar. */
function lihatResiCucian(id){
  const r = trxTampil.get(id);
  if(r) lihatResi(dataResiCucian(r), true);
}
function lihatResiFnb(id){
  const s = penjualanFnb.find(x=>x.id===id);
  if(s) lihatResi(dataResiFnb(s), true);
}
function transaksiBaru(){
  pilihan=null; $("inputCari").value=""; $("hasilCari").innerHTML=""; kosongkanAi();
  tampilkan("layarHome");
}

/* ---------- DRAFT: catat kendaraan dulu, bayar belakangan ----------
   Draft hidup di server supaya tidak hilang saat tablet ditutup dan bisa
   dilanjutkan kasir lain. Harga sengaja TIDAK disimpan — baru dikunci
   server saat draft benar-benar jadi transaksi. */
let drafts = [];

async function renderDrafts(){
  try{
    drafts = await api("/drafts");
  }catch(e){
    if(e.message===ERR_LOGIN || e.message===ERR_SHIFT) return;
    drafts = [];
  }
  const blok = $("blokDraft");
  if(!blok) return;
  blok.classList.toggle("hidden", drafts.length===0);
  $("draftJumlah").textContent = drafts.length ? drafts.length : "";
  $("daftarDraft").innerHTML = drafts.map(d => {
    const kt = CFG && CFG.categories[d.category];
    const pk = (d.worker_ids||[]).map(id => {
      const w = pekerja.find(x=>x.id===id); return w? w.name : null;
    }).filter(Boolean).join(", ");
    return '<div class="draft-baris">'
      + '<button class="draft-utama" onclick="lanjutDraft('+d.id+')">'
      +   '<span class="draft-nama">'+esc(d.vehicle_name)+'</span>'
      +   '<span class="draft-sub">'+esc(d.plate || "plat kosong")
      +     (kt? ' &middot; '+esc(kt.label) : '')
      +     (pk? ' &middot; &#128119; '+esc(pk) : '')
      +     (jmlFnbDraft(d)? ' &middot; &#127860; '+jmlFnbDraft(d)+' item' : '')
      +     (d.tip? ' &middot; tip '+rp(d.tip) : '')+'</span>'
      +   (d.note? '<span class="draft-note">&#128221; '+esc(d.note)+'</span>' : '')
      +   '<span class="draft-jam">'+jam(d.created_at)+(d.created_by? ' &middot; '+esc(d.created_by):'')+'</span>'
      + '</button>'
      + '<button class="btn-hapus-pk" title="Hapus draft" onclick="hapusDraft('+d.id+')">&#10005;</button>'
      + '</div>';
  }).join("");
}

/* Berapa porsi makanan/minuman yang menempel pada satu draft cucian —
   dipakai baris daftar draft supaya kasir tahu pesanannya ikut tersimpan. */
function jmlFnbDraft(d){
  return (d.fnb_items||[]).reduce((t,i)=>t+(i.qty||0), 0);
}

async function simpanDraft(){
  const kt = CFG.categories[pilihan.kat];
  const body = {
    vehicle_name: pilihan.nama || kt.label,
    category: pilihan.kat,
    service: layananAktif,
    plate: formatPlat($("inPlat").value) || null,
    worker_ids: Array.from(pekerjaPilih),
    addon_ids: Array.from(addonPilih),
    // Makanan/minuman & tip ikut disimpan persis seperti saat transaksi
    // dikonfirmasi (lihat konfirmasi()). Tanpa dua baris ini, apa yang sudah
    // diketik kasir hilang begitu ditekan "Simpan Draft".
    fnb_items: Object.entries(fnbCuci).map(([id,qty])=>({product_id:+id, qty})),
    tip: Math.max(0, parseInt($("inTip").value,10)||0),
  };
  try{
    // Draft yang sedang dibuka cukup diperbarui, jangan sampai jadi dua baris.
    if(draftAktif) await api("/drafts/"+draftAktif,{method:"PATCH",body});
    else           await api("/drafts",{method:"POST",body});
    transaksiBaru();
    await renderDrafts();
    Swal.fire({toast:true, position:"top-end", icon:"success",
      title: draftAktif? "Draft diperbarui" : "Tersimpan sebagai draft",
      showConfirmButton:false, timer:2000});
  }catch(e){ gagal(e); }
}

function lanjutDraft(id){
  const d = drafts.find(x=>x.id===id);
  if(!d) return;
  pilihan = { nama: d.vehicle_name, kat: d.category };
  resetConfirm();
  draftAktif   = d.id;
  layananAktif = d.service || "reguler";
  pekerjaPilih = new Set(d.worker_ids || []);
  addonPilih   = new Set(d.addon_ids || []);
  $("inPlat").value = d.plate || "";
  $("inTip").value  = d.tip ? d.tip : "";
  // Menu yang sudah dihapus/dinonaktifkan sejak draft dibuat tidak bisa
  // dijual lagi — dilewati dan disebutkan, bukan diam-diam dibuang.
  // Perlakuan yang sama dengan lanjutDraftFnb().
  fnbCuci = {};
  let hilangFnb = 0;
  (d.fnb_items||[]).forEach(it => {
    const p = produk.find(x=>x.id===it.product_id);
    if(!p){ hilangFnb++; return; }
    fnbCuci[p.id] = it.qty;
  });
  renderConfirm();
  tampilkan("layarConfirm");
  if(hilangFnb){
    Swal.fire({icon:"warning", title:"Sebagian menu tidak ada lagi",
      text:hilangFnb+" item makanan/minuman di draft ini sudah dihapus dari daftar menu dan tidak ikut dimuat.",
      confirmButtonColor:"#1B9E62"});
  }
}

async function hapusDraft(id){
  if(!await konfirmasiHapus()) return;
  try{ await api("/drafts/"+id,{method:"DELETE"}); await renderDrafts(); }catch(e){ gagal(e); }
}

/* ---------- PEKERJA & UPAH ---------- */
async function renderPekerja(){
  try{
    pekerja = await api("/workers");
    $("daftarPekerja").innerHTML = pekerja.length===0
      ? '<div class="cat-kosong">Belum ada pekerja. Tambahkan nama di atas.</div>'
      : pekerja.map(barisPekerja).join("");
    // Deposit menumpang daftar 'pekerja' yang baru dimuat di atas untuk isi
    // dropdown penyetor — karena itu dipanggil di sini, bukan di pergi().
    renderDeposit();
    renderPenyesuaian();   // dropdown-nya menumpang daftar yang sama
  }catch(e){ gagal(e); }
}

/**
 * Satu baris pekerja + biodata ringkas.
 * Memakai tata letak .dp-baris (nama di atas, keterangan membungkus di
 * bawahnya) — bukan .pk-baris yang nowrap, karena alamat & NIK jelas tidak
 * muat sebaris di lebar HP.
 */
function barisPekerja(p){
  const info = [];
  if(p.nik)        info.push("NIK " + esc(p.nik));
  if(p.birth_date) info.push(fmtTgl(tglSaja(p.birth_date))
                     + (p.age!=null ? " (" + p.age + " th)" : ""));
  if(p.birth_place)info.push("Lahir di " + esc(p.birth_place));
  if(p.phone)      info.push("&#128222; " + esc(p.phone));
  if(p.address)    info.push(esc(p.address));

  return '<div class="dp-baris">'
    + '<span class="dp-isi">'
    +   '<span class="dp-atas"><span>'+esc(p.name)
    +     (p.is_trainee? ' <span class="tag-training">TRAINING</span>' : '')+'</span></span>'
    +   '<span class="dp-meta">'
    +     (info.length ? info.join(" &middot; ") : "Biodata belum diisi — ketuk &#9998; untuk melengkapi")
    +   '</span>'
    + '</span>'
    + '<button class="btn-edit-pk" onclick="editPekerja('+p.id+')" title="Ubah data">&#9998;</button>'
    + '<button class="btn-hapus-pk" onclick="hapusPekerja('+p.id+')" title="Hapus">&#10005;</button>'
    + '</div>';
}

/* Nama jenis kendaraan & layanan sesuai katalog owner; jatuh ke slug mentah
   bila kategorinya sudah dihapus dari katalog. */
function labelKat(slug){
  return (CFG && CFG.categories[slug] && CFG.categories[slug].label) || slug;
}
function labelSvc(slug){
  return (CFG && CFG.services[slug] && CFG.services[slug].label) || slug;
}

/**
 * Satu pekerja + daftar cucian yang ia kerjakan (bisa dibuka/tutup).
 * Barisnya sengaja SAMA dengan riwayat di Rekap Hari Ini — jam, kendaraan,
 * plat, dan totalnya — supaya angka upah bisa ditelusuri ke transaksinya.
 */
function kartuUpah(w, ruang){
  const detailId = "upah-"+ruang+"-"+w.id;
  const baris = (w.breakdown||[]).map(t =>
    '<div class="upah-trx">'
    + '<span class="upah-trx-kiri">'
    +   '<span class="waktu">'+esc(t.time||"")+'</span> '+esc(t.vehicle_name)
    +   '<span class="upah-trx-sub">'+esc(t.plate || "plat kosong")
    +     ' &middot; '+esc(labelKat(t.category))+' &middot; '+esc(labelSvc(t.service))
    +     ' &middot; '+rp(t.total)+'</span>'
    + '</span>'
    + '<b class="hijau">'+rp(t.wage)+'</b>'
    + '</div>'
  ).join("");

  // Baris penyesuaian ditampilkan HANYA kalau ada — supaya kartu upah yang
  // normal tetap seringkas dulu, dan yang dipotong langsung terlihat kenapa
  // angkanya beda dari jumlah rincian di atasnya.
  const adaPeny = (w.penalty||0) > 0 || (w.override!==null && w.override!==undefined);
  const penyHTML = !adaPeny ? "" :
      '<div class="upah-peny">'
    + (w.override!==null && w.override!==undefined
        ? '<div class="upah-peny-baris"><span>&#9998; Upah ditimpa'
          + '<span class="upah-trx-sub">hasil hitungan '+rp(w.gross_wage)+'</span></span>'
          + '<b>'+rp(w.override)+'</b></div>'
        : '')
    + ((w.penalty||0) > 0
        ? '<div class="upah-peny-baris"><span>&#10134; Potongan</span>'
          + '<b class="merah">-'+rp(w.penalty)+'</b></div>'
        : '')
    + '<div class="upah-peny-baris tebal"><span>Diterima</span>'
    +   '<b class="hijau">'+rp(w.wage)+'</b></div>'
    + '</div>';

  return '<div class="upah-kartu">'
    + '<button class="upah-kepala" onclick="document.getElementById(\''+detailId+'\').classList.toggle(\'hidden\')">'
    +   '<span class="upah-nama">'+esc(w.name)
    +     '<span class="upah-sub">'+w.vehicles+' kendaraan'
    +       (adaPeny? ' &middot; <span class="upah-tanda-peny">disesuaikan</span>' : '')
    +     '</span></span>'
    +   '<b class="hijau">'+rp(w.wage)+'</b>'
    +   '<span class="trx-panah">&#9662;</span>'
    + '</button>'
    + '<div id="'+detailId+'" class="hidden upah-detail">'
    +   (baris || '<div class="cat-kosong">Tidak ada rincian.</div>')
    +   penyHTML
    + '</div>'
    + '</div>';
}
async function tambahPekerja(){
  const nama = $("inNamaPk").value.trim();
  if(!nama){ $("inNamaPk").focus(); return; }
  try{
    await api("/workers",{method:"POST",body:{name:nama}});
    $("inNamaPk").value="";
    renderPekerja();
  }catch(e){ gagal(e); }
}
async function hapusPekerja(id){
  if(!await konfirmasiHapus()) return;
  try{ await api("/workers/"+id,{method:"DELETE"}); renderPekerja(); }catch(e){ gagal(e); }
}
/** Satu ruas berlabel di dalam modal biodata. */
function ruasBiodata(id, label, nilai, tipe, extra){
  return '<div class="sw-field-label">'+label+'</div>'
    + '<input id="'+id+'" type="'+(tipe||"text")+'" class="swal2-input" '
    +   (extra||"")+' value="'+esc(nilai==null?"":String(nilai))+'">';
}

async function editPekerja(id){
  const p = pekerja.find(x => x.id === id);
  if(!p) return;

  const {value: hasil} = await Swal.fire({
    title: "Data pekerja",
    // Hanya nama yang wajib; sisanya boleh menyusul. Itu sebabnya tidak ada
    // tanda bintang di mana-mana selain nama.
    html:
        ruasBiodata("swPkNama",  "Nama *", p.name, "text", 'maxlength="60"')
      + ruasBiodata("swPkNik",   "NIK (16 angka KTP)", p.nik, "text",
          'maxlength="16" inputmode="numeric" placeholder="opsional"')
      + ruasBiodata("swPkLahirTgl", "Tanggal lahir", tglSaja(p.birth_date), "date")
      + ruasBiodata("swPkLahirTmp", "Tempat lahir", p.birth_place, "text",
          'maxlength="60" placeholder="opsional"')
      + ruasBiodata("swPkHp",    "Nomor HP", p.phone, "text",
          'maxlength="24" inputmode="tel" placeholder="opsional"')
      + ruasBiodata("swPkAlamat","Alamat", p.address, "text",
          'maxlength="255" placeholder="opsional"'),
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: "Simpan",
    cancelButtonText: "Batal",
    confirmButtonColor: "#1B9E62",
    width: 460,
    preConfirm: () => {
      const ambil = i => document.getElementById(i).value.trim();
      const nama = ambil("swPkNama");
      const nik  = ambil("swPkNik");
      if(!nama) return Swal.showValidationMessage("Nama tidak boleh kosong");
      // Dicegat di sini juga supaya owner tidak perlu menunggu balasan server
      // hanya untuk tahu NIK-nya kurang angka.
      if(nik && !/^\d{16}$/.test(nik)) return Swal.showValidationMessage("NIK harus 16 angka sesuai KTP");
      // Kosong dikirim sebagai null, bukan "" — supaya kolom yang dikosongkan
      // benar-benar kembali kosong dan indeks unik NIK tidak menganggap dua
      // pekerja tanpa NIK sebagai nilai yang sama.
      const n = v => v === "" ? null : v;
      return {
        name: nama,
        nik: n(nik),
        birth_date:  n(ambil("swPkLahirTgl")),
        birth_place: n(ambil("swPkLahirTmp")),
        phone:       n(ambil("swPkHp")),
        address:     n(ambil("swPkAlamat")),
      };
    },
  });
  if(!hasil) return;

  try{
    await api("/workers/"+id,{method:"PATCH",body:hasil});
    await renderPekerja();   // daftar ini juga dipakai layar kasir
  }catch(e){ gagal(e); }
}

/* ---------- DEPOSIT PEKERJA KE KAS (khusus owner) ----------
   Setoran uang dari pekerja. Yang dicatat: siapa penyetornya (worker_name
   disalin di server, jadi tetap terbaca walau pekerjanya kelak dihapus) dan
   siapa yang menginputnya (created_by).

   Angka ini SENGAJA tidak masuk rekap/laba — uangnya bukan hasil cucian.
   Lihat catatan di WorkerDepositController. */
let depositKalTahun = new Date().getFullYear();
let depositKalBulan = new Date().getMonth();
let depositTglPilih = null;          // null = tampilkan sebulan penuh

function initDepositRange(){
  if($("inDepositTgl") && !$("inDepositTgl").value) $("inDepositTgl").value = hariIni();
  if($("inPenyTgl") && !$("inPenyTgl").value) $("inPenyTgl").value = hariIni();
}

/* ---------- PENYESUAIAN UPAH: potongan (hukuman) & timpa angka ----------
   Owner-only, sama seperti Deposit. Aturan hitungnya ada di server
   (WageService::terapkanPenyesuaian): 'timpa' menetapkan angka dasar, lalu
   'potongan' menguranginya, dan hasilnya tidak pernah minus.

   Uang yang tidak jadi dibayarkan otomatis menambah laba bersih — laporan
   memakai upah SETELAH penyesuaian, dan laba dihitung "... - upah -
   pengeluaran". Jadi tidak ada pos khusus yang perlu dicatat terpisah. */
let jenisPenyesuaian = "potongan";

function setJenisPenyesuaian(j){
  jenisPenyesuaian = j;
  $("jenisPotongan").classList.toggle("aktif", j==="potongan");
  $("jenisTimpa").classList.toggle("aktif", j==="timpa");
  $("inPenyJumlah").placeholder = j==="potongan"
    ? "Jumlah dipotong (Rp)" : "Upah baru hari itu (Rp)";
  $("penyKet").innerHTML = j==="potongan"
    ? "Mengurangi upah pekerja pada tanggal itu. Boleh dicatat lebih dari sekali."
    : "Mengganti upah hasil hitungan dengan angka lain. Boleh Rp 0 (upah hari itu dinolkan). Satu per pekerja per hari &mdash; yang terbaru menang.";
}

async function renderPenyesuaian(){
  const blok = $("blokPenyesuaian");
  if(!blok) return;
  if(ROLE!=="owner"){ blok.classList.add("hidden"); return; }
  blok.classList.remove("hidden");

  setJenisPenyesuaian(jenisPenyesuaian);

  $("inPenyPekerja").innerHTML = pekerja.length===0
    ? '<option value="">— belum ada pekerja —</option>'
    : '<option value="">— pilih pekerja —</option>'
      + pekerja.map(p => '<option value="'+p.id+'">'+esc(p.name)+'</option>').join("");

  // Daftar mengikuti tanggal yang sedang dipilih di kolom tanggal, supaya
  // owner langsung melihat apa saja yang sudah tercatat untuk hari itu.
  const tgl = $("inPenyTgl").value || hariIni();
  try{
    const rows = await api("/wage-adjustments?date="+tgl);
    $("daftarPenyesuaian").innerHTML = rows.length===0
      ? '<div class="cat-kosong">Belum ada potongan/koreksi pada '+fmtTgl(tgl)+'.</div>'
      : rows.map(a =>
          '<div class="cat-baris"><span>'
          + '<b>'+esc(a.worker_name)+'</b> '
          + (a.type==="timpa"
              ? '<span class="chip-timpa">TIMPA</span>'
              : '<span class="chip-potong">POTONG</span>')
          + (a.reason? '<span class="draft-note"> &#128221; '+esc(a.reason)+'</span>' : '')
          + (a.created_by? '<span class="waktu"> &#128100; '+esc(a.created_by)+'</span>' : '')
          + '</span><span>'
          + '<b class="'+(a.type==="timpa"?"":"merah")+'">'
          +   (a.type==="timpa" ? rp(a.amount) : "-"+rp(a.amount))+'</b>'
          + ' <button class="btn-void" title="Hapus" onclick="hapusPenyesuaian('+a.id+')">&#10005;</button>'
          + '</span></div>'
        ).join("");
  }catch(e){ gagal(e); }
}

async function tambahPenyesuaian(){
  const worker_id = parseInt($("inPenyPekerja").value, 10);
  const amount = parseInt($("inPenyJumlah").value, 10);
  if(!worker_id){ $("inPenyPekerja").focus(); return; }
  // 'timpa' boleh 0 (upah dinolkan), 'potongan' tidak — potongan Rp 0 tidak
  // mengubah apa pun selain menambah baris yang membingungkan.
  if(isNaN(amount) || amount<0 || (jenisPenyesuaian==="potongan" && amount<1)){
    $("inPenyJumlah").focus(); return;
  }

  const nama = (pekerja.find(p=>p.id===worker_id)||{}).name || "pekerja ini";
  const tgl  = $("inPenyTgl").value || hariIni();
  const konfirmasi = await Swal.fire({
    icon: "warning",
    title: jenisPenyesuaian==="potongan" ? "Potong upah?" : "Timpa angka upah?",
    html: jenisPenyesuaian==="potongan"
      ? 'Upah <b>'+esc(nama)+'</b> pada '+fmtTgl(tgl)+' dipotong <b>'+rp(amount)+'</b>.'
        + '<br><br>Uangnya tidak jadi dibayarkan, jadi <b>laba bersih hari itu naik</b> sebesar potongan ini.'
      : 'Upah <b>'+esc(nama)+'</b> pada '+fmtTgl(tgl)+' diganti jadi <b>'+rp(amount)+'</b>, '
        + 'menimpa hasil hitungan.<br><br>Selisihnya ikut mengubah <b>laba bersih hari itu</b>.',
    showCancelButton: true,
    confirmButtonText: "Ya, catat",
    cancelButtonText: "Batal",
    confirmButtonColor: "#C0392B",
    cancelButtonColor: "#57503E",
  });
  if(!konfirmasi.isConfirmed) return;

  const body = {worker_id, type:jenisPenyesuaian, amount,
                reason: $("inPenyAlasan").value.trim() || null, date: tgl};
  try{
    await api("/wage-adjustments",{method:"POST",body});
    $("inPenyJumlah").value=""; $("inPenyAlasan").value="";
    await renderPenyesuaian();
    await segarkanUpahTerlihat(tgl);
    Swal.fire({toast:true, position:"top-end", icon:"success", showConfirmButton:false, timer:2200,
      title: jenisPenyesuaian==="potongan" ? "Upah dipotong" : "Angka upah ditimpa",
      text: esc(nama)+" · "+rp(amount)});
  }catch(e){ gagal(e); }
}

async function hapusPenyesuaian(id){
  if(!await konfirmasiHapus()) return;
  try{
    await api("/wage-adjustments/"+id,{method:"DELETE"});
    await renderPenyesuaian();
    await segarkanUpahTerlihat($("inPenyTgl").value || hariIni());
  }catch(e){ gagal(e); }
}

/* Setelah penyesuaian berubah, angka upah yang sedang terpampang harus ikut
   berubah — kalau tidak, owner melihat upah lama dan mengira catatannya gagal. */
async function segarkanUpahTerlihat(tgl){
  await renderUpahKalender();
  if(upahTglPilih === tgl) await pilihTglUpah(tgl);
}

function gantiBulanDeposit(d){
  depositKalBulan += d;
  if(depositKalBulan<0){ depositKalBulan=11; depositKalTahun--; }
  if(depositKalBulan>11){ depositKalBulan=0; depositKalTahun++; }
  depositTglPilih = null;
  renderDeposit();
}

/**
 * Blok deposit: dropdown penyetor, kalender, dan daftar setoran.
 * Tanpa argumen = tampilkan bulan yang sedang dibuka di kalender.
 */
async function renderDeposit(){
  const blok = $("blokDeposit");
  if(!blok) return;
  if(ROLE!=="owner"){ blok.classList.add("hidden"); return; }
  blok.classList.remove("hidden");

  // Dropdown penyetor memakai daftar pekerja yang sudah dimuat renderPekerja().
  $("inDepositPekerja").innerHTML = pekerja.length===0
    ? '<option value="">— belum ada pekerja —</option>'
    : '<option value="">— pilih pekerja —</option>'
      + pekerja.map(p => '<option value="'+p.id+'">'+esc(p.name)+'</option>').join("");

  const [awal, akhir] = batasBulan(depositKalTahun, depositKalBulan);
  await muatDeposit(
    depositTglPilih ? {date: depositTglPilih} : {from: awal, to: akhir},
    {gambarKalender: true},
  );
}

/** Rentang deposit yang dipilih dengan tahan-lalu-ketuk di kalender. */
function depositRange(dari, sampai){
  depositTglPilih = null;
  muatDeposit({from: dari, to: sampai});
}

/** Satu tanggal diketuk di kalender deposit. */
function depositSatuHari(tgl){
  depositTglPilih = (depositTglPilih === tgl ? null : tgl);  // ketuk ulang = kembali sebulan
  renderDeposit();
}

/**
 * Ambil & tampilkan setoran untuk sebuah filter.
 * Kalendernya digambar ulang HANYA saat menampilkan sebulan penuh — kalau
 * ikut digambar ulang setelah memilih rentang, titik-titiknya berubah
 * mengikuti hasil filter dan bulan yang sedang dilihat jadi tampak kosong.
 */
async function muatDeposit(filter, opsi){
  const q = new URLSearchParams(filter).toString();
  try{
    const res = await api("/worker-deposits?"+q, {penuh:true});
    const rows = res.data, sum = res.summary;

    if(opsi && opsi.gambarKalender) gambarKalenderDeposit(rows);

    if(rows.length===0){
      $("daftarDeposit").innerHTML = '<div class="cat-kosong">Belum ada deposit pada periode ini.</div>';
      return;
    }

    // Ringkasan per penyetor dulu — pertanyaan pertama owner biasanya
    // "siapa sudah setor berapa", bukan rincian tiap barisnya.
    let html = '<div class="cat-baris tebal"><span>TOTAL DEPOSIT</span><b class="hijau">'+rp(sum.total)+'</b></div>';
    html += sum.per_worker.map(w =>
      '<div class="cat-baris"><span>'+esc(w.worker_name)+' <span class="waktu">'+w.count+'x setor</span></span>'
      +'<b>'+rp(w.total)+'</b></div>').join("");

    html += '<div style="margin:12px 0 6px;font-size:12px;color:var(--ink2);font-weight:700">Rincian setoran</div>';
    html += rows.map(d =>
      '<div class="dp-baris">'
      +'<span class="dp-isi">'
      +  '<span class="dp-atas"><span>'+esc(d.worker_name)+'</span>'
      +    '<b class="hijau">'+rp(d.amount)+'</b></span>'
      +  '<span class="dp-meta">'+fmtTgl(tglSaja(d.date))
      +    (d.note ? ' &middot; '+esc(d.note) : '')
      +    (d.created_by ? ' &middot; input: '+esc(d.created_by) : '')
      +  '</span>'
      +'</span>'
      +'<button class="btn-hapus-pk" onclick="hapusDeposit('+d.id+')" title="Hapus">&#10005;</button>'
      +'</div>').join("");

    $("daftarDeposit").innerHTML = html;
  }catch(e){ gagal(e); }
}

/**
 * Gambar kalender deposit; titik hijau = hari yang ada setorannya.
 * Titiknya diambil dari baris yang baru saja dimuat, jadi tidak perlu
 * permintaan kedua ke server hanya untuk menandai tanggal.
 */
function gambarKalenderDeposit(rows){
  const perTgl = {};
  (rows||[]).forEach(d => { perTgl[tglSaja(d.date)] = true; });

  $("depositKalJudul").textContent = NAMA_BULAN[depositKalBulan]+" "+depositKalTahun;

  const jmlHari = new Date(depositKalTahun, depositKalBulan+1, 0).getDate();
  let html = kepalaKalender() + awalanKalender(depositKalTahun, depositKalBulan);
  for(let t=1;t<=jmlHari;t++){
    const tgl = depositKalTahun+"-"+String(depositKalBulan+1).padStart(2,"0")+"-"+String(t).padStart(2,"0");
    html += selKalender(tgl, t, {ada: !!perTgl[tgl], pilih: tgl===depositTglPilih});
  }
  $("depositKalGrid").innerHTML = html;
  pasangKalender("depositKalGrid", {
    info: "depositKalInfo",
    onSingle: depositSatuHari,
    onRange: depositRange,
    onClear: () => { depositTglPilih = null; renderDeposit(); },
  });
  tandaiRentang("depositKalGrid");
}

async function tambahDeposit(){
  const worker_id = parseInt($("inDepositPekerja").value, 10);
  const amount = parseInt($("inDepositJumlah").value, 10);
  if(!worker_id){ $("inDepositPekerja").focus(); return; }
  if(!amount || amount<1){ $("inDepositJumlah").focus(); return; }
  const body = {worker_id, amount, note: $("inDepositCatatan").value.trim() || null};
  const tgl = $("inDepositTgl").value;
  if(tgl) body.date = tgl;
  try{
    await api("/worker-deposits",{method:"POST",body});
    $("inDepositJumlah").value=""; $("inDepositCatatan").value="";
    await renderDeposit();
    Swal.fire({toast:true, position:"top-end", icon:"success", title:"Deposit dicatat",
      text: rp(amount), showConfirmButton:false, timer:2000});
  }catch(e){ gagal(e); }
}

async function hapusDeposit(id){
  if(!await konfirmasiHapus()) return;
  try{ await api("/worker-deposits/"+id,{method:"DELETE"}); renderDeposit(); }catch(e){ gagal(e); }
}

/* ---------- UPAH PEKERJA: KALENDER + RANGE TANGGAL ---------- */
let upahKalTahun = new Date().getFullYear();
let upahKalBulan = new Date().getMonth();
let upahTglPilih = null;

function gantiBulanUpah(d){
  upahKalBulan += d;
  if(upahKalBulan<0){ upahKalBulan=11; upahKalTahun--; }
  if(upahKalBulan>11){ upahKalBulan=0; upahKalTahun++; }
  renderUpahKalender();
}

async function renderUpahKalender(){
  const [awal, akhir] = batasBulan(upahKalTahun, upahKalBulan);
  $("upahKalJudul").textContent = NAMA_BULAN[upahKalBulan]+" "+upahKalTahun;

  // Titik hijau = hari yang ada upahnya, sama seperti kalender Pembukuan &
  // Pengeluaran. Diambil dari rekap rentang yang memang sudah menyediakan
  // total upah per tanggal.
  const perTgl = {};
  try{
    const r = await api("/reports/date-range?from="+awal+"&to="+akhir);
    r.days.forEach(d => { if(d.wages) perTgl[tglSaja(d.date)] = d.wages; });
  }catch(e){
    if(e.message===ERR_LOGIN || e.message===ERR_SHIFT) return;
    // Titik hanya penanda — kalendernya tetap ditampilkan walau gagal.
  }

  const jmlHari = new Date(upahKalTahun, upahKalBulan+1, 0).getDate();
  let html = kepalaKalender() + awalanKalender(upahKalTahun, upahKalBulan);
  for(let t=1;t<=jmlHari;t++){
    const tgl = upahKalTahun+"-"+String(upahKalBulan+1).padStart(2,"0")+"-"+String(t).padStart(2,"0");
    html += selKalender(tgl, t, {ada: !!perTgl[tgl], pilih: tgl===upahTglPilih});
  }
  $("upahKalGrid").innerHTML = html;
  pasangKalender("upahKalGrid", {
    info: "upahKalInfo",
    onSingle: pilihTglUpah,
    onRange: renderUpahRange,
    onClear: () => { $("upahRangeHasil").innerHTML = ""; },
  });
  tandaiRentang("upahKalGrid");
}

async function pilihTglUpah(tgl){
  upahTglPilih = tgl;
  await renderUpahKalender(); // tandai tanggal terpilih dulu, baru muat detailnya

  try{
    const h = await api("/reports/daily?date="+tgl);
    $("upahRangeHasil").innerHTML = ''; // hasil range lama jangan bercampur

    let html = '<div class="cat-blok"><h3>&#128119; Upah '+fmtTgl(tgl)+'</h3>';
    html += (!h.worker_wages || h.worker_wages.length===0)
      ? '<div class="cat-kosong">Belum ada kendaraan yang dikerjakan pada tanggal ini.</div>'
      : h.worker_wages.map(w => kartuUpah(w, "kal")).join("")
        + '<div class="cat-baris tebal"><span>TOTAL UPAH</span><b>'+rp(h.wages)+'</b></div>';
    html += '</div>';

    $("upahDetailHari").innerHTML = html;
  }catch(e){ gagal(e); }
}

/** Rekap upah untuk rentang tanggal (dipilih dengan tahan di kalender). */
async function renderUpahRange(dari, sampai){
  try{
    const data = await api("/reports/wages?from="+dari+"&to="+sampai);
    $("upahDetailHari").innerHTML = '';   // detail satu hari & rentang jangan bertumpuk

    if(data.length===0){
      $("upahRangeHasil").innerHTML = '<div class="cat-kosong">Belum ada data upah pada periode ini.</div>';
      return;
    }

    let html = '<div style="margin-bottom:12px;font-size:12px;color:var(--ink2)">Periode: '
      + fmtTgl(dari)+' sampai '+fmtTgl(sampai)+'</div>';
    data.forEach(worker => {
      html += '<div class="cat-blok" style="background:var(--air);padding:12px;margin-bottom:8px;border-radius:8px">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">'
        + '<span style="font-weight:700">'+esc(worker.name)+'</span>'
        + '<span class="hijau" style="font-weight:800;font-size:16px">'+rp(worker.total_wage)+'</span>'
        + '</div>'
        + '<div style="font-size:12px;color:var(--ink2);margin-bottom:8px">'+worker.total_vehicles+' kendaraan</div>';

      if(worker.daily_breakdown.length > 0){
        html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:6px;font-size:12px">';
        worker.daily_breakdown.forEach(day => {
          if(!day.date || !day.wage) return;
          const parts = day.date.split('T')[0].split('-');
          const tahun = parseInt(parts[0], 10);
          const bulan = parseInt(parts[1], 10);
          const hari = parseInt(parts[2], 10);
          if(!tahun || !bulan || !hari) return;
          const namaBln = ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"][bulan-1];
          html += '<div style="background:white;padding:8px;border-radius:6px;border-left:3px solid var(--go)">'
            + '<div style="color:var(--ink2)">'+hari+' '+namaBln+'</div>'
            + '<div style="font-weight:800;color:var(--go)">'+rp(day.wage)+'</div>'
            + '<div style="color:var(--ink2);font-size:11px">'+day.vehicles+'x kendaraan</div>'
            + '</div>';
        });
        html += '</div>';
      }

      html += '</div>';
    });

    $("upahRangeHasil").innerHTML = html;
    $("upahDetailHari").innerHTML = '';
  }catch(e){ gagal(e); }
}
/* Tarif upah kini diatur bersama jenis kendaraan di Pengaturan (editKategori). */

/**
 * Rekap omzet & kendaraan per shift.
 *
 * Blok disembunyikan bila tidak ada shift yang benar-benar terpakai — toko
 * yang belum memakai shift hanya akan melihat satu kelompok "Di luar shift"
 * yang angkanya sama persis dengan total harian, jadi tidak ada gunanya.
 */
/**
 * Satu cara bayar (Cash / TF) sebagai baris yang bisa dibuka.
 * Isinya: tiap jenis kendaraan, berapa kali, dan berapa rupiah.
 *
 * Dibiarkan TERTUTUP saat pertama tampil supaya bentuk rekap sehari-hari tidak
 * berubah — rinciannya baru dibuka kalau memang sedang dicocokkan dengan uang
 * di laci.
 */
function barisBayar(p){
  const id    = "rincianBayar-"+p.method;
  const label = {cash:"Cash", tf:"TF"}[p.method] || p.method.toUpperCase();
  const kelas = p.method === "tf" ? "biru-t" : "";

  return '<button class="cat-baris baris-buka" onclick="document.getElementById(\''+id+'\').classList.toggle(\'hidden\')">'
    +   '<span>'+esc(label)+' <span class="waktu">'+p.count+'x</span></span>'
    +   '<b class="'+kelas+'">'+rp(p.total)+' <span class="trx-panah">&#9662;</span></b>'
    + '</button>'
    + '<div id="'+id+'" class="hidden rincian-bayar">'
    +   p.rows.map(r =>
        '<div class="cat-baris"><span>'+esc(labelKat(r.category))
        + ' <span class="waktu">'+r.count+'x</span></span>'
        + '<b>'+rp(r.total)+'</b></div>').join("")
    + '</div>';
}

/* ---------- REKAP: PENYARING BUKU KAS SELURUH LAYAR ----------
   Buku kas menggantikan shift sebagai satuan laporan & setoran. Shift sendiri
   (jam operasional yang mengunci aplikasi kasir di luar jam) tetap ada, hanya
   dipindah jadi urusan Pengaturan saja — tidak lagi menandai transaksi.

   null = sehari penuh (semua buku digabung); angka = id satu buku saja.
   Menggantinya memuat ulang SEMUA angka di layar Rekap dari server — bukan
   menyembunyikan sebagian yang sudah terlanjur diambil — supaya laba, upah,
   dan pengeluaran benar-benar milik buku itu saja. */
let rekapBukuPilih = null;
let rekapBukuDaftar = [];   // data buku terakhir yang dimuat; dipakai panel aksi

/** Potongan query untuk penyaring yang sedang aktif. */
function paramBukuRekap(){
  return rekapBukuPilih===null ? "" : "&book="+rekapBukuPilih;
}

/**
 * Bar tab di paling atas layar Rekap: "Semua" + satu tab per buku hari itu.
 * Disembunyikan bila belum ada buku sama sekali (data sangat lama dari
 * sebelum fitur ini, atau hari tanpa transaksi) — tab tunggal tidak
 * menyaring apa pun dan hanya memakan ruang.
 */
function renderRekapBuku(books){
  rekapBukuDaftar = books;
  const bar = $("rekapBukuTabs");
  if(!bar) return;

  bar.classList.toggle("hidden", books.length===0);
  if(books.length===0){ rekapBukuPilih = null; renderRekapBukuAksi(); return; }

  // "Semua" sengaja tetap ada dan jadi tab pertama: laba bersih SEHARI adalah
  // angka yang paling sering dicari owner, dan tanpa tab ini angka itu tidak
  // bisa dilihat sama sekali.
  const daftar = [{label:"Semua", id:null}].concat(
    books.map(b => ({label:b.label, id:b.id})));

  bar.innerHTML = daftar.map((t, i) =>
    '<button class="set-tab'+(t.id===rekapBukuPilih?" aktif":"")+'"'
    + ' onclick="pilihTabBukuRekap('+i+')">'+esc(t.label)+'</button>'
  ).join("");

  bar._daftar = daftar;   // dipakai penangan klik, supaya id-nya tidak perlu
                          // disandikan ke dalam atribut onclick
  renderRekapBukuAksi();
}

async function pilihTabBukuRekap(i){
  const bar = $("rekapBukuTabs");
  const t = bar && bar._daftar && bar._daftar[i];
  if(!t) return;
  rekapBukuPilih = t.id;
  await renderRekap();     // seluruh layar dimuat ulang untuk buku ini
}

/**
 * Panel di bawah tab: tombol tutup buku (kalau buku yang dipilih masih
 * terbuka), atau lencana status (menunggu/disetor/ditolak). Kosong di tab
 * "Semua" — tidak ada satu buku tunggal untuk ditindak di sana.
 */
function renderRekapBukuAksi(){
  const el = $("rekapBukuAksi");
  if(!el) return;

  const b = rekapBukuDaftar.find(x => x.id === rekapBukuPilih);
  if(!b){ el.innerHTML = ""; return; }

  if(b.status === "open"){
    // Hanya OWNER atau kasir yang sedang login yang menutup buku — tapi
    // siapa pun yang login boleh (ini bukan aksi sensitif seperti hapus data,
    // hanya menutup catatannya sendiri), sama seperti mencatat transaksi.
    el.innerHTML = '<button class="btn-catat" style="background:var(--go);width:100%;margin-bottom:12px"'
      + ' onclick="tutupBuku('+b.id+')">&#128230; Simpan &amp; Ajukan Setoran '+esc(b.label)+'</button>';
    return;
  }

  const lencana = {
    pending:   {kelas:"buku-menunggu", ikon:"&#8987;", teks:"Menunggu persetujuan owner"},
    deposited: {kelas:"buku-disetor",  ikon:"&#9989;", teks:"Sudah disetor"},
    rejected:  {kelas:"buku-ditolak",  ikon:"&#10060;", teks:"Ditolak"},
  }[b.status];
  if(!lencana) return;

  let sub = "";
  if(b.status==="pending")   sub = "diajukan "+esc(b.requested_by||"-")+" &middot; "+jam(b.requested_at);
  if(b.status==="deposited") sub = "diterima "+esc(b.approved_by||"-")+" &middot; "+jam(b.approved_at);
  if(b.status==="rejected")  sub = "oleh "+esc(b.rejected_by||"-")+" &middot; "+jam(b.rejected_at)
    + '<br>&#128172; '+esc(b.reject_reason||"tanpa alasan");

  el.innerHTML = '<div class="buku-lencana '+lencana.kelas+'">'
    + '<div class="buku-lencana-atas"><span>'+lencana.ikon+' '+lencana.teks+'</span>'
    +   '<b>'+rp(b.amount||0)+'</b></div>'
    + '<div class="buku-lencana-sub">'+sub+'</div>'
    + '</div>';
}

/** Kasir menutup buku yang sedang terbuka & mengajukan setoran cash-nya. */
async function tutupBuku(id){
  const b = rekapBukuDaftar.find(x => x.id === id);
  if(!b) return;
  const r = await Swal.fire({
    title: "Tutup "+b.label+"?",
    html: "Uang cash yang harus disetor (perkiraan saat ini):<br>"
      + '<b style="font-size:22px;color:var(--go-dk)">'+rp(b.amount||0)+'</b>'
      + '<br><small>TF tidak dihitung — sudah otomatis masuk rekening.<br>'
      + 'Buku baru langsung terbuka setelah ini untuk transaksi berikutnya.</small>',
    icon: "question", showCancelButton: true,
    confirmButtonText: "Ya, ajukan setoran", cancelButtonText: "Batal",
    confirmButtonColor: "#1B9E62",
  });
  if(!r.isConfirmed) return;
  try{
    await api("/cash-books/"+id+"/request-deposit", {method:"POST"});
    rekapBukuPilih = null;   // buku ini sudah tertutup; kembali ke ringkasan sehari
    await renderRekap();
    Swal.fire({toast:true, position:"top-end", icon:"success",
      title: b.label+" ditutup", text: "Menunggu persetujuan owner.",
      showConfirmButton:false, timer:2500});
  }catch(e){ gagal(e); }
}

/**
 * Daftar setoran yang menunggu persetujuan owner — sama persis polanya
 * dengan daftar pengajuan pembatalan transaksi (blokApproval), TIDAK
 * dibatasi tanggal hari ini saja: owner perlu tetap melihatnya walau sedang
 * membuka laporan hari lain.
 */
async function renderApprovalSetoran(){
  const blok = $("blokApprovalSetoran");
  if(!blok) return;
  if(ROLE!=="owner"){ blok.classList.add("hidden"); return; }
  try{
    const list = await api("/cash-books/pending");
    blok.classList.toggle("hidden", list.length===0);
    $("approvalSetoranJumlah").textContent = list.length ? list.length : "";
    $("daftarApprovalSetoran").innerHTML = list.map(b =>
      '<div class="app-baris">'
      + '<div class="app-info">'
      +   '<div class="app-judul">'+esc(fmtTgl(tglSaja(b.date)))+' &middot; Buku '+b.number
      +     ' <b>'+rp(b.amount)+'</b></div>'
      +   '<div class="app-sub">&#128100; '+esc(b.requested_by||"kasir")
      +     ' &middot; '+jam(b.requested_at)+'</div>'
      + '</div>'
      + '<div class="app-aksi">'
      +   '<button class="btn-terima-setoran" onclick="setujuiSetoran('+b.id+')">&#10003; Terima</button>'
      +   '<button class="btn-tolak-setoran" onclick="tolakSetoran('+b.id+')">&#10005; Tolak</button>'
      + '</div>'
      + '</div>').join("");
  }catch(e){ gagal(e); }
}
async function setujuiSetoran(id){
  const r = await Swal.fire({title:"Terima setoran ini?", icon:"question",
    text:"Pastikan uang cash-nya sudah benar-benar diterima sebelum menekan ini.",
    showCancelButton:true, confirmButtonText:"Ya, sudah diterima", cancelButtonText:"Batal",
    confirmButtonColor:"#1B9E62"});
  if(!r.isConfirmed) return;
  try{
    await api("/cash-books/"+id+"/approve",{method:"POST"});
    renderApprovalSetoran();
    renderRekap();
  }catch(e){ gagal(e); }
}
async function tolakSetoran(id){
  const {value: alasan} = await Swal.fire({
    title:"Tolak setoran", input:"text", inputPlaceholder:"Mis. uangnya kurang, salah hitung...",
    inputAttributes:{maxlength:200},
    showCancelButton:true, confirmButtonText:"Tolak", cancelButtonText:"Batal", confirmButtonColor:"#d33",
    inputValidator: v => !v || !v.trim() ? "Alasan wajib diisi" : undefined,
  });
  if(alasan===undefined) return;
  try{
    await api("/cash-books/"+id+"/reject",{method:"POST",body:{reason:alasan.trim()}});
    renderApprovalSetoran();
    renderRekap();
  }catch(e){ gagal(e); }
}

/* ---------- REKAP HARI INI ---------- */
async function renderRekap(){
  try{
    // CFG dimuat setelah layar pertama dibuka; renderRekap bisa jalan lebih
    // dulu (mis. tab Rekap dibuka langsung setelah login). Tanpa penantian
    // ini, labelKat() jatuh ke slug mentah ("kecil" alih-alih "Mobil Kecil")
    // di rincian Cash/TF.
    if(!CFG) CFG = await api("/config");
    const h = await api("/reports/daily?date="+hariIni()+paramBukuRekap());
    const cuciTotal = h.total;
    const cuciLaba = cuciTotal + h.tip - h.wages;

    // Baris TF & Cash menggantikan "Cash Motor"/"Cash Mobil" yang lama: sekarang
    // bisa dibuka dan memerinci SEMUA jenis kendaraan berikut berapa kalinya,
    // bukan cuma memisah motor dari mobil.
    $("rekapCuci").innerHTML =
      '<div class="cat-baris"><span>Total Cuci</span><b>'+rp(cuciTotal)+'</b></div>'
      +'<div class="cat-baris"><span>Tip</span><b>'+(h.tip? rp(h.tip):"kosong")+'</b></div>'
      + (h.by_payment||[]).map(barisBayar).join("")
      +'<div class="cat-baris"><span>Upah pekerja</span><b class="merah">-'+rp(h.wages)+'</b></div>'
      +'<div class="cat-baris tebal"><span>Laba Cuci</span><b class="hijau">'+rp(cuciLaba)+'</b></div>';

    $("rekapFnb").innerHTML =
      '<div class="cat-baris"><span>Total F&amp;B</span><b>'+rp(h.fnb_total)+'</b></div>'
      +'<div class="cat-baris"><span>Cash</span><b>'+(h.fnb_cash? rp(h.fnb_cash):"kosong")+'</b></div>'
      +'<div class="cat-baris"><span>TF</span><b class="biru-t">'+(h.fnb_tf? rp(h.fnb_tf):"kosong")+'</b></div>'
      +'<div class="cat-baris tebal"><span>Laba F&amp;B</span><b class="hijau">'+rp(h.fnb_total)+'</b></div>';

    renderRekapBuku(h.books || []);

    // Bagian ini cukup melaporkan pengeluarannya saja: daftar + totalnya.
    // Angka omzet TIDAK diulang di sini — sudah ada kotak OMZET tepat di
    // bawah kartu ini, dan menampilkannya dua kali membuat orang mengira
    // keduanya angka yang berbeda. Barisnya SELALU ada, termasuk saat
    // pengeluaran masih kosong, supaya letaknya tidak berpindah-pindah.
    $("rekapKeluar").innerHTML =
      ((h.expense_list||[]).length===0
        ? '<div class="cat-kosong">Belum ada pengeluaran hari ini.</div>'
        : (h.expense_list||[]).map(e =>
            '<div class="cat-baris"><span>'+esc(e.description)+'</span>'
            +'<b class="merah">-'+rp(e.amount)+'</b></div>').join(""))
      + '<div class="cat-baris tebal"><span>Total Pengeluaran</span><b class="merah">'
      +   (h.expenses? '-'+rp(h.expenses) : rp(0))+'</b></div>'
      + barisTitipanRekap(h);

    $("statMasuk").textContent = rp(omzetHari(h));
    $("statTrx").textContent = h.vehicles + " kendaraan";
    $("statLaba").textContent = rp(h.profit);
    renderRiwayat();
    renderApproval();
    renderApprovalSetoran();
  }catch(e){ gagal(e); }
}
/* Uang masuk hari itu: cucian + makanan/minuman + tip. Ini angka KOTOR,
   sebelum pengeluaran — dipakai sebagai baris pertama hitungan di blok
   Pengeluaran, bukan sebagai angka omzet yang ditampilkan.

   Tip ikut karena memang uang yang diterima hari itu dan ikut dihitung di
   laba bersih; mengeluarkannya membuat hitungan tidak nyambung dengan Laba
   Bersih yang tertera di sebelahnya. */
/* Dua baris yang muncul HANYA di cucian yang menerima titipan, supaya
   hitungan di layar tetap bisa dicocokkan dengan tangan: kenapa uang masuk
   sekian tapi omzetnya segitu. */
function barisTitipanRekap(h){
  let baris = "";
  if(h.consignor_share){
    baris += '<div class="cat-baris"><span>&#129309; Hak penitip (uang orang)</span>'
      + '<b class="merah">-'+rp(h.consignor_share)+'</b></div>';
  }
  if(h.consignor_payout){
    baris += '<div class="cat-baris"><span>&#8617;&#65039; Setoran titipan (bukan biaya)</span>'
      + '<b class="hijau">+'+rp(h.consignor_payout)+'</b></div>';
  }
  return baris;
}

function uangMasukHari(h){
  return (h.total||0) + (h.fnb_total||0) + (h.tip||0);
}

/* Omzet yang DITAMPILKAN: uang masuk dikurangi pengeluaran. Satu arti untuk
   semua layar — Rekap, Dashboard, dan Pembukuan sama-sama memakai angka
   setelah pengeluaran, supaya "omzet" tidak berarti dua hal berbeda
   tergantung layar mana yang sedang dibuka. */
function omzetHari(h){
  /* Titip jual membelokkan dua angka, dan keduanya dikoreksi di sini supaya
     "omzet" tetap berarti sama persis dengan yang dihitung server di
     BookkeepingService::dateRange():

       hak penitip      uang orang yang kebetulan lewat laci kita — bukan
                        pendapatan, jadi dipotong;
       setoran titipan  ikut terhitung di h.expenses karena uangnya memang
                        keluar dari laci, tapi ia BUKAN biaya — hak itu sudah
                        dipotong sejak barangnya laku, jadi dikembalikan.

     Cucian yang tidak menerima titipan: kedua angka 0, rumusnya persis
     seperti dulu. */
  return uangMasukHari(h)
    - ((h.expenses||0) - (h.consignor_payout||0))
    - (h.consignor_share||0);
}

function unduhCSV(){ window.location = API+"/reports/daily/csv?date="+hariIni()+"&token="+encodeURIComponent(TOKEN); }

/* ---------- PENGELUARAN ----------
   Uang keluar (sabun, bensin, servis alat). Langsung mengurangi laba bersih
   di Rekap Hari Ini, Pembukuan, dan Dashboard. */
let keluarTgl = hariIni();   // tanggal aktif: untuk melihat detail SEKALIGUS mencatat
let keluarKalTahun = new Date().getFullYear();
let keluarKalBulan = new Date().getMonth();

function gantiBulanKeluar(d){
  keluarKalBulan += d;
  if(keluarKalBulan<0){ keluarKalBulan=11; keluarKalTahun--; }
  if(keluarKalBulan>11){ keluarKalBulan=0; keluarKalTahun++; }
  renderKeluarKalender();
}

/**
 * Kalender bulanan + ringkasan bulan. Jumlah pengeluaran sebulan selalu
 * kecil, jadi satu permintaan untuk sebulan lalu dikelompokkan di sini
 * sudah cukup — tidak perlu endpoint kalender tersendiri.
 */
async function renderKeluarKalender(){
  const [awal, akhir] = batasBulan(keluarKalTahun, keluarKalBulan);
  $("keluarKalJudul").textContent = NAMA_BULAN[keluarKalBulan]+" "+keluarKalTahun;

  let list = [];
  try{ list = await api("/expenses?from="+awal+"&to="+akhir); }catch(e){ gagal(e); return; }

  const perTgl = {};
  list.forEach(e => {
    const k = tglSaja(e.date);
    perTgl[k] = (perTgl[k]||0) + e.amount;
  });

  const jmlHari = new Date(keluarKalTahun, keluarKalBulan+1, 0).getDate();
  let html = kepalaKalender() + awalanKalender(keluarKalTahun, keluarKalBulan);
  for(let t=1;t<=jmlHari;t++){
    const tgl = keluarKalTahun+"-"+String(keluarKalBulan+1).padStart(2,"0")+"-"+String(t).padStart(2,"0");
    html += selKalender(tgl, t, {ada: !!perTgl[tgl], pilih: tgl===keluarTgl});
  }
  $("keluarKalGrid").innerHTML = html;
  pasangKalender("keluarKalGrid", {
    info: "keluarKalInfo",
    onSingle: pilihTglKeluar,
    onRange: cariKeluarRange,
    onClear: () => { $("keluarRangeHasil").innerHTML = ""; },
  });
  tandaiRentang("keluarKalGrid");

  const total = list.reduce((s,e)=>s+e.amount, 0);
  $("keluarJudulRingkas").innerHTML = "&#128200; "+NAMA_BULAN[keluarKalBulan]+" "+keluarKalTahun;
  $("keluarRingkas").innerHTML =
    '<div class="cat-baris"><span>Hari ada pengeluaran</span><b>'+Object.keys(perTgl).length+' hari</b></div>'
    +'<div class="cat-baris"><span>Jumlah catatan</span><b>'+list.length+'</b></div>'
    +'<div class="cat-baris tebal"><span>TOTAL BULAN INI</span><b class="merah">-'+rp(total)+'</b></div>';
}

/** Pilih tanggal di kalender: jadi tanggal aktif untuk input DAN untuk detail. */
async function pilihTglKeluar(tgl){
  keluarTgl = tgl;
  $("keluarRangeHasil").innerHTML = ""; // hasil range lama jangan bercampur
  await renderKeluarKalender();
  await renderDetailKeluar();
  $("keluarDetailHari").scrollIntoView({behavior:"smooth", block:"start"});
}

/** Daftar pengeluaran pada tanggal yang sedang dipilih. */
async function renderDetailKeluar(){
  $("keluarTglAktif").textContent = fmtTgl(keluarTgl) + (keluarTgl===hariIni()? " (hari ini)" : "");
  try{
    const list = await api("/expenses?date="+keluarTgl);
    const total = list.reduce((t,e)=>t+e.amount, 0);

    // Uang masuk hari itu ikut diambil supaya kasir langsung melihat
    // pengeluaran ini memotong apa — mencatat angka minus tanpa pembandingnya
    // membuat sulit menilai apakah belanjanya masih wajar. Kalau gagal (mis.
    // kasir di luar jam operasional), blok hitungannya dilewati saja, daftar
    // pengeluarannya tetap tampil.
    //
    // Pengeluaran dipotong SEKALI, lewat omzetHari() — jangan dikurangi lagi
    // dengan `total` di sini, karena h.expenses sudah berisi angka yang sama.
    let hitung = "";
    try{
      const h = await api("/reports/daily?date="+keluarTgl);
      const masuk = uangMasukHari(h);
      const omzet = omzetHari(h);
      hitung = '<div class="cat-baris"><span>Uang masuk (cuci + F&amp;B + tip)</span>'
        +   '<b>'+rp(masuk)+'</b></div>'
        + '<div class="cat-baris"><span>Pengeluaran</span><b class="merah">'
        +   (h.expenses? '-'+rp(h.expenses) : rp(0))+'</b></div>'
        + barisTitipanRekap(h)
        + '<div class="cat-baris tebal"><span>OMZET</span>'
        +   '<b class="'+(omzet>=0?"hijau":"merah")+'">'+rp(omzet)+'</b></div>';
    }catch(e){ /* daftar pengeluaran tetap berguna tanpa blok hitungan */ }

    $("keluarDetailHari").innerHTML =
      '<div class="cat-blok"><h3>&#128197; '+fmtTgl(keluarTgl)+(keluarTgl===hariIni()?' &middot; HARI INI':'')+'</h3>'
      + (list.length===0
          ? '<div class="cat-kosong">Belum ada pengeluaran pada tanggal ini.</div>'
          : list.map(barisKeluar).join(""))
      + hitung
      + '</div>';
  }catch(e){ gagal(e); }
}

/* Isi setiap baris pengeluaran yang sedang tampil di layar mana pun.
   editPengeluaran() mengisi formulirnya dari sini, bukan menembak server lagi
   — dan yang lebih penting, bukan menyelipkan keterangan pengeluaran ke dalam
   atribut onclick, yang akan pecah begitu keterangannya memuat tanda kutip. */
const keluarTampil = new Map();

/** Satu baris pengeluaran; tombol koreksi & hapus hanya untuk owner. */
function barisKeluar(e){
  keluarTampil.set(e.id, e);
  return '<div class="cat-baris">'
    + '<span>&#128184; '+esc(e.description)
    +   (e.created_by? '<span class="waktu"> &#128100; '+esc(e.created_by)+'</span>' : '')
    + '</span>'
    // Nominal & tombol dibungkus satu span, seperti trxHTML — lihat .keluar-aksi.
    + '<span class="keluar-aksi"><b class="merah">-'+rp(e.amount)+'</b>'
    // Mengubah angka pengeluaran hanya boleh owner — sama seperti void transaksi.
    // Ini cuma menyembunyikan tombolnya; penjaga sebenarnya ada di routes/api.php,
    // yang tetap menolak walau layar ini dipaksa memunculkannya.
    + (ROLE==="owner"
        ? '<button class="btn-edit-pk" title="Koreksi" onclick="editPengeluaran('+e.id+')">&#9998;</button>'
          + '<button class="btn-hapus-pk" title="Hapus" onclick="hapusPengeluaran('+e.id+')">&#10005;</button>'
        : '')
    + '</span>'
    + '</div>';
}

/** Cari pengeluaran pada rentang tanggal (dipilih dengan tahan di kalender). */
async function cariKeluarRange(dari, sampai){
  try{
    const list = await api("/expenses?from="+dari+"&to="+sampai);
    const total = list.reduce((t,e)=>t+e.amount, 0);

    // Kelompokkan per tanggal, terbaru di atas.
    const perTgl = {};
    list.forEach(e => { (perTgl[tglSaja(e.date)] ??= []).push(e); });
    const tanggal = Object.keys(perTgl).sort().reverse();

    let html = '<div class="cat-blok"><h3>&#128184; '+fmtTgl(dari)+' s/d '+fmtTgl(sampai)+'</h3>';
    if(list.length===0){
      html += '<div class="cat-kosong">Tidak ada pengeluaran pada periode ini.</div>';
    }else{
      html += '<div class="cat-baris"><span>Hari ada pengeluaran</span><b>'+tanggal.length+' hari</b></div>'
        + '<div class="cat-baris"><span>Jumlah catatan</span><b>'+list.length+'</b></div>'
        + '<div class="cat-baris tebal"><span>TOTAL PERIODE</span><b class="merah">-'+rp(total)+'</b></div>';
    }
    html += '</div>';

    tanggal.forEach(tgl => {
      const rows = perTgl[tgl];
      const sub  = rows.reduce((t,e)=>t+e.amount, 0);
      html += '<div class="cat-blok"><h3>'+fmtTgl(tgl)+(tgl===hariIni()?' &middot; HARI INI':'')+'</h3>'
        + rows.map(barisKeluar).join("")
        + '<div class="cat-baris tebal"><span>Subtotal</span><b class="merah">-'+rp(sub)+'</b></div>'
        + '</div>';
    });

    $("keluarRangeHasil").innerHTML = html;
    $("keluarDetailHari").innerHTML = ''; // biar tidak bingung mana hasil yang dilihat
    $("keluarRangeHasil").scrollIntoView({behavior:"smooth", block:"start"});
  }catch(e){ gagal(e); }
}

async function renderPengeluaran(){
  await renderSaldoAwal();
  await renderKeluarKalender();
  await renderDetailKeluar();
}

/* ---------- SALDO KAS KECIL (buku yang sedang berjalan) ----------
   Diisi manual sekali tiap buku baru dibuka (biasanya pagi), dari uang
   tunai yang sudah ada di tangan kasir sebelum transaksi hari itu.

   MURNI pelacak uang di tangan kasir — TIDAK menyentuh Omzet/Laba Bersih
   di layar mana pun. Total Pengeluaran tetap dihitung dari jumlah baris
   pengeluaran seperti sebelumnya; "sisa saldo" di sini cuma menjawab
   "dari modal pagi tadi, berapa yang masih ada di tangan saya". */
let bukuTerbukaSekarang = null;   // {id, opening_balance, ...} — null kalau tidak ada / belum dimuat

async function renderSaldoAwal(){
  const blok = $("blokSaldoAwal");
  if(!blok) return;
  try{
    const h = await api("/reports/daily?date="+hariIni());
    bukuTerbukaSekarang = (h.books||[]).find(b => b.status==="open") || null;
  }catch(e){ bukuTerbukaSekarang = null; }

  if(!bukuTerbukaSekarang){ blok.classList.add("hidden"); return; }
  blok.classList.remove("hidden");

  const b = bukuTerbukaSekarang;
  $("saldoBukuLabel").textContent = "— "+b.label;
  $("inSaldoAwal").value = b.opening_balance!=null ? b.opening_balance : "";

  $("saldoAwalInfo").innerHTML = b.opening_balance==null
    ? '<div class="cat-kosong">Belum diisi. Masukkan uang tunai yang ada di tangan sebelum transaksi pertama.</div>'
    : '<div class="cat-baris"><span>Saldo awal</span><b>'+rp(b.opening_balance)+'</b></div>'
      +'<div class="cat-baris"><span>Sudah dipakai (pengeluaran tercatat)</span><b class="merah">-'+rp(b.opening_balance - b.remaining_balance)+'</b></div>'
      +'<div class="cat-baris tebal"><span>SISA SALDO</span><b class="'+(b.remaining_balance>=0?"hijau":"merah")+'">'+rp(b.remaining_balance)+'</b></div>';
}

async function simpanSaldoAwal(){
  if(!bukuTerbukaSekarang) return;
  const amount = parseInt($("inSaldoAwal").value, 10);
  if(isNaN(amount) || amount<0){ $("inSaldoAwal").focus(); return; }
  try{
    await api("/cash-books/"+bukuTerbukaSekarang.id+"/opening-balance",{method:"PUT",body:{amount}});
    await renderSaldoAwal();
    Swal.fire({toast:true, position:"top-end", icon:"success", title:"Saldo awal tersimpan",
      text:rp(amount), showConfirmButton:false, timer:2000});
  }catch(e){ gagal(e); }
}

async function tambahPengeluaran(){
  const ket = $("inKeluarKet").value.trim();
  const jml = parseInt($("inKeluarJumlah").value,10);
  if(!ket){ $("inKeluarKet").focus(); return; }
  if(!jml || jml<1){ $("inKeluarJumlah").focus(); return; }
  try{
    await api("/expenses",{method:"POST",body:{description:ket, amount:jml, date:keluarTgl}});
    $("inKeluarKet").value=""; $("inKeluarJumlah").value="";
    $("keluarRangeHasil").innerHTML = ""; // angka range jadi basi setelah ada catatan baru
    renderPengeluaran();
    Swal.fire({toast:true, position:"top-end", icon:"success",
      title:"Pengeluaran dicatat", text: fmtTgl(keluarTgl),
      showConfirmButton:false, timer:1800});
  }catch(e){ gagal(e); }
}
/* Baris pengeluaran muncul di DUA layar (Pengeluaran & Pembukuan). Sesudah
   dikoreksi atau dihapus, yang berubah bukan cuma barisnya — omzet, laba
   bersih, dan sisa saldo di sekelilingnya ikut bergeser. Jadi layar yang
   sedang terbuka dimuat ulang utuh, bukan ditambal per baris. */
async function muatUlangKeluar(){
  if(!$("layarBuku").classList.contains("hidden")){ await renderBuku(); return; }
  $("keluarRangeHasil").innerHTML = "";   // angka rentang jadi basi
  await renderPengeluaran();
}

async function hapusPengeluaran(id){
  if(!await konfirmasiHapus()) return;
  try{
    await api("/expenses/"+id,{method:"DELETE"});
    await muatUlangKeluar();
  }catch(e){ gagal(e); }
}

/* Koreksi pengeluaran yang salah catat — owner saja, sama seperti hapus.
   Memakai PATCH /api/expenses/{id} yang sudah lama ada di server tapi selama
   ini tidak punya tombol di layar mana pun.

   Tanggal ikut boleh diubah, karena justru di situ kesalahan paling sering
   terjadi: nota kemarin yang telanjur tercatat hari ini. Mengubahnya
   MEMINDAHKAN baris ini ke hari lain, jadi ia akan hilang dari tanggal yang
   sedang dibuka — itu memang yang diinginkan, bukan kegagalan. */
async function editPengeluaran(id){
  const p = keluarTampil.get(id);
  if(!p) return;

  const {value: hasil} = await Swal.fire({
    title: "Koreksi pengeluaran",
    html:
        ruasBiodata("swKlKet", "Keterangan *", p.description, "text", 'maxlength="160"')
      + ruasBiodata("swKlJml", "Jumlah (Rp) *", p.amount, "number", 'inputmode="numeric" min="1"')
      + ruasBiodata("swKlTgl", "Tanggal", tglSaja(p.date), "date"),
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: "Simpan",
    cancelButtonText: "Batal",
    confirmButtonColor: "#1B9E62",
    width: 460,
    preConfirm: () => {
      const ket = document.getElementById("swKlKet").value.trim();
      const jml = parseInt(document.getElementById("swKlJml").value, 10);
      const tgl = document.getElementById("swKlTgl").value;
      // Batasnya disamakan dengan ExpenseController supaya owner tidak perlu
      // menunggu balasan server hanya untuk tahu angkanya kelewat besar.
      if(!ket) return Swal.showValidationMessage("Keterangan tidak boleh kosong");
      if(!jml || jml < 1) return Swal.showValidationMessage("Jumlah harus lebih dari 0");
      if(jml > 100000000) return Swal.showValidationMessage("Jumlah kelewat besar");
      if(!tgl) return Swal.showValidationMessage("Tanggal tidak boleh kosong");
      return {description: ket, amount: jml, date: tgl};
    },
  });
  if(!hasil) return;

  try{
    await api("/expenses/"+id,{method:"PATCH",body:hasil});
    await muatUlangKeluar();
    Swal.fire({toast:true, position:"top-end", icon:"success",
      title:"Pengeluaran dikoreksi", text:rp(hasil.amount),
      showConfirmButton:false, timer:1800});
  }catch(e){ gagal(e); }
}

/* ---------- PERSETUJUAN PEMBATALAN (owner) ----------
   Kasir hanya bisa MENGAJUKAN pembatalan; transaksinya tetap dihitung
   sampai owner memutuskan di sini. */
async function renderApproval(){
  const blok = $("blokApproval");
  if(!blok) return;
  if(ROLE!=="owner"){ blok.classList.add("hidden"); return; }
  try{
    const list = await api("/transactions/void-requests");
    blok.classList.toggle("hidden", list.length===0);
    $("approvalJumlah").textContent = list.length ? list.length : "";
    $("daftarApproval").innerHTML = list.map(r =>
      '<div class="app-baris">'
      + '<div class="app-info">'
      +   '<div class="app-judul">'+esc(r.vehicle_name)+' &middot; '+esc(r.plate||"plat kosong")
      +     ' <b>'+rp(r.total)+'</b></div>'
      +   '<div class="app-sub">&#128100; '+esc(r.void_requested_by||"kasir")
      +     ' &middot; '+jam(r.void_requested_at)+'</div>'
      +   '<div class="app-alasan">&#128172; '+esc(r.void_request_reason||"tanpa alasan")+'</div>'
      + '</div>'
      + '<div class="app-aksi">'
      +   '<button class="btn-setuju" onclick="setujuiVoid('+r.id+')">&#10003; Setujui</button>'
      +   '<button class="btn-tolak" onclick="tolakVoid('+r.id+')">&#10005; Tolak</button>'
      + '</div>'
      + '</div>').join("");
  }catch(e){ gagal(e); }
}
async function setujuiVoid(id){
  const r = await Swal.fire({title:"Setujui pembatalan?", icon:"warning",
    text:"Transaksi akan dikeluarkan dari rekap uang & upah hari ini.",
    showCancelButton:true, confirmButtonText:"Ya, batalkan", cancelButtonText:"Batal", confirmButtonColor:"#d33"});
  if(!r.isConfirmed) return;
  try{
    await api("/transactions/"+id+"/void/approve",{method:"POST"});
    renderRekap();
  }catch(e){ gagal(e); }
}
async function tolakVoid(id){
  const {value: alasan} = await Swal.fire({
    title:"Tolak pembatalan", input:"text", inputPlaceholder:"Alasan menolak...",
    inputAttributes:{maxlength:120},
    showCancelButton:true, confirmButtonText:"Tolak", cancelButtonText:"Batal", confirmButtonColor:"#1B9E62",
    inputValidator: v => !v || !v.trim() ? "Alasan wajib diisi" : undefined,
  });
  if(alasan===undefined) return;
  try{
    await api("/transactions/"+id+"/void/reject",{method:"POST",body:{reason:alasan.trim()}});
    renderRekap();
  }catch(e){ gagal(e); }
}

/* ---------- JAM OPERASIONAL (SHIFT) ----------
   Di luar jam ini server menolak semua permintaan kasir dengan HTTP 423,
   dan api() memunculkan layar terkunci. Owner tidak pernah terkena. */
async function muatShift(){
  try{
    SHIFT = await api("/shift");
    renderChipShift();
  }catch(e){ /* status shift bukan hal kritis — abaikan bila gagal */ }
}
function renderChipShift(){
  const chip = $("shiftChip");
  if(!chip || !SHIFT) return;
  chip.classList.toggle("hidden", !SHIFT.enabled);
  if(!SHIFT.enabled) return;
  chip.classList.toggle("tutup", !SHIFT.is_open);
  const kini = SHIFT.current_shift;
  chip.innerHTML = SHIFT.is_open
    ? "&#9679; "+(kini ? esc(kini.name)+" s/d "+esc(kini.end_time) : "Buka")
    : "&#9679; Tutup";
}
/** "Pagi 07:00–14:00" untuk dipakai di daftar & layar terkunci. */
function jamShift(s){
  return esc(s.name)+" "+esc(s.start_time)+"–"+esc(s.end_time);
}
function tampilkanTerkunci(pesan, shift){
  if(shift){ SHIFT = shift; renderChipShift(); }
  // Pesan owner ditaruh sebagai textContent (bukan innerHTML): isinya teks
  // bebas, jadi jangan sampai tag di dalamnya ikut dieksekusi.
  const catatan = (shift && shift.message) || "";
  $("kunciCatatan").textContent = catatan;
  $("kunciCatatan").classList.toggle("hidden", catatan === "");
  $("kunciPesan").textContent = pesan || "Aplikasi hanya bisa dipakai pada jam operasional.";

  // Tampilkan SEMUA shift aktif, dengan shift berikutnya ditandai — kasir
  // langsung tahu jadwal hari itu, bukan cuma satu jam buka.
  const aktif = ((shift && shift.shifts) || []).filter(s => s.is_active);
  const berikut = shift && shift.next_shift;
  $("kunciJam").innerHTML = aktif.length
    ? '<div class="kunci-jadwal-judul">Jadwal shift</div>'
      + aktif.map(s =>
          '<div class="kunci-shift'+(berikut && s.id===berikut.id ? ' berikut' : '')+'">'
          + jamShift(s)
          + (berikut && s.id===berikut.id ? ' <span class="kunci-tag">berikutnya</span>' : '')
          + '</div>').join("")
      + '<div class="kunci-sekarang">Sekarang pukul '+esc(shift.now)+'</div>'
    : (shift ? '<div class="kunci-sekarang">Sekarang pukul '+esc(shift.now)+'</div>' : "");
  $("layarTerkunci").classList.remove("hidden-login");
}
async function cekShiftLagi(){
  try{
    const s = await api("/shift");
    SHIFT = s; renderChipShift();
    if(s.is_open || ROLE==="owner"){
      $("layarTerkunci").classList.add("hidden-login");
      mulaiAplikasi();
    }else{
      tampilkanTerkunci("Masih di luar jam operasional.", s);
    }
  }catch(e){ /* tetap terkunci */ }
}
/* --- Pengaturan shift (owner) --- */
let shiftAktifDraft = false;
async function renderShiftPengaturan(){
  const blok = $("blokShift");
  if(!blok) return;
  if(ROLE!=="owner"){ blok.classList.add("hidden"); return; }
  blok.classList.remove("hidden");
  try{
    SHIFT = await api("/shift");
    renderChipShift();
    shiftAktifDraft = SHIFT.enabled;
    $("inShiftPesan").value = SHIFT.message || "";
    hitungSisaPesan();
    gambarTombolShift();
    gambarDaftarShift();
  }catch(e){ gagal(e); }
}

/** Daftar shift + status toko saat ini. */
function gambarDaftarShift(){
  const s = SHIFT || {};
  const list = s.shifts || [];
  const kini = s.current_shift;

  $("daftarShift").innerHTML = list.length===0
    ? '<div class="cat-kosong">Belum ada shift. Tambahkan minimal satu supaya penguncian punya jadwal.</div>'
    : list.map(x => {
        const berjalan = kini && x.id===kini.id;
        return '<div class="shift-baris'+(berjalan?" berjalan":"")+'">'
          + '<div class="shift-info">'
          +   '<div class="shift-nama">'+esc(x.name)
          +     (berjalan? ' <span class="shift-tag">sedang berjalan</span>' : '')+'</div>'
          +   '<div class="shift-jam">'+esc(x.start_time)+' &ndash; '+esc(x.end_time)
          +     (x.start_time >= x.end_time ? ' <span class="waktu">(lewat tengah malam)</span>' : '')+'</div>'
          + '</div>'
          // Tombol dikelompokkan supaya ketiganya ikut pindah baris bersama
          // saat layar sempit, bukan tercecer sendiri-sendiri.
          + '<span class="shift-aksi">'
          +   '<button class="btn-hadir'+(x.is_active?" aktif":"")+'" onclick="toggleShiftAktifBaris('+x.id+','+(x.is_active?0:1)+')">'
          +     (x.is_active? "&#10003; Aktif" : "Nonaktif")+'</button>'
          +   '<button class="btn-edit-pk" title="Ubah" onclick="editShift('+x.id+')">&#9998;</button>'
          +   '<button class="btn-hapus-pk" title="Hapus" onclick="hapusShift('+x.id+')">&#10005;</button>'
          + '</span>'
          + '</div>';
      }).join("");

  // Penguncian menyala tanpa shift aktif = kasir bisa terkunci selamanya.
  // Server sengaja memilih membuka; owner diberi tahu supaya sadar.
  const bahaya = s.enabled && s.no_shift;
  $("shiftWaspada").classList.toggle("hidden", !bahaya);
  if(bahaya){
    $("shiftWaspada").innerHTML = "&#9888;&#65039; Penguncian aktif tapi <b>tidak ada shift yang aktif</b>. "
      + "Selama begini aplikasi dibiarkan terbuka, supaya kasir tidak terkunci tanpa jalan keluar. "
      + "Tambahkan atau aktifkan minimal satu shift.";
  }

  $("shiftStatusInfo").innerHTML = !s.enabled
    ? "Penguncian mati — kasir bisa memakai aplikasi kapan saja."
    : (s.is_open
        ? "Saat ini toko <b>BUKA</b>"+(kini? " ("+esc(kini.name)+", sampai "+esc(kini.end_time)+")" : "")+" &middot; pukul "+esc(s.now)+"."
        : "Saat ini toko <b>TUTUP</b>"
          +(s.next_shift? " &middot; buka lagi jam "+esc(s.next_shift.start_time)+" ("+esc(s.next_shift.name)+")" : "")
          +" &middot; pukul "+esc(s.now)+".");
}

/** Form tambah/ubah shift. id null = tambah baru. */
async function editShift(id){
  const s = id ? (SHIFT.shifts||[]).find(x=>x.id===id) : null;
  const {value: hasil} = await Swal.fire({
    title: s ? "Ubah "+s.name : "Shift baru",
    html:
      '<div class="sw-field-label">Nama shift</div>'
      +'<input id="swShiftNama" class="swal2-input" maxlength="40" placeholder="Pagi, Sore, Malam..." value="'+esc(s?s.name:"")+'">'
      +'<div class="sw-field-label">Jam mulai</div>'
      +'<input id="swShiftMulai" type="time" class="swal2-input" value="'+esc(s?s.start_time:"07:00")+'">'
      +'<div class="sw-field-label">Jam selesai</div>'
      +'<input id="swShiftSelesai" type="time" class="swal2-input" value="'+esc(s?s.end_time:"14:00")+'">'
      +'<div class="sw-judul">Jam selesai boleh lebih kecil dari jam mulai — itu berarti shift lewat tengah malam.</div>',
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: "Simpan", cancelButtonText: "Batal", confirmButtonColor: "#1B9E62",
    preConfirm: () => {
      const name  = document.getElementById("swShiftNama").value.trim();
      const mulai = document.getElementById("swShiftMulai").value;
      const akhir = document.getElementById("swShiftSelesai").value;
      if(!name)            return Swal.showValidationMessage("Nama shift tidak boleh kosong");
      if(!mulai || !akhir) return Swal.showValidationMessage("Jam mulai & selesai wajib diisi");
      if(mulai === akhir)  return Swal.showValidationMessage("Jam mulai dan selesai tidak boleh sama");
      return {name, start_time: mulai, end_time: akhir};
    },
  });
  if(!hasil) return;
  try{
    SHIFT = s
      ? await api("/shifts/"+s.id,{method:"PATCH",body:hasil})
      : await api("/shifts",{method:"POST",body:hasil});
    renderChipShift();
    gambarDaftarShift();
    Swal.fire({toast:true, position:"top-end", icon:"success",
      title: s? "Shift diperbarui" : "Shift ditambahkan",
      showConfirmButton:false, timer:1800});
  }catch(e){ gagal(e); }
}

async function toggleShiftAktifBaris(id, aktif){
  try{
    SHIFT = await api("/shifts/"+id,{method:"PATCH",body:{is_active: !!aktif}});
    renderChipShift();
    gambarDaftarShift();
  }catch(e){ gagal(e); }
}

async function hapusShift(id){
  const s = (SHIFT.shifts||[]).find(x=>x.id===id);
  const r = await Swal.fire({title:"Hapus shift "+(s?s.name:"")+"?", icon:"warning",
    text:"Jam kerja ini tidak lagi dipakai untuk membuka akses kasir.",
    showCancelButton:true, confirmButtonText:"Ya, hapus", cancelButtonText:"Batal", confirmButtonColor:"#d33"});
  if(!r.isConfirmed) return;
  try{
    SHIFT = await api("/shifts/"+id,{method:"DELETE"});
    renderChipShift();
    gambarDaftarShift();
  }catch(e){ gagal(e); }
}

function gambarTombolShift(){
  const b = $("tglShiftAktif");
  b.classList.toggle("aktif", shiftAktifDraft);
  b.innerHTML = shiftAktifDraft ? "&#10003; Penguncian AKTIF" : "Penguncian nonaktif";
}
function toggleShiftAktif(){ shiftAktifDraft = !shiftAktifDraft; gambarTombolShift(); }
/** Sisa karakter pesan; batas 200 biar tetap muat di layar terkunci. */
function hitungSisaPesan(){
  const n = $("inShiftPesan").value.length;
  $("shiftPesanSisa").textContent = n ? (200-n)+" karakter tersisa" : "Kosongkan bila tidak perlu.";
}
/** Simpan sakelar utama + pesan. Jam-jamnya diatur lewat daftar shift. */
async function simpanShift(){
  try{
    SHIFT = await api("/shift",{method:"PUT",
      body:{enabled:shiftAktifDraft, message:$("inShiftPesan").value.trim()}});
    renderChipShift();
    gambarDaftarShift();
    Swal.fire({icon:"success", title:"Pengaturan tersimpan",
      text: shiftAktifDraft
        ? "Kasir hanya bisa masuk saat ada shift yang berjalan."
        : "Penguncian dimatikan.",
      timer:2400, showConfirmButton:false});
  }catch(e){ gagal(e); }
}
/** Pratinjau layar terkunci — owner bisa lihat hasilnya tanpa menunggu tutup. */
function pratinjauTerkunci(){
  const s = Object.assign({}, SHIFT || {}, {message: $("inShiftPesan").value.trim()});
  const b = s.next_shift;
  tampilkanTerkunci(
    b ? "Shift sudah tutup. Aplikasi bisa dipakai lagi mulai jam "+b.start_time+" ("+b.name+")."
      : "Shift sudah tutup.",
    s);
  $("kunciPratinjau").classList.remove("hidden"); // owner butuh jalan keluar
}
function tutupPratinjau(){
  $("layarTerkunci").classList.add("hidden-login");
  $("kunciPratinjau").classList.add("hidden");
}

/* ---------- KALENDER PEMBUKUAN ---------- */
const skrgD = new Date();
let kalTahun = skrgD.getFullYear();
let kalBulan = skrgD.getMonth();
let tglPilih = null;
const NAMA_BULAN = ["Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];

function gantiBulan(d){
  kalBulan += d;
  if(kalBulan<0){ kalBulan=11; kalTahun--; }
  if(kalBulan>11){ kalBulan=0; kalTahun++; }
  tglPilih = null;
  renderBuku();
}
function fmtTgl(t){
  return new Date(t+"T00:00:00").toLocaleDateString("id-ID",{weekday:"long", day:"numeric", month:"long", year:"numeric"});
}
/* Isi tiap transaksi yang sedang tampil, dipakai editTrx() untuk mengisi
   formulir tanpa menembak server lagi. Sama alasannya dengan keluarTampil. */
const trxTampil = new Map();

/**
 * @param opsi {batal, koreksi} — tombol mana yang boleh muncul di baris ini.
 *   batal   : Rekap Hari Ini (kasir mengajukan, owner membatalkan langsung)
 *   koreksi : Pembukuan (owner saja; membetulkan isi tanpa membatalkan)
 * Keduanya mati kalau tidak disebut — itulah sebabnya riwayat di Pembukuan
 * selama ini tidak punya tombol apa pun.
 */
function trxHTML(r, opsi){
  trxTampil.set(r.id, r);
  const bolehVoid   = opsi && opsi.batal;
  const bolehKoreksi = opsi && opsi.koreksi && ROLE==="owner";
  const pk = (r.workers||[]).map(w=>esc(w.name)).join(", ");
  const batal = !!r.voided_at;
  const menunggu = r.void_status==="menunggu";
  const ditolak  = r.void_status==="ditolak";
  const kt = CFG.categories[r.category];
  const sv = CFG.services[r.service];
  const fnb = itemFnb(r);
  const grand = totalTrx(r);

  const kepala = '<div class="cat-baris trx-head'+(batal?' trx-batal':'')+'" onclick="toggleTrx(this)"><span>'
    +'<span class="waktu">'+jam(r.created_at)+'</span> &middot; '+esc(r.vehicle_name)+' &middot; '+esc(r.plate||"plat kosong")+' '
    +'<span class="'+(r.payment_method==="tf"?"chip-tf":"chip-cash")+'">'+(r.payment_method==="tf"?"TF":"CASH")+'</span>'
    /* chip BONUS hanya untuk catatan lama — fiturnya sendiri sudah dihapus */
    +(r.is_bonus?' <span class="chip-bonus">GRATIS</span>':'')
    +(batal?' <span class="chip-batal">BATAL</span>':'')
    +(menunggu?' <span class="chip-tunggu">MENUNGGU APPROVAL</span>':'')
    // Koreksi tidak mencoret barisnya seperti pembatalan, jadi tanpa chip ini
    // angka yang sudah diubah owner tidak bisa dibedakan dari yang asli.
    +(r.edit_count?' <span class="chip-edit" title="Pernah dikoreksi owner">&#9998; DIKOREKSI'+(r.edit_count>1?' '+r.edit_count+'&times;':'')+'</span>':'')
    +(fnb.length?' <span class="chip-fnb">&#127860; '+fnb.length+'</span>':'')
    +'</span><span><b>'+rp(grand)+'</b>'
    // Koreksi hanya di Pembukuan & owner: membetulkan isi transaksi yang tetap
    // terjadi, lawan dari void yang membatalkan transaksi yang tidak jadi.
    +((bolehKoreksi && !batal && !menunggu)?' <button class="btn-edit-pk" title="Koreksi transaksi" onclick="event.stopPropagation();editTrx('+r.id+')">&#9998;</button>':'')
    // Kasir tetap boleh menekan tombol ini — bedanya jadi PENGAJUAN, bukan pembatalan.
    +((bolehVoid && !batal && !menunggu)?' <button class="btn-void" title="'+(ROLE==="owner"?"Batalkan transaksi":"Ajukan pembatalan")+'" onclick="event.stopPropagation();voidTrx('+r.id+',\''+jsStr(r.vehicle_name+' · '+rp(grand))+'\')">&#10005;</button>':'')
    +' <span class="trx-panah">&#9662;</span></span></div>';

  const baris = [
    // Nomor yang sama dengan yang tercetak di resi — pegangan untuk mencocokkan
    // transfer masuk ke catatannya, terutama saat dua transfer bernilai sama.
    ["No. Nota", nomorNota("C", r.id)],
    ["Jenis", kt? esc(kt.label) : esc(r.category)],
    ["Layanan", r.category==="motor" ? "Cuci Motor" : (sv? esc(sv.label) : esc(r.service))],
    ["Pembayaran", r.payment_method==="tf" ? "Transfer" : "Cash"],
    ["Tip", r.tip? rp(r.tip) : "&mdash;"],
    ["Pekerja", pk || "&mdash;"],
    ["Dicatat oleh", r.created_by? "&#128100; "+esc(r.created_by) : "&mdash;"],
  ];
  (r.addons||[]).forEach(a =>
    baris.push(["+ "+esc(a.pivot?a.pivot.name:a.name), rp(a.pivot?a.pivot.price:a.price)]));
  fnb.forEach(i => baris.push(["&#127860; "+esc(i.product_name)+" x"+i.qty, rp(i.subtotal)]));
  if(fnb.length) baris.push(["Cucian saja", rp(r.total)]);
  if(r.is_bonus) baris.push(["Keterangan","Cuci gratis (fitur lama)"]);
  if(menunggu){
    baris.push(["Diajukan batal", esc(r.void_request_reason||"tanpa alasan")]);
    baris.push(["Diajukan oleh", "&#128100; "+esc(r.void_requested_by||"-")]);
  }
  if(ditolak){
    baris.push(["Pengajuan ditolak", esc(r.void_reject_reason||"tanpa alasan")]);
    baris.push(["Ditolak oleh", "&#128100; "+esc(r.void_rejected_by||"-")]);
  }
  if(batal) {
    // Batal lewat pengajuan kasir yang disetujui owner: nama PENGAJUNYA harus
    // tetap tampil. Dulu yang tersisa hanya "Dibatalkan oleh Owner", jadi
    // sebulan kemudian semua pembatalan kasir terlihat seperti keputusan owner
    // sendiri — padahal "siapa yang membatalkan" adalah keluhan pertama owner
    // (docs/BACKLOG.md 1.1). Pengajuan yang pernah DITOLAK lalu dibatalkan owner
    // langsung bukan jalur ini: requestVoid() membersihkan jejak tolak saat
    // pengajuan ulang, jadi void_rejected_at yang masih terisi berarti ditolak.
    const lewatPengajuan = r.void_requested_by && !r.void_rejected_at;
    if(lewatPengajuan){
      baris.push(["Diajukan batal oleh", "&#128100; "+esc(r.void_requested_by)
        + (r.void_requested_at? ' <span class="waktu">'+jam(r.void_requested_at)+'</span>' : '')]);
      baris.push(["Alasan", esc(r.void_request_reason||r.void_reason||"tanpa alasan")]);
      if(r.voided_by) baris.push(["Disetujui oleh", "&#128100; "+esc(r.voided_by)
        + (r.voided_at? ' <span class="waktu">'+jam(r.voided_at)+'</span>' : '')]);
    } else {
      baris.push(["Dibatalkan", esc(r.void_reason||"tanpa alasan")]);
      if(r.voided_by) baris.push(["Dibatalkan oleh", "&#128100; "+esc(r.voided_by)
        + (r.voided_at? ' <span class="waktu">'+jam(r.voided_at)+'</span>' : '')]);
    }
  }
  // Jejak koreksi — inilah pertanggungjawaban angka yang sudah diubah, jadi
  // ia ditaruh di detail yang sama dengan jejak pembatalan, bukan disembunyikan.
  if(r.edit_count){
    baris.push(["Dikoreksi", esc(r.edit_reason||"tanpa alasan")
      + (r.edit_count>1? ' <span class="waktu">('+r.edit_count+' kali)</span>' : '')]);
    baris.push(["Dikoreksi oleh", "&#128100; "+esc(r.edited_by||"-")
      + (r.edited_at? ' <span class="waktu">'+jam(r.edited_at)+'</span>' : '')]);
  }

  const detail = '<div class="trx-detail">'
    + baris.map(b=>'<div class="cat-baris trx-det-baris"><span class="waktu">'+b[0]+'</span><span>'+b[1]+'</span></div>').join("")
    // Cetak ulang untuk owner & kasir, di Rekap maupun Pembukuan. Transaksi
    // yang sudah batal tidak diberi tombol: resinya tidak boleh beredar lagi.
    + (!batal
        ? '<button class="btn-cetak-ulang" onclick="event.stopPropagation();lihatResiCucian('+r.id+')">'
          + '&#129534; Lihat Resi</button>'
        : '')
    + '</div>';

  return '<div class="trx-item">'+kepala+detail+'</div>';
}
function toggleTrx(el){
  el.parentElement.classList.toggle("buka");
}

/* ---------- KOREKSI TRANSAKSI (owner, dari Pembukuan) ----------
   Membetulkan transaksi yang SALAH DICATAT tapi tetap terjadi — mis. mobil
   kecil telanjur dipilih "mobil", atau pekerjanya salah orang. Beda tugas
   dengan void, yang untuk transaksi yang memang tidak jadi.

   Yang sengaja TIDAK ada di formulir ini — tanggal, nomor antrian, total,
   dan makanan/minuman yang menempel — beserta alasannya masing-masing
   ditulis di TransactionService::update(). Total dihitung server dari
   katalog harga; angka di bawah cuma pratinjau supaya owner tidak menyimpan
   sambil menebak. */

/** Layanan yang PUNYA HARGA untuk kategori ini; di luar itu server menolak. */
function opsiLayanan(kat, terpilih){
  const harga = (CFG.categories[kat]||{}).prices || {};
  return Object.keys(harga).map(s =>
    '<option value="'+esc(s)+'"'+(s===terpilih?' selected':'')+'>'
    + esc((CFG.services[s]||{}).label || s)+' &mdash; '+rp(harga[s])+'</option>').join("");
}

async function editTrx(id){
  const r = trxTampil.get(id);
  if(!r) return;

  const pilihPk = new Set((r.workers||[]).map(w=>w.id));
  const pilihAd = new Set((r.addons||[]).map(a=>a.id));
  const addonAktif = CFG.addons || [];

  // Add-on yang MENEMPEL di transaksi ini tapi sudah dinonaktifkan owner tidak
  // muncul di daftar centang, dan server pun menolak memasangnya kembali
  // (resolveAddons menyaring is_active). Menyimpan koreksi berarti add-on itu
  // hilang beserta harganya — owner harus tahu itu SEBELUM menekan Simpan,
  // bukan menemukannya sendiri dari total yang tiba-tiba mengecil.
  const adHilang = (r.addons||[]).filter(a => !addonAktif.some(c => c.id === a.id));
  const peringatan = adHilang.length
    ? '<div style="margin-top:6px;color:var(--danger);font-weight:800">&#9888; '
      + adHilang.map(a=>esc(a.pivot?a.pivot.name:a.name)).join(", ")
      + ' sudah dinonaktifkan &mdash; akan hilang dari transaksi ini bila disimpan.</div>'
    : '';

  const {value: hasil} = await Swal.fire({
    title: "Koreksi transaksi",
    width: 520,
    html:
        '<div class="sw-field-label">Nama kendaraan *</div>'
      + '<input id="swTrxNama" class="swal2-input" maxlength="100" value="'+esc(r.vehicle_name||"")+'">'
      + '<div class="sw-field-label">Plat nomor</div>'
      + '<input id="swTrxPlat" class="swal2-input" maxlength="20" placeholder="boleh kosong" value="'+esc(r.plate||"")+'">'
      + '<div class="sw-field-label">Jenis kendaraan *</div>'
      + '<select id="swTrxKat" class="sw-select">'
      +   Object.keys(CFG.categories).map(k => '<option value="'+esc(k)+'"'
      +     (k===r.category?' selected':'')+'>'+esc(CFG.categories[k].label)+'</option>').join("")
      + '</select>'
      + '<div class="sw-field-label">Layanan *</div>'
      + '<select id="swTrxSvc" class="sw-select">'+opsiLayanan(r.category, r.service)+'</select>'
      + '<div class="sw-field-label">Cara bayar *</div>'
      + '<select id="swTrxBayar" class="sw-select">'
      +   '<option value="cash"'+(r.payment_method==="cash"?' selected':'')+'>Cash</option>'
      +   '<option value="tf"'+(r.payment_method==="tf"?' selected':'')+'>Transfer</option>'
      + '</select>'
      + '<div class="sw-field-label">Tip</div>'
      + '<input id="swTrxTip" type="number" class="swal2-input" inputmode="numeric" min="0" value="'+(r.tip||0)+'">'
      + (addonAktif.length
          ? '<div class="sw-field-label">Layanan tambahan</div>'
            + '<div class="sw-cek-grid" id="swTrxAddon">'
            + addonAktif.map(a=>'<label class="sw-cek"><input type="checkbox" value="'+a.id+'"'
                +(pilihAd.has(a.id)?' checked':'')+'> '+esc(a.name)
                +' <span class="waktu">'+rp(a.price)+'</span></label>').join("")
            + '</div>'
          : '')
      + '<div class="sw-field-label">Pekerja &mdash; menentukan upah</div>'
      + '<div class="sw-cek-grid" id="swTrxPekerja">'
      +   (pekerja.length
            ? pekerja.map(p=>'<label class="sw-cek"><input type="checkbox" value="'+p.id+'"'
                +(pilihPk.has(p.id)?' checked':'')+'> '+esc(p.name)
                +(p.is_trainee?' <span class="waktu">training</span>':'')+'</label>').join("")
            : '<div class="cat-kosong">Tidak ada pekerja terdaftar.</div>')
      + '</div>'
      + '<div class="sw-total-pratinjau" id="swTrxTotal"></div>'
      + '<div class="sw-field-label">Alasan koreksi *</div>'
      + '<input id="swTrxAlasan" class="swal2-input" maxlength="120" placeholder="mis. salah pilih jenis kendaraan">',
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: "Simpan koreksi",
    cancelButtonText: "Batal",
    confirmButtonColor: "#1B9E62",
    didOpen: () => {
      const kat = document.getElementById("swTrxKat");
      const svc = document.getElementById("swTrxSvc");
      const kotakAd = document.getElementById("swTrxAddon");

      const hitung = () => {
        const harga = (CFG.categories[kat.value]||{}).prices || {};
        const dasar = harga[svc.value] || 0;
        const tambahan = kotakAd
          ? [...kotakAd.querySelectorAll("input:checked")]
              .reduce((t,c)=>t+((addonAktif.find(a=>a.id===+c.value)||{}).price||0), 0)
          : 0;
        const baru = dasar + tambahan;
        document.getElementById("swTrxTotal").innerHTML =
          'Total cucian jadi <b>'+rp(baru)+'</b>'
          + (baru===r.total
              ? ' <span class="waktu">(tidak berubah)</span>'
              : ' <span class="waktu">dari '+rp(r.total)+'</span>')
          + '<div style="margin-top:4px;font-weight:600">Upah pekerja ikut dihitung ulang server.</div>'
          + peringatan;
      };

      kat.addEventListener("change", () => {
        // Tiap kategori punya daftar layanan sendiri (motor cuma reguler).
        // Dibangun ulang di sini supaya layanan yang tidak punya harga di
        // kategori baru tidak ikut terkirim & ditolak server.
        svc.innerHTML = opsiLayanan(kat.value, svc.value);
        hitung();
      });
      svc.addEventListener("change", hitung);
      if(kotakAd) kotakAd.addEventListener("change", hitung);
      hitung();
    },
    preConfirm: () => {
      const ambil = i => document.getElementById(i).value.trim();
      const nama   = ambil("swTrxNama");
      const alasan = ambil("swTrxAlasan");
      if(!nama)   return Swal.showValidationMessage("Nama kendaraan tidak boleh kosong");
      if(!alasan) return Swal.showValidationMessage("Alasan koreksi wajib diisi");

      const tip = parseInt(ambil("swTrxTip"), 10);
      const tercentang = sel => [...document.querySelectorAll(sel)].map(c => +c.value);

      return {
        vehicle_name:   nama,
        plate:          ambil("swTrxPlat") || null,
        category:       ambil("swTrxKat"),
        service:        ambil("swTrxSvc"),
        payment_method: ambil("swTrxBayar"),
        tip:            (isNaN(tip) || tip < 0) ? 0 : tip,
        addon_ids:      tercentang("#swTrxAddon input:checked"),
        worker_ids:     tercentang("#swTrxPekerja input:checked"),
        edit_reason:    alasan,
      };
    },
  });
  if(!hasil) return;

  try{
    await api("/transactions/"+id,{method:"PATCH",body:hasil});
    await muatUlangTrx();
    Swal.fire({toast:true, position:"top-end", icon:"success",
      title:"Transaksi dikoreksi", text:hasil.vehicle_name,
      showConfirmButton:false, timer:2000});
  }catch(e){ gagal(e); }
}

/* Sama alasannya dengan muatUlangKeluar: yang berubah bukan cuma baris itu —
   omzet, upah, dan angka buku kas hari itu ikut bergeser. */
async function muatUlangTrx(){
  if(!$("layarBuku").classList.contains("hidden")){ await renderBuku(); return; }
  await renderRekap();
}
/* Modal alasan pembatalan — menggantikan prompt() bawaan browser, yang
   diblokir di banyak webview/tablet sehingga tombol batal terasa "mati". */
let voidId = null;
function voidTrx(id, ringkas){
  voidId = id;
  $("voidRingkas").innerHTML = ringkas ? esc(ringkas) : "";
  $("voidErr").textContent = "";
  $("voidAlasan").value = "";
  // Kasir mengajukan, owner membatalkan langsung — judul & tombol menyesuaikan
  // supaya kasir tidak mengira transaksinya sudah hilang dari rekap.
  const milikOwner = ROLE==="owner";
  $("voidJudul").innerHTML = milikOwner ? "Batalkan transaksi" : "Ajukan pembatalan";
  $("voidCatatan").innerHTML = milikOwner
    ? "Transaksi tetap tersimpan sebagai jejak, tapi dikeluarkan dari rekap uang &amp; upah."
    : "Pembatalan perlu disetujui owner. Sampai disetujui, transaksi <b>masih dihitung</b> di rekap.";
  $("voidKirim").innerHTML = milikOwner ? "Batalkan" : "Kirim Pengajuan";
  $("voidOverlay").classList.add("buka");
  setTimeout(()=>$("voidAlasan").focus(), 50);
}
function tutupVoid(){ $("voidOverlay").classList.remove("buka"); voidId = null; }
async function kirimVoid(){
  const alasan = $("voidAlasan").value.trim();
  if(!alasan){ $("voidErr").textContent = "Alasan wajib diisi."; $("voidAlasan").focus(); return; }
  if(voidId===null) return;
  try{
    await api("/transactions/"+voidId+"/void",{method:"POST",body:{reason:alasan}});
    tutupVoid();
    // Tombol batal kini ada di DUA layar. muatUlangTrx() memuat ulang yang
    // sedang terbuka saja — angka di layar sebelah ikut benar sendiri karena
    // pergi() memanggil render-nya tiap kali layar itu dibuka.
    await muatUlangTrx();
    if(ROLE!=="owner"){
      Swal.fire({icon:"info", title:"Pengajuan terkirim",
        text:"Menunggu persetujuan owner. Transaksi masih dihitung sampai disetujui.",
        confirmButtonColor:"#1B9E62"});
    }
  }catch(e){ $("voidErr").textContent = e.message; }
}
async function renderBuku(){
  try{
    const kal = await api("/reports/calendar?year="+kalTahun+"&month="+(kalBulan+1));
    $("kalJudul").textContent = NAMA_BULAN[kalBulan]+" "+kalTahun;
    const ada = {}; kal.days.forEach(d => ada[tglSaja(d.date)]=d);
    const jmlHari = new Date(kalTahun, kalBulan+1, 0).getDate();
    let html = kepalaKalender() + awalanKalender(kalTahun, kalBulan);
    for(let t=1;t<=jmlHari;t++){
      const tgl = kalTahun+"-"+String(kalBulan+1).padStart(2,"0")+"-"+String(t).padStart(2,"0");
      html += selKalender(tgl, t, {
        ada: !!ada[tgl], pilih: tgl===tglPilih, mati: !ada[tgl],
      });
    }
    $("kalGrid").innerHTML = html;
    pasangKalender("kalGrid", {
      info: "kalInfo",
      onSingle: pilihTgl,
      onRange: cariByRange,
      onClear: tutupRange,
    });
    tandaiRentang("kalGrid");
    const s = kal.summary;
    $("totalBulan").innerHTML =
      '<div class="cat-baris"><span>Hari beroperasi</span><b>'+s.operating_days+' hari &middot; '+s.vehicles+' kendaraan</b></div>'
      +'<div class="cat-baris"><span>Cucian</span><b>'+rp(s.wash_total)+'</b></div>'
      +'<div class="cat-baris"><span>F&amp;B</span><b>'+(s.fnb_total? rp(s.fnb_total):"kosong")+'</b></div>'
      +'<div class="cat-baris"><span>Tip</span><b>'+(s.tip? rp(s.tip):"kosong")+'</b></div>'
      +'<div class="cat-baris"><span>Omzet</span><b class="hijau">'+rp(s.total)+'</b></div>'
      +'<div class="cat-baris"><span>Upah pekerja</span><b class="merah">-'+rp(s.wages)+'</b></div>'
      +'<div class="cat-baris"><span>Pengeluaran</span><b class="merah">-'+rp(s.expenses)+'</b></div>'
      +'<div class="cat-baris tebal"><span>LABA BERSIH</span><b class="'+(s.profit>=0?"hijau":"merah")+'">'+rp(s.profit)+'</b></div>';

    if(!tglPilih){
      $("detailHari").innerHTML = '<div class="cat-kosong" style="text-align:center">Tap tanggal bertitik hijau untuk lihat riwayat transaksi &amp; pendapatan hari itu.</div>';
      return;
    }
    const tgl = tglPilih; // kunci tanggal: selagi await, user bisa tap tanggal lain
    const [h, trx, fnb] = await Promise.all([
      api("/reports/daily?date="+tgl),
      api("/transactions?date="+tgl),
      api("/fnb-sales?date="+tgl),
    ]);
    if(tglPilih !== tgl) return; // pilihan sudah berubah — hasil ini basi, jangan render
    const upahRows = h.worker_wages.map(w =>
      '<div class="cat-baris"><span>&#128119; '+esc(w.name)+' &middot; '+w.vehicles+' kendaraan</span><b>'+rp(w.wage)+'</b></div>').join("");
    $("detailHari").innerHTML =
      '<div class="cat-blok"><h3>&#128197; '+fmtTgl(tgl)+(tgl===hariIni()?' &middot; HARI INI':'')+'</h3>'
      +'<div class="cat-baris"><span>Total</span><b>'+rp(h.total)+'</b></div>'
      +'<div class="cat-baris"><span>Tip</span><b>'+(h.tip?rp(h.tip):"kosong")+'</b></div>'
      +'<div class="cat-baris"><span>TF</span><b>'+(h.tf?rp(h.tf):"kosong")+'</b></div>'
      +'<div class="cat-baris"><span>Cash Motor</span><b>'+(h.cash_motor?rp(h.cash_motor):"kosong")+'</b></div>'
      +'<div class="cat-baris"><span>Cash Mobil</span><b>'+(h.cash_mobil?rp(h.cash_mobil):"kosong")+'</b></div>'
      +'<div class="cat-baris"><span>F&amp;B</span><b>'+(h.fnb_total?rp(h.fnb_total):"kosong")+'</b></div>'
      +'<div class="cat-baris"><span>Cash Total</span><b>'+rp(h.cash_total)+'</b></div>'
      +'<div class="cat-baris"><span>Upah pekerja</span><b class="merah">-'+rp(h.wages)+'</b></div>'
      +upahRows
      +'<div class="cat-baris"><span>Pengeluaran</span><b class="merah">-'+rp(h.expenses)+'</b></div>'
      // Baris yang sama persis dengan layar Pengeluaran — termasuk tombol
      // koreksi & hapus untuk owner. Ini satu-satunya jalan membetulkan
      // pengeluaran bertanggal lampau: Rekap cuma melayani hari ini.
      +(h.expense_list||[]).map(e => barisKeluar(e)).join("")
      +'<div class="cat-baris tebal"><span>LABA BERSIH</span><b class="hijau">'+rp(h.profit)+'</b></div>'
      +'<div style="margin-top:12px"><button class="btn-export" onclick="window.location=API+\'/reports/daily/csv?date='+tgl+'&token=\'+encodeURIComponent(TOKEN)">&#128190; Unduh CSV tanggal ini</button></div>'
      +'</div>'
      // Rincian per buku: tanggal lampau tidak butuh tombol tutup/setujui
      // seperti Rekap Hari Ini (buku sehari yang sudah lewat sudah pasti
      // bukan 'open' lagi), jadi cukup daftar ringkas berstatus.
      +((h.books&&h.books.length)? '<div class="cat-blok"><h3>&#128214; Per buku</h3>'+h.books.map(barisBukuRingkas).join("")+'</div>' : '')
      +'<div class="cat-blok"><h3>&#129534; Riwayat transaksi ('+h.vehicles+')</h3>'
      +'<input id="bukuCariIn" class="cari-kecil" placeholder="&#128269; Cari nama kendaraan / plat&hellip;" oninput="bukuCari=this.value;bukuHal=1;renderBukuTrx()">'
      +'<div id="bukuTrxList"></div><div id="bukuTrxNav" class="hal-nav"></div></div>'
      +'<div class="cat-blok"><h3>&#127860; Penjualan F&amp;B</h3>'
      +(fnb.length? fnb.map(sl=>'<div class="cat-baris"><span><span class="waktu">'+jam(sl.created_at)+'</span> &middot; '+sl.items.map(i=>esc(i.product_name)+' x'+i.qty).join(", ")+(sl.created_by?' <span class="waktu">&#128100; '+esc(sl.created_by)+'</span>':'')+'</span><b>'+rp(sl.total)+'</b></div>').join("") : '<div class="cat-kosong">Tidak ada penjualan F&amp;B.</div>')+'</div>';
    bukuTrxData = trx; bukuCari=""; bukuHal=1;
    renderBukuTrx();
  }catch(e){ gagal(e); }
}
/**
 * Satu baris ringkas buku kas untuk detail hari di Pembukuan — bukan
 * interaktif seperti panel di Rekap Hari Ini, karena tanggal lampau tidak
 * pernah punya buku yang masih 'open' untuk ditutup.
 */
const LABEL_STATUS_BUKU = {
  open: "&#9998; berjalan", pending: "&#8987; menunggu",
  deposited: "&#9989; disetor", rejected: "&#10060; ditolak",
};
function barisBukuRingkas(b){
  return '<div class="cat-baris"><span>'+esc(b.label)
    + ' <span class="waktu">'+(LABEL_STATUS_BUKU[b.status]||b.status)
    +   (b.cashiers&&b.cashiers.length? ' &middot; '+esc(b.cashiers.join(", ")) : '')+'</span></span>'
    + '<b>'+rp(b.amount||0)+'</b></div>';
}

/* Riwayat transaksi di Pembukuan: cari + halaman (client-side, 10/halaman) */
let bukuTrxData=[], bukuCari="", bukuHal=1;
const BUKU_PER_HAL = 10;
function renderBukuTrx(){
  const wadah = $("bukuTrxList"); if(!wadah) return;
  const q = bukuCari.trim().toLowerCase();
  const cocok = q
    ? bukuTrxData.filter(r =>
        (r.vehicle_name||"").toLowerCase().includes(q) ||
        (r.plate||"").toLowerCase().includes(q.replace(/\s+/g," ")) ||
        (r.plate||"").toLowerCase().replace(/\s/g,"").includes(q.replace(/\s/g,"")))
    : bukuTrxData;
  const totalHal = Math.max(1, Math.ceil(cocok.length/BUKU_PER_HAL));
  if(bukuHal>totalHal) bukuHal = totalHal;
  const mulai = (bukuHal-1)*BUKU_PER_HAL;
  const potong = cocok.slice(mulai, mulai+BUKU_PER_HAL);
  wadah.innerHTML = potong.length
    // Pembatalan di sini sengaja OWNER SAJA, tidak seperti di Rekap Hari Ini
    // yang kasir pun boleh mengajukan. Rekap cuma melayani hari berjalan —
    // uangnya masih di laci dan kasirnya masih ada; tanggal lampau sudah
    // ditutup dan disetor, jadi mengutak-atiknya urusan owner.
    ? potong.map(r=>trxHTML(r,{koreksi:true, batal: ROLE==="owner"})).join("")
    : '<div class="cat-kosong">'+(q? 'Tidak ada yang cocok dengan "'+esc(bukuCari)+'".' : 'Tidak ada transaksi.')+'</div>';
  $("bukuTrxNav").innerHTML = totalHal<=1 ? "" :
    '<button class="hal-btn" '+(bukuHal<=1?'disabled':'')+' onclick="bukuHal--;renderBukuTrx()">&#8249;</button>'
    +'<span class="hal-info">Hal '+bukuHal+' / '+totalHal+' &middot; '+cocok.length+' transaksi</span>'
    +'<button class="hal-btn" '+(bukuHal>=totalHal?'disabled':'')+' onclick="bukuHal++;renderBukuTrx()">&#8250;</button>';
}
async function pilihTgl(t){
  tglPilih = (tglPilih===t? null : t); // tap ulang tanggal yang sama = tutup detail
  // Hasil rentang lama jangan menggantung di bawah detail satu hari.
  $("detailRange").innerHTML = "";
  $("detailRange").style.display = "none";
  await renderBuku();
  if(tglPilih) $("detailHari").scrollIntoView({behavior:"smooth", block:"start"});
}

/* ---------- RANGE TANGGAL ----------
   Rentangnya datang dari kalender (tahan lalu ketuk), bukan lagi dari sepasang
   input tanggal. Urutan dari/sampai sudah dibereskan pasangKalender(), jadi
   tidak perlu lagi memeriksa "dari" yang melewati "sampai". */
async function cariByRange(from, to){
  try{
    const data = await api("/reports/date-range?from="+from+"&to="+to);
    tglPilih = null;                 // detail satu hari & hasil rentang jangan bertumpuk
    renderRangeResults(data, from, to);
  }catch(e){ gagal(e); }
}

/** Rentang dibatalkan: kembalikan ringkasan ke bulan yang sedang dibuka. */
function tutupRange(){
  $("detailRange").innerHTML = "";
  $("detailRange").style.display = "none";
  renderBuku();
}

function renderRangeResults(data, from, to){
  const s = data.summary;
  $("judulSummary").innerHTML = "📅 "+fmtTgl(from)+" sampai "+fmtTgl(to);
  $("totalBulan").innerHTML =
    '<div class="cat-baris"><span>Hari beroperasi</span><b>'+s.operating_days+' hari &middot; '+s.vehicles+' kendaraan</b></div>'
    +'<div class="cat-baris"><span>Cucian</span><b>'+rp(s.wash_total)+'</b></div>'
    +'<div class="cat-baris"><span>F&amp;B</span><b>'+(s.fnb_total? rp(s.fnb_total):"kosong")+'</b></div>'
    +'<div class="cat-baris"><span>Tip</span><b>'+(s.tip? rp(s.tip):"kosong")+'</b></div>'
    +'<div class="cat-baris"><span>Omzet</span><b class="hijau">'+rp(s.total)+'</b></div>'
    +'<div class="cat-baris"><span>Upah pekerja</span><b class="merah">-'+rp(s.wages)+'</b></div>'
    +'<div class="cat-baris"><span>Pengeluaran</span><b class="merah">-'+rp(s.expenses)+'</b></div>'
    +'<div class="cat-baris tebal"><span>LABA BERSIH</span><b class="'+(s.profit>=0?"hijau":"merah")+'">'+rp(s.profit)+'</b></div>';

  let html = '<div class="cat-blok"><h3>📊 Detail harian</h3>';
  data.days.forEach(d => {
    html += '<div class="hari-baris">'
      +'<div class="hb-info"><div class="hb-tgl">'+fmtTgl(d.date)+'</div>'
      +'<div class="hb-sub">'+d.vehicles+' kendaraan &middot; '+rp(d.omzet)+'</div></div>'
      +'<div class="hb-laba">'+rp(d.profit)+'</div>'
      +'</div>';
  });
  html += '</div>';
  $("detailRange").innerHTML = html;
  $("detailRange").style.display = "block";
  $("detailHari").innerHTML = '';
}

/* ---------- DASHBOARD: LAPORAN & STATISTIK ----------
   Data dari GET /api/reports/stats?period=harian|mingguan|bulanan.
   Grafik pakai Chart.js yang disimpan lokal (public/js/chart.min.js). */
let dashPeriode = "harian";
let grafikTren = null, grafikKomposisi = null;

const WARNA = {tag:"#FFC229", go:"#1B9E62", water:"#E5A800", ink2:"#57503E",
               line:"#E5E0D2", danger:"#C0442B", biru:"#3E7CB1"};

function setPeriode(p){
  dashPeriode = p;
  document.querySelectorAll("#layarDashboard .chip-filter")
    .forEach(c => c.classList.toggle("aktif", c.dataset.p===p));
  renderDashboard();
}

async function renderDashboard(){
  try{
    const r = await api("/reports/stats?period="+dashPeriode);
    const s = r.summary;
    const satuan = dashPeriode==="harian" ? "hari" : (dashPeriode==="mingguan" ? "minggu" : "bulan");

    $("statLabel").textContent = r.label+" · "+s.active_periods+" "+satuan+" ada transaksi";
    $("dashOmzet").textContent     = rp(s.omzet);
    $("dashKendaraan").textContent = s.vehicles+" unit";
    $("dashRata").textContent      = rp(s.avg_omzet);
    $("dashTiket").textContent     = rp(s.avg_ticket);
    $("dashLaba").textContent      = rp(s.profit);
    $("dashTerbaik").textContent   = s.best && s.best.omzet
      ? s.best.label+" — "+rp(s.best.omzet) : "—";

    $("dashRincian").innerHTML =
      '<div class="cat-baris"><span>Cucian</span><b>'+rp(s.wash)+'</b></div>'
      +'<div class="cat-baris"><span>Makanan &amp; minuman</span><b>'+rp(s.fnb)+'</b></div>'
      +'<div class="cat-baris"><span>Tip</span><b>'+rp(s.tip)+'</b></div>'
      +'<div class="cat-baris"><span>Upah pekerja</span><b class="merah">-'+rp(s.wages)+'</b></div>'
      +'<div class="cat-baris"><span>Pengeluaran</span><b class="merah">-'+rp(s.expenses)+'</b></div>'
      +'<div class="cat-baris tebal"><span>LABA BERSIH</span><b class="'+(s.profit>=0?"hijau":"merah")+'">'+rp(s.profit)+'</b></div>';

    gambarTren(r.points);
    gambarKomposisi(s);
    gambarPeringkat("dashLayanan",  r.services,   "layanan");
    gambarPeringkat("dashKategori", r.categories, "jenis kendaraan");
    gambarPeringkat("dashAddon",    r.addons,     "layanan tambahan");
  }catch(e){ gagal(e); }
}

/** Batang = omzet, garis = laba bersih. */
function gambarTren(points){
  const ctx = $("grafikTren");
  if(grafikTren) grafikTren.destroy();
  grafikTren = new Chart(ctx, {
    data: {
      labels: points.map(p=>p.label),
      datasets: [
        {type:"bar", label:"Omzet", data:points.map(p=>p.omzet),
         backgroundColor:WARNA.tag, borderRadius:5, order:2},
        {type:"line", label:"Laba bersih", data:points.map(p=>p.profit),
         borderColor:WARNA.go, backgroundColor:WARNA.go, borderWidth:3,
         tension:.3, pointRadius:3, order:1},
      ],
    },
    options: {
      responsive:true, maintainAspectRatio:false,
      interaction:{mode:"index", intersect:false},
      plugins:{
        legend:{labels:{font:{weight:"700"}, usePointStyle:true, boxWidth:8}},
        tooltip:{callbacks:{label: c => c.dataset.label+": "+rp(c.parsed.y)}},
      },
      scales:{
        x:{grid:{display:false}, ticks:{font:{weight:"700"}, maxRotation:0, autoSkipPadding:12}},
        y:{beginAtZero:true, grid:{color:WARNA.line},
           ticks:{font:{weight:"700"}, callback: v => v>=1000 ? (v/1000)+"rb" : v}},
      },
    },
  });
}

/** Donat komposisi pemasukan: cucian vs F&B vs tip. */
function gambarKomposisi(s){
  const ctx = $("grafikKomposisi");
  if(grafikKomposisi) grafikKomposisi.destroy();
  const nilai = [s.wash, s.fnb, s.tip];
  if(nilai.every(v=>!v)){
    if(ctx.getContext) ctx.getContext("2d").clearRect(0,0,ctx.width,ctx.height);
    return;
  }
  grafikKomposisi = new Chart(ctx, {
    type:"doughnut",
    data:{ labels:["Cucian","Makanan & minuman","Tip"], datasets:[{data:nilai,
      backgroundColor:[WARNA.tag, WARNA.biru, WARNA.go], borderWidth:2, borderColor:"#fff"}] },
    options:{ responsive:true, maintainAspectRatio:false, cutout:"58%",
      plugins:{ legend:{position:"bottom", labels:{font:{weight:"700"}, usePointStyle:true, boxWidth:8}},
        tooltip:{callbacks:{label: c => c.label+": "+rp(c.parsed)}} } },
  });
}

/** Daftar peringkat + bar proporsi sederhana. */
function gambarPeringkat(elId, rows, satuan){
  const el = $(elId);
  if(!rows || !rows.length){
    el.innerHTML = '<div class="cat-kosong">Belum ada data '+satuan+' pada periode ini.</div>';
    return;
  }
  const maks = Math.max(...rows.map(r=>r.total), 1);
  el.innerHTML = rows.map((r,i) =>
    '<div class="rank-baris">'
    + '<span class="rank-no">'+(i+1)+'</span>'
    + '<span class="rank-isi">'
    +   '<span class="rank-atas"><b>'+esc(r.label)+'</b><b>'+rp(r.total)+'</b></span>'
    +   '<span class="rank-bar"><span style="width:'+Math.round(r.total/maks*100)+'%"></span></span>'
    +   '<span class="rank-bawah">'+r.count+'x</span>'
    + '</span></div>'
  ).join("");
}

/* ---------- RIWAYAT / PENCARIAN GAGAL ---------- */
async function renderRiwayat(){
  try{
    let trx = await api("/transactions?date="+hariIni());
    // Disaring di sini, bukan di server: daftarnya sudah terambil utuh untuk
    // layar ini dan book_id tiap transaksi ikut terbawa, jadi menyaringnya di
    // sisi klien tidak menambah permintaan baru.
    if(rekapBukuPilih!==null){
      trx = trx.filter(r => r.book_id === rekapBukuPilih);
    }
    // Transaksi yang sudah dibatalkan hanya untuk mata owner — permintaan
    // owner sendiri (21/09): di layar kasir, baris batal tidak boleh muncul
    // walau cuma sebagai coretan. Uangnya memang sudah tidak dihitung di mana
    // pun (Transaction::valid()), jadi yang disembunyikan murni tampilannya.
    //
    // Yang MENUNGGU approval tetap tampil: transaksinya masih sah, dan kasir
    // perlu melihat bahwa pengajuannya sedang ditunggu. Begitu owner
    // menyetujui, barisnya hilang dari layar kasir.
    if(ROLE!=="owner") trx = trx.filter(r => !r.voided_at);
    $("daftarRiwayat").innerHTML = trx.length===0
      ? '<div class="cat-kosong">Belum ada transaksi'+(rekapBukuPilih!==null? ' di buku ini':'')+'.</div>'
      : trx.map(r=>trxHTML(r,{batal:true})).join("");
  }catch(e){ gagal(e); }
}

/* ---------- GEMINI: sambungan otomatis dari kolom pencarian ----------
   Dulu AI punya tombol melayang & modal sendiri dengan kolom ketik kedua.
   Sekarang ia menyatu: kasir cukup mengetik di satu kolom, dan kalau daftar
   kendaraan lokal tidak punya jawabannya, AI yang meneruskan.

   Kemudahan itu ada harganya, dan harganya kuota. Pencarian lokal berjalan
   tiap 250 ms sambil kasir mengetik, jadi menanyakan AI setiap kali hasil
   lokal kosong berarti "panther" menembak Gemini empat kali: "pant",
   "panth", "panthe", "panther". Cache 30 hari di GeminiVehicleService TIDAK
   menahan ini — tiap potongan adalah kunci cache yang berbeda. Justru pola
   inilah yang menghabiskan kuota gratis pada 30 Agustus 2026.

   Karena itu ada empat lapis penahan sebelum satu panggilan terjadi:
     1. jeda diam 1,2 detik — jauh lebih panjang dari debounce pencarian,
        supaya yang ditanyakan hanya kata yang sudah selesai diketik;
     2. minimal 4 huruf — "av" atau "bmw" tidak pernah sampai ke AI;
     3. satu kata cuma DITANYAKAN sekali per sesi — jawabannya diingat, jadi
        mengetik kata yang sama lagi menampilkan jawaban yang sama tanpa
        menembak API;
     4. throttle di routes/api.php sebagai jaring terakhir di sisi server.  */
const AI_JEDA_MS   = 1200;
const AI_MIN_HURUF = 4;

let aiUsulan  = null;
let aiTimer   = null;
/* kata -> jawaban yang sudah didapat sesi ini. Yang diingat JAWABANNYA, bukan
   sekadar "pernah ditanya": kasir yang mengetik kata sama untuk kedua kalinya
   berhak melihat hasil yang sama, bukan kotak kosong. */
let aiJawaban = new Map();

function batalTanyaAi(){ clearTimeout(aiTimer); aiTimer = null; }

/* Kosongkan kotak AI berikut usulan yang menempel padanya. Keduanya HARUS
   dibuang bersama: usulan yang tertinggal padahal kartunya sudah hilang adalah
   kendaraan siap-simpan tanpa apa pun di layar yang menyebutkannya. */
function kosongkanAi(){ $("hasilAi").innerHTML = ""; aiUsulan = null; }

/* Antre bertanya ke AI. Tidak langsung menembak: baru jalan kalau kasir
   benar-benar berhenti mengetik selama AI_JEDA_MS. */
function jadwalTanyaAi(q){
  batalTanyaAi();
  if(q.length < AI_MIN_HURUF){ kosongkanAi(); return; }

  const ingatan = aiJawaban.get(q.toLowerCase());
  if(ingatan){ gambarJawabanAi(q, ingatan); return; }   // sudah tahu jawabannya

  $("hasilAi").innerHTML = '<div class="ai-antre">&#10024; AI sedang mencoba menebak&hellip;</div>';
  aiTimer = setTimeout(() => tanyaAiOtomatis(q), AI_JEDA_MS);
}

/* Kata sambung & kata depan: tidak berarti apa-apa kalau jadi kata terakhir.
   Memotong di batas kata saja menghasilkan "...merek mobil 'Mazda' yang…",
   yang menggantung dan membuat kalimatnya terbaca rusak, bukan terpotong. */
const EKOR_BUANG = new Set(["yang","yg","dan","atau","dari","untuk","dengan","sebagai",
  "pada","ke","di","dalam","itu","ini","adalah","ialah","merupakan","karena","serta",
  "oleh","akan","juga","agar","supaya","termasuk","secara","kalau","bila","namun","tetapi","tapi"]);

/* Potong alasan AI di batas kata. Prompt sudah meminta maksimal 8 kata, tapi
   jawaban yang terlanjur tersimpan di ingatan server (30 hari) masih yang
   panjang-panjang — dan kartu ini dibaca sambil pelanggan menunggu. */
function ringkasAlasan(teks, maks = 70){
  teks = String(teks || "").trim();
  if(teks.length <= maks) return teks;

  const spasi = teks.slice(0, maks).lastIndexOf(" ");
  const kata  = (spasi > 30 ? teks.slice(0, spasi) : teks.slice(0, maks)).split(" ");

  // Sisakan minimal 3 kata: lebih pendek dari itu bukan alasan lagi.
  while(kata.length > 3 && EKOR_BUANG.has(kata[kata.length-1].toLowerCase().replace(/[.,;:]$/, ""))){
    kata.pop();
  }

  return kata.join(" ").replace(/[.,;:]$/, "") + "…";
}

/* Satu-satunya tempat jawaban AI digambar — dipakai jawaban yang baru datang
   maupun yang diambil dari ingatan, supaya keduanya tidak pernah berbeda. */
function gambarJawabanAi(q, ingatan){
  // Judulnya menyesuaikan: kalau daftar lokal ikut tampil di atas, tebakan AI
  // ini posisinya sebagai pembanding, bukan sebagai satu-satunya jawaban.
  const adaHasilLokal = !!$("hasilCari").querySelector(".kartu-mobil");

  if(ingatan.status === "gagal"){
    $("hasilAi").innerHTML =
      '<div class="gagal">AI gagal dihubungi:<br>&#9888; '+esc(ingatan.pesan)
      + '<br><button class="btn-tanya-inline" onclick="tanyaAiOtomatis(\''+esc(q).replace(/'/g,"\\'")+'\')">'
      + '&#10024; Coba tanya AI lagi</button></div>';
    return;
  }

  if(ingatan.status === "nihil"){
    $("hasilAi").innerHTML = adaHasilLokal
      ? '<div class="ai-antre">AI juga tidak yakin ada kendaraan lain yang cocok.</div>'
      : '<div class="gagal">AI juga tidak yakin ini kendaraan apa.'
        + '<br>Pilih jenis kendaraannya di bawah &#128071;</div>';
    return;
  }

  const r = ingatan.data;
  aiUsulan = r;   // wajib: tombol Simpan membacanya, termasuk di jalur ingatan

  /* Tebakan AI yang berbeda dari katalog TIDAK dipakai — server sudah
     memulangkan kategori milik katalog (lihat AiVehicleController::classify).
     Yang digambar di sini selisihnya, terang-terangan.

     Dulu kartu ini cuma menyebut satu harga, dan itu harga versi AI: 30
     Agustus 2026, "citi" dan "raise" membuat Honda City & Toyota Raize
     tertagih 35rb padahal katalog owner menyebut keduanya 40rb. Tidak ada
     yang bisa melihatnya, karena tidak ada satu baris pun di layar yang
     menyebut bahwa katalog berkata lain. */
  const koreksi = r.dikoreksi_katalog
    ? '<div class="ai-dikoreksi">AI menebak <span class="coret">'+esc(r.ai_category_label)
      + (r.ai_price!=null ? ' &middot; '+rp(r.ai_price) : '')+'</span><br>'
      + 'Yang dipakai daftarmu: <b>'+esc(r.category_label)+' &middot; '+rp(r.price)+'</b>'
      + (r.reason? '<div class="ai-alasan" style="margin-top:6px">Alasan AI: "'+esc(ringkasAlasan(r.reason))+'"</div>' : '')
      + '</div>'
    : (r.reason? '<div class="ai-alasan">"'+esc(ringkasAlasan(r.reason))+'"</div>' : '');

  $("hasilAi").innerHTML =
    '<div class="ai-hasil">'
    + '<div class="ai-tebakan">'
    +   (adaHasilLokal ? "&#10024; Bukan yang di atas? Tebakan AI:"
                       : "&#10024; Tidak ada di daftar &mdash; tebakan AI:")
    + '</div>'
    + siluetSVG(bentukKat(r.category),130)
    + '<div class="kk-nama">'+esc(r.name)+'</div>'
    + '<div class="kk-kat">'+esc(r.category_label)+' &middot; '+rp(r.price)+'</div>'
    + koreksi
    + (r.already_exists
        ? '<div class="ai-sudah-ada">&#9989; Sudah ada di daftar</div>'
          + '<button class="btn-besar" style="margin-top:8px" onclick="simpanGemini()">&#10003; Pakai</button>'
        : '<button class="btn-besar" style="margin-top:8px" onclick="simpanGemini()">&#10003; Pakai &amp; simpan</button>'
      )
    // Satu kalimat saja. Kasir membaca kartu ini sambil pelanggan menunggu di
    // depan meja; kalimat kedua ("masuk sebagai tebakan sampai owner
    // memeriksanya") toh bukan urusan dia — yang menindaklanjuti owner, dan
    // owner sudah diberi tahu lewat angka merah di menu Pengaturan.
    + '<div class="ai-catatan">AI bisa salah &mdash; cocokkan dengan mobil di depan Anda.</div>'
    + '</div>';
}

async function tanyaAiOtomatis(q){
  aiTimer  = null;
  aiUsulan = null;
  $("hasilAi").innerHTML = '<div class="ai-loading">&#10024; Menanyakan ke AI&hellip;</div>';
  const kunci = q.toLowerCase();
  try{
    const r = await api("/ai/classify-vehicle",{method:"POST",body:{query:q}});
    const ingatan = r.recognized ? {status:"ada", data:r} : {status:"nihil"};
    aiJawaban.set(kunci, ingatan);
    // Kasir sudah mengetik hal lain sejak tadi: jawaban ini bukan miliknya lagi
    // — tapi tetap disimpan di atas, supaya kalau ia kembali ke kata ini tidak
    // perlu menembak API lagi.
    if($("inputCari").value.trim()!==q) return;
    gambarJawabanAi(q, ingatan);
  }catch(e){
    // Kegagalan IKUT diingat supaya mengetik ulang tidak menembak API lagi:
    // kalau penyebabnya kuota habis, mengulang otomatis menggali lubang yang
    // sama. Yang tersimpan tetap menampilkan tombol coba-lagi, jadi kasir masih
    // punya jalan keluar — lewat ketukan sadar, bukan otomatis.
    const ingatan = {status:"gagal", pesan:e.message};
    aiJawaban.set(kunci, ingatan);
    if($("inputCari").value.trim()!==q) return;
    gambarJawabanAi(q, ingatan);
  }
}

async function simpanGemini(){
  if(!aiUsulan) return;
  try{
    if(!aiUsulan.already_exists){
      await api("/ai/save-vehicle",{method:"POST",body:{name:aiUsulan.name, category:aiUsulan.category}});
    }
    $("inputCari").value = "";
    $("hasilCari").innerHTML = "";
    $("hasilAi").innerHTML = "";
    pilihHasil(aiUsulan.name, aiUsulan.category); // langsung lanjut ke layar konfirmasi transaksi
  }catch(e){ gagal(e); }
}

/* ---------- F&B: JUAL ---------- */
let produk = [];
let keranjang = {};          // {product_id: qty}
let bayarFnb = "cash";
let jenisFnbBaru = "minuman";
let draftFnbAktif = null;    // id draft F&B yang sedang dilanjutkan (dihapus server saat tersimpan)
let penjualanFnb = [];       // riwayat penjualan hari ini — dipakai layar pembatalan

async function renderFnb(){
  try{
    produk = await api("/products?active=1");
    gambarGridFnb(); gambarKeranjang();
    await renderFnbDrafts();
    const sales = await api("/fnb-sales?date="+hariIni());
    penjualanFnb = sales;
    $("riwayatFnb").innerHTML = sales.length===0
      ? '<div class="cat-kosong">Belum ada penjualan hari ini.</div>'
      : sales.map(barisRiwayatFnb).join("");
  }catch(e){ gagal(e); }
}
/** Nama penitip sebuah produk; 'Titipan' kalau relasinya tidak ikut terkirim. */
function namaPenitip(p){
  return (p.consignor && p.consignor.name) || "Titipan";
}

function gambarGridFnb(){
  $("gridFnb").innerHTML = produk.length===0
    ? '<div class="cat-kosong">Menu masih kosong.'
      +'<br><button class="btn-tanya-inline" onclick="pergi(\'layarMenuFnb\')">&#10133; Tambah Menu Makanan/Minuman</button></div>'
    : produk.map(p =>
        '<button class="fnb-item'+(p.stock<=0?' habis':'')+(p.consignor_id?' titipan':'')+'" '+(p.stock<=0?'disabled':'onclick="tambahKeranjang('+p.id+')"')+'>'
        +(keranjang[p.id]? '<span class="fnb-qty">'+keranjang[p.id]+'</span>':'')
        // Barang titipan memakai baris jenis untuk menyebut PEMILIKNYA. Kasir
        // tidak perlu berbuat apa-apa dengan informasi itu — tapi begitu ada
        // pelanggan bertanya atau penitipnya datang, jawabannya ada di layar.
        +'<span class="fnb-jenis">'+(p.consignor_id
            ? '&#129309; '+esc(namaPenitip(p))
            : (p.type==="makanan"?"&#127836; Makanan":"&#129380; Minuman"))+'</span>'
        +'<span class="fnb-nama">'+esc(p.name)+'</span>'
        +'<span class="fnb-harga">'+rp(p.price)+'</span>'
        +'<span class="fnb-stok">'+(p.stock>0?'Stok '+p.stock:'Habis')+'</span>'
        +'</button>'
      ).join("");
}
function gambarKeranjang(){
  const ids = Object.keys(keranjang);
  let total = 0;
  $("keranjangFnb").innerHTML = ids.length===0
    ? '<div class="cat-kosong">Belum ada item. Tap menu di atas.</div>'
    : ids.map(id => {
        const p = produk.find(x=>x.id==id); if(!p) return "";
        const qty = keranjang[id];
        total += p.price*qty;
        return '<div class="krj-baris">'
          +'<span class="krj-nama">'+esc(p.name)+'</span>'
          +'<button class="btn-qty" onclick="ubahQty('+id+',-1)">&minus;</button>'
          +'<b>'+qty+'</b>'
          +'<button class="btn-qty" onclick="ubahQty('+id+',1)">+</button>'
          +'<span class="krj-sub">'+rp(p.price*qty)+'</span>'
          +'</div>';
      }).join("");
  $("totalFnb").textContent = rp(total);

  // Tip ditampilkan TERPISAH dari TOTAL F&B, bukan dijumlahkan ke dalamnya:
  // total itu omzet menu, tip punya barisnya sendiri di rekap. Barisan "uang
  // diterima" ditambahkan supaya kasir tetap tahu berapa yang harus ditagih.
  const tip = tipFnb();
  $("tipFnbInfo").classList.toggle("hidden", tip===0);
  $("tipFnbInfo").innerHTML = 'Tip <b>'+rp(tip)+'</b> &middot; uang diterima <b>'+rp(total+tip)+'</b>';
}

/** Nominal tip yang sedang diketik di layar Jual Makanan/Minuman. */
function tipFnb(){
  const v = parseInt(($("inTipFnb")||{}).value, 10);
  return (isNaN(v) || v<0) ? 0 : v;
}
function tambahKeranjang(id){
  const p = produk.find(x=>x.id==id);
  if(!p || p.stock<=0) return;
  if((keranjang[id]||0) >= p.stock){
    Swal.fire({icon:"warning", title:"Stok tidak cukup", text:"Sisa stok "+p.name+": "+p.stock, confirmButtonColor:"#1B9E62"});
    return;
  }
  keranjang[id]=(keranjang[id]||0)+1; gambarGridFnb(); gambarKeranjang();
}
function ubahQty(id,d){
  const p = produk.find(x=>x.id==id);
  const next = (keranjang[id]||0)+d;
  if(d>0 && p && next>p.stock){
    Swal.fire({icon:"warning", title:"Stok tidak cukup", text:"Sisa stok "+p.name+": "+p.stock, confirmButtonColor:"#1B9E62"});
    return;
  }
  keranjang[id]=next;
  if(keranjang[id]<=0) delete keranjang[id];
  gambarGridFnb(); gambarKeranjang();
}
function setBayarFnb(m){
  bayarFnb=m;
  $("fnbCash").classList.toggle("aktif", m==="cash");
  $("fnbTf").classList.toggle("aktif", m==="tf");
  if(m==="tf") bukaQris(); // pelanggan langsung bisa scan
}
function totalKeranjang(){
  return Object.entries(keranjang).reduce((t,[id,qty])=>{
    const p = produk.find(x=>x.id==id); return t + (p? p.price*qty : 0);
  },0);
}
function bukaQris(){
  $("qrisTotal").textContent = "Total: "+rp(totalKeranjang());
  $("qrisOverlay").classList.add("buka");
}
function tutupQris(){ $("qrisOverlay").classList.remove("buka"); }
async function simpanFnb(){
  const items = Object.entries(keranjang).map(([id,qty])=>({product_id:+id, qty}));
  if(items.length===0){
    Swal.fire({icon:"warning", title:"Pesanan masih kosong", text:"Tap menu di atas untuk menambah item dulu.", confirmButtonColor:"#1B9E62"});
    return;
  }
  try{
    const tip = tipFnb();
    const body = {payment_method:bayarFnb, items, tip};
    if(draftFnbAktif) body.draft_id = draftFnbAktif; // draft ikut terhapus di server
    const sale = await api("/fnb-sales",{method:"POST",body});
    lepasDraftFnb();   // draftnya sudah tidak ada — jangan dipakai lagi
    keranjang={}; bayarFnb="cash"; setBayarFnb("cash");
    if($("inTipFnb")) $("inTipFnb").value = "";
    renderFnb();
    // Resi hanya dibuka kalau kasir menekan tombolnya. Notifikasi tetap
    // menutup sendiri seperti dulu (timer), supaya penjualan cepat yang tidak
    // butuh resi tidak bertambah satu ketukan.
    Swal.fire({icon:"success", title:"Penjualan tersimpan",
      text:"Total "+rp(sale.total)+(sale.tip? " + tip "+rp(sale.tip) : "")
           +" ("+(sale.payment_method==="tf"?"Transfer":"Cash")+") · "+nomorNota("F", sale.id),
      timer:5000, timerProgressBar:true,
      showConfirmButton:true, confirmButtonText:"&#129534; Lihat Resi", confirmButtonColor:"#1B9E62",
      showCancelButton:true, cancelButtonText:"Tutup",
    }).then(r=>{ if(r.isConfirmed) lihatResi(dataResiFnb(sale)); });
  }catch(e){ gagal(e); }
}

/* Satu baris riwayat penjualan F&B, lengkap dengan tombol batal.
   Bentuknya sengaja mengikuti baris riwayat transaksi cuci (trxHTML):
   coretan + chip BATAL untuk yang sudah dibatalkan, chip MENUNGGU APPROVAL
   untuk pengajuan kasir yang belum diputus owner. */
function barisRiwayatFnb(sl){
  const batal    = sl.void_status==="batal";
  const menunggu = sl.void_status==="menunggu";
  // Cucian yang menaungi pesanan ini sudah dibatalkan: pesanannya ikut gugur
  // sendiri lewat FnbSale::valid(), jadi tidak ada yang perlu dibatalkan lagi.
  const indukBatal = !!(sl.transaction && sl.transaction.voided_at);

  return '<div class="cat-baris'+(batal||indukBatal?' trx-batal':'')+'"><span>'
    + '<span class="waktu">'+jam(sl.created_at)+'</span> &middot; '
    + sl.items.map(i=>esc(i.product_name)+' x'+i.qty).join(", ")
    + ' <span class="'+(sl.payment_method==="tf"?"chip-tf":"chip-cash")+'">'+(sl.payment_method==="tf"?"TF":"CASH")+'</span>'
    // Penanda bahwa pesanan ini menempel pada sebuah cucian — supaya kasir
    // tahu sebelum menekan tombol batal, bukan baru diberi tahu setelahnya.
    + (sl.transaction? ' <span class="chip-nempel" title="Menempel pada cucian">&#128663; '
        + esc(sl.transaction.plate || sl.transaction.vehicle_name || "cucian") + '</span>' : '')
    + (batal?' <span class="chip-batal">BATAL</span>':'')
    + (indukBatal && !batal?' <span class="chip-batal">CUCIAN BATAL</span>':'')
    + (menunggu?' <span class="chip-tunggu">MENUNGGU APPROVAL</span>':'')
    + (sl.tip? ' <span class="chip-tip">&#128176; tip '+rp(sl.tip)+'</span>':'')
    + (sl.created_by?' <span class="waktu">&#128100; '+esc(sl.created_by)+'</span>':'')
    + '</span><span><b>'+rp(sl.total)+'</b>'
    // Cetak ulang hanya untuk penjualan yang berdiri sendiri. Pesanan yang
    // menempel pada cucian tercetak di resi cucian itu — dicetak ulang dari Rekap.
    + ((!sl.transaction && !batal)
        ? ' <button class="btn-cetak-mini" title="Lihat resi '+nomorNota("F", sl.id)
          + '" onclick="lihatResiFnb('+sl.id+')">&#129534;</button>'
        : '')
    // Kasir tetap boleh menekan — bedanya jadi PENGAJUAN, bukan pembatalan.
    + ((!batal && !menunggu && !indukBatal)
        ? ' <button class="btn-void" title="'+(ROLE==="owner"?"Batalkan penjualan":"Ajukan pembatalan")
          + '" onclick="voidFnb('+sl.id+')">&#10005;</button>'
        : '')
    + '</span></div>';
}

/* ---------- PEMBATALAN PENJUALAN F&B ----------
   Alur & aturannya sama persis dengan pembatalan transaksi cuci: kasir
   MENGAJUKAN (uangnya tetap dihitung sampai owner memutuskan), owner
   membatalkan langsung. Stok menu dikembalikan saat benar-benar dibatalkan.

   Bedanya cuma satu: pesanan F&B bisa MENEMPEL pada sebuah cucian (satu
   resi). Membatalkan sisi F&B-nya saja membuat resi itu punya separuh
   laporan yang hilang, jadi kasir diperingatkan dulu — lengkap dengan plat
   kendaraannya — sebelum boleh melanjutkan. */
let voidFnbId = null;

async function voidFnb(id){
  const sl = penjualanFnb.find(x=>x.id===id);
  if(!sl) return;

  if(sl.transaction){
    const t = sl.transaction;
    const nama = esc(t.vehicle_name || "kendaraan");
    const plat = t.plate ? esc(t.plate) : "tanpa plat";
    const lanjut = await Swal.fire({
      icon: "warning",
      title: "Pesanan ini menempel pada cucian",
      html: 'Makanan/minuman ini dipesan bareng cucian <b>'+nama+'</b> ('+plat+') '
          + 'senilai <b>'+rp(t.total)+'</b> — keduanya satu resi.<br><br>'
          + 'Kalau bagian F&amp;B-nya saja dibatalkan, resi itu jadi tidak cocok '
          + 'dengan laporan: cuciannya tetap terhitung, makanannya hilang.<br><br>'
          + '<b>Kalau yang salah justru cuciannya</b>, batalkan lewat transaksi '
          + 'cuci itu di Rekap Hari Ini — F&amp;B-nya ikut gugur otomatis.',
      showCancelButton: true,
      confirmButtonText: "Tetap batalkan F&B-nya",
      cancelButtonText: "Batal, jangan jadi",
      confirmButtonColor: "#C0392B",
      cancelButtonColor: "#57503E",
    });
    if(!lanjut.isConfirmed) return;
  }

  voidFnbId = id;
  $("voidFnbRingkas").innerHTML = sl.items.map(i=>esc(i.product_name)+' x'+i.qty).join(", ")
    + ' &middot; ' + rp(sl.total);
  $("voidFnbErr").textContent = "";
  $("voidFnbAlasan").value = "";
  const milikOwner = ROLE==="owner";
  $("voidFnbJudul").innerHTML = milikOwner ? "Batalkan penjualan" : "Ajukan pembatalan";
  $("voidFnbCatatan").innerHTML = milikOwner
    ? "Penjualan tetap tersimpan sebagai jejak, tapi dikeluarkan dari rekap uang. Stok menunya dikembalikan."
    : "Pembatalan perlu disetujui owner. Sampai disetujui, penjualan ini <b>masih dihitung</b> di rekap.";
  $("voidFnbKirim").innerHTML = milikOwner ? "Batalkan" : "Kirim Pengajuan";
  $("voidFnbOverlay").classList.add("buka");
  setTimeout(()=>$("voidFnbAlasan").focus(), 50);
}

function tutupVoidFnb(){ $("voidFnbOverlay").classList.remove("buka"); }

async function kirimVoidFnb(){
  const alasan = $("voidFnbAlasan").value.trim();
  if(!alasan){ $("voidFnbErr").textContent = "Alasan wajib diisi."; $("voidFnbAlasan").focus(); return; }
  try{
    await api("/fnb-sales/"+voidFnbId+"/void",{method:"POST",body:{reason:alasan}});
    tutupVoidFnb();
    await renderFnb();   // stok & riwayat ikut tersegarkan
    Swal.fire({icon:"success", showConfirmButton:false, timer:2200,
      title: ROLE==="owner" ? "Penjualan dibatalkan" : "Pengajuan terkirim",
      text:  ROLE==="owner" ? "Stok menunya sudah dikembalikan."
                            : "Menunggu persetujuan owner."});
  }catch(e){ $("voidFnbErr").textContent = e.message; }
}

/* ---------- DRAFT F&B: catat pesanan dulu, bayar belakangan ----------
   Kembaran draft cucian (lihat renderDrafts di atas), dengan alasan yang
   sama: draft hidup di SERVER supaya tidak hilang saat tablet ditutup dan
   bisa dilanjutkan kasir lain. Harga sengaja TIDAK ikut disimpan — cuma
   product_id & qty — jadi harga baru dikunci server saat draft benar-benar
   jadi penjualan, dan draft lama tidak memakai harga kedaluwarsa.

   Stok juga belum dipotong selama masih draft. Konsekuensinya draft bisa
   gagal disimpan nanti kalau stoknya keburu habis terjual — itu memang
   benar, karena barangnya nyata-nyata sudah tidak ada. */
let draftsFnb = [];

async function renderFnbDrafts(){
  try{
    draftsFnb = await api("/fnb-drafts");
  }catch(e){
    if(e.message===ERR_LOGIN || e.message===ERR_SHIFT) return;
    draftsFnb = [];
  }
  const blok = $("blokDraftFnb");
  if(!blok) return;
  blok.classList.toggle("hidden", draftsFnb.length===0);
  $("draftFnbJumlah").textContent = draftsFnb.length ? draftsFnb.length : "";
  $("daftarDraftFnb").innerHTML = draftsFnb.map(d => {
    // Nama menu diambil dari daftar produk yang sedang dimuat, bukan dari
    // draft: kalau menunya sempat diganti nama, yang tampil tetap yang kini.
    const isi = (d.items||[]).map(it => {
      const p = produk.find(x=>x.id===it.product_id);
      return (p ? esc(p.name) : "menu terhapus")+" x"+it.qty;
    }).join(", ");
    const total = (d.items||[]).reduce((t,it)=>{
      const p = produk.find(x=>x.id===it.product_id); return t + (p? p.price*it.qty : 0);
    },0);
    // Isi pesanan yang jadi judul barisnya — tanpa kolom nama, itulah satu-
    // satunya penanda yang benar-benar membedakan satu draft dari yang lain.
    return '<div class="draft-baris'+(draftFnbAktif===d.id?" aktif":"")+'">'
      + '<button class="draft-utama" onclick="lanjutDraftFnb('+d.id+')">'
      +   '<span class="draft-nama">'+(isi || "pesanan kosong")+'</span>'
      +   (d.note? '<span class="draft-note">&#128221; '+esc(d.note)+'</span>' : '')
      +   '<span class="draft-jam">'+jam(d.created_at)+(d.created_by? ' &middot; '+esc(d.created_by):'')
      +     ' &middot; '+rp(total)+(d.tip? ' + tip '+rp(d.tip) : '')+'</span>'
      + '</button>'
      + '<button class="btn-hapus-pk" title="Hapus draft" onclick="hapusDraftFnb('+d.id+')">&#10005;</button>'
      + '</div>';
  }).join("");
}

/* Kembalikan tampilan ke mode "pesanan baru" tanpa mengosongkan keranjang —
   dipakai setelah draft tersimpan jadi penjualan maupun saat kasir melepas. */
function lepasDraftFnb(){
  draftFnbAktif = null;
  $("judulKeranjang").innerHTML = "&#128722; Pesanan";
  $("btnDraftFnb").innerHTML = "&#128190; Simpan Draft (belum bayar)";
  $("btnBatalDraftFnb").classList.add("hidden");
}

async function simpanDraftFnb(){
  const items = Object.entries(keranjang).map(([id,qty])=>({product_id:+id, qty}));
  if(items.length===0){
    Swal.fire({icon:"warning", title:"Pesanan masih kosong", text:"Tap menu di atas untuk menambah item dulu.", confirmButtonColor:"#1B9E62"});
    return;
  }
  const body = {items, tip: tipFnb()};
  try{
    // Draft yang sedang dibuka cukup diperbarui, jangan sampai jadi dua baris.
    if(draftFnbAktif) await api("/fnb-drafts/"+draftFnbAktif,{method:"PATCH",body});
    else              await api("/fnb-drafts",{method:"POST",body});
    const tadinyaDraft = draftFnbAktif;
    lepasDraftFnb();
    keranjang={};
    if($("inTipFnb")) $("inTipFnb").value = "";
    gambarGridFnb(); gambarKeranjang();
    await renderFnbDrafts();
    Swal.fire({toast:true, position:"top-end", icon:"success",
      title: tadinyaDraft? "Draft diperbarui" : "Tersimpan sebagai draft",
      showConfirmButton:false, timer:2000});
  }catch(e){ gagal(e); }
}

function lanjutDraftFnb(id){
  const d = draftsFnb.find(x=>x.id===id);
  if(!d) return;
  keranjang = {};
  const hilang = [];
  (d.items||[]).forEach(it => {
    const p = produk.find(x=>x.id===it.product_id);
    // Menu yang sudah dihapus/dinonaktifkan sejak draft dibuat tidak bisa
    // dijual lagi — dilewati dan disebutkan, bukan diam-diam dibuang.
    if(!p){ hilang.push("menu terhapus"); return; }
    keranjang[p.id] = it.qty;
  });
  draftFnbAktif = d.id;
  if($("inTipFnb")) $("inTipFnb").value = d.tip ? d.tip : "";
  // Jam pencatatan dipakai sebagai penanda draft yang sedang dibuka: cukup
  // untuk membedakan beberapa draft, tanpa perlu kasir mengetik nama apa pun.
  $("judulKeranjang").innerHTML = "&#128722; Pesanan &middot; <span class=\"draft-tanda\">draft "
    + esc(jam(d.created_at)) + "</span>";
  $("btnDraftFnb").innerHTML = "&#128190; Perbarui Draft";
  $("btnBatalDraftFnb").classList.remove("hidden");
  gambarGridFnb(); gambarKeranjang(); renderFnbDrafts();
  $("keranjangFnb").scrollIntoView({block:"center"});
  if(hilang.length){
    Swal.fire({icon:"warning", title:"Sebagian menu tidak ada lagi",
      text:hilang.length+" item di draft ini sudah dihapus dari daftar menu dan tidak ikut dimuat.",
      confirmButtonColor:"#1B9E62"});
  }
}

/* Lepas dari draft TANPA menghapusnya: keranjang dikosongkan supaya isinya
   tidak tidak sengaja tersimpan sebagai penjualan pesanan lain. */
function batalDraftFnb(){
  lepasDraftFnb();
  keranjang={};
  if($("inTipFnb")) $("inTipFnb").value = "";
  gambarGridFnb(); gambarKeranjang(); renderFnbDrafts();
}

async function hapusDraftFnb(id){
  if(!await konfirmasiHapus()) return;
  try{
    await api("/fnb-drafts/"+id,{method:"DELETE"});
    if(draftFnbAktif===id) batalDraftFnb();
    else await renderFnbDrafts();
  }catch(e){ gagal(e); }
}

/* ---------- F&B: KELOLA MENU ---------- */
function setJenisFnb(j){
  jenisFnbBaru=j;
  $("jenisMakanan").classList.toggle("aktif", j==="makanan");
  $("jenisMinuman").classList.toggle("aktif", j==="minuman");
}
/* ---------- PENGATURAN: KATALOG CUCI (owner) ---------- */
const NAMA_BENTUK = {moto:"Motor", hatch:"Mobil kecil", mpv:"Mobil sedang", van:"Mobil besar / van"};
let katalogKategori = [], katalogLayanan = [];

async function renderKatalog(){
  try{
    [katalogKategori, katalogLayanan] = await Promise.all([
      api("/wash-categories"), api("/wash-services"),
    ]);
    gambarDaftarKategori();
    gambarDaftarLayanan();
  }catch(e){ gagal(e); }
}
function gambarDaftarKategori(){
  $("daftarKategori").innerHTML = katalogKategori.map(k => {
    const harga = katalogLayanan
      .filter(s => k.prices[s.slug]!==undefined)
      .map(s => esc(s.label)+" "+rp(k.prices[s.slug])+" / upah "+rp((k.wages&&k.wages[s.slug])||0)).join(" &middot; ");
    return '<div class="kat-baris">'
      + '<div class="kat-info">'
      +   '<div class="kat-nama">'+esc(k.label)+'</div>'
      +   '<div class="kat-detail">'+(harga || "belum ada harga")+'</div>'
      +   '<div class="kat-detail">'+esc(NAMA_BENTUK[k.shape]||k.shape)
      +     (k.used_count? ' &middot; '+k.used_count+' transaksi' : '')+'</div>'
      + '</div>'
      + '<button class="btn-edit-pk" onclick="editKategori(\''+jsStr(k.slug)+'\')" title="Ubah">&#9998;</button>'
      + '<button class="btn-hapus-pk" onclick="hapusKategori('+k.id+',\''+jsStr(k.label)+'\')" title="Hapus">&#10005;</button>'
      + '</div>';
  }).join("");
}
function gambarDaftarLayanan(){
  $("daftarLayanan").innerHTML = katalogLayanan.map(s =>
    '<div class="pk-baris">'
    + '<span class="pk-nama">'+esc(s.label)
    +   (s.used_count? ' <span class="waktu">'+s.used_count+' transaksi</span>' : '')+'</span>'
    + '<button class="btn-edit-pk" onclick="editLayanan('+s.id+',\''+jsStr(s.label)+'\')" title="Ubah nama">&#9998;</button>'
    + '<button class="btn-hapus-pk" onclick="hapusLayanan('+s.id+',\''+jsStr(s.label)+'\')" title="Hapus">&#10005;</button>'
    + '</div>'
  ).join("");
}

/** Form tambah/ubah jenis kendaraan. slug null = tambah baru. */
async function editKategori(slug){
  const k = slug ? katalogKategori.find(x=>x.slug===slug) : null;
  const barisLayanan = katalogLayanan.map(s => {
    const ada = k ? k.prices[s.slug]!==undefined : true;
    const nilaiHarga = k && ada ? k.prices[s.slug] : 0;
    const nilaiUpah = k && ada && k.wages ? (k.wages[s.slug] || 0) : 0;
    return '<label class="sw-layanan">'
      + '<input type="checkbox" class="sw-ada" data-svc="'+s.slug+'"'+(ada?" checked":"")+'>'
      + '<span class="sw-label">'+esc(s.label)+'</span>'
      + '<span class="mini-field"><span class="mini-label">Harga</span>'
      +   '<input type="number" class="sw-harga" data-svc="'+s.slug+'" value="'+nilaiHarga+'" placeholder="Rp"></span>'
      + '<span class="mini-field"><span class="mini-label">Upah</span>'
      +   '<input type="number" class="sw-upah" data-svc="'+s.slug+'" value="'+nilaiUpah+'" placeholder="Rp"></span>'
      + '</label>';
  }).join("");

  const {value: hasil} = await Swal.fire({
    title: k ? "Ubah "+k.label : "Jenis kendaraan baru",
    html:
      '<div class="sw-field-label">Nama jenis kendaraan</div>'
      +'<input id="swKatLabel" class="swal2-input" placeholder="Mobil Kecil..." maxlength="40" value="'+esc(k?k.label:"")+'">'
      +'<div class="sw-judul">Harga &amp; upah pekerja per layanan (hilangkan centang bila tidak tersedia)</div>'
      + barisLayanan,
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: "Simpan", cancelButtonText: "Batal", confirmButtonColor: "#1B9E62",
    preConfirm: () => {
      const label = document.getElementById("swKatLabel").value.trim();
      if(!label) return Swal.showValidationMessage("Nama tidak boleh kosong");
      const prices = {};
      const wages = {};
      document.querySelectorAll(".sw-ada").forEach(cb => {
        if(cb.checked){
          const inpHarga = document.querySelector('.sw-harga[data-svc="'+cb.dataset.svc+'"]');
          const inpUpah = document.querySelector('.sw-upah[data-svc="'+cb.dataset.svc+'"]');
          prices[cb.dataset.svc] = Math.max(0, parseInt(inpHarga.value,10)||0);
          wages[cb.dataset.svc] = Math.max(0, parseInt(inpUpah.value,10)||0);
        }
      });
      if(Object.keys(prices).length===0) return Swal.showValidationMessage("Pilih minimal satu layanan");
      return {
        label,
        prices,
        wages,
      };
    },
  });
  if(!hasil) return;
  try{
    if(k) await api("/wash-categories/"+k.id,{method:"PATCH",body:hasil});
    else   await api("/wash-categories",{method:"POST",body:hasil});
    await muatUlangKatalog();
    Swal.fire({icon:"success", title:k?"Perubahan tersimpan":"Jenis kendaraan ditambahkan", timer:1700, showConfirmButton:false});
  }catch(e){ gagal(e); }
}
async function hapusKategori(id, label){
  const r = await Swal.fire({title:"Hapus "+label+"?", icon:"warning", showCancelButton:true,
    confirmButtonText:"Ya, hapus", cancelButtonText:"Batal", confirmButtonColor:"#d33"});
  if(!r.isConfirmed) return;
  try{ await api("/wash-categories/"+id,{method:"DELETE"}); await muatUlangKatalog(); }
  catch(e){ Swal.fire({icon:"error", title:"Tidak bisa dihapus", text:e.message}); }
}
async function tambahLayanan(){
  const {value: h} = await Swal.fire({
    title:"Jenis layanan baru",
    html:'<div class="sw-field-label">Nama layanan</div>'
        +'<input id="swSvcLabel" class="swal2-input" placeholder="Cuci + Wax..." maxlength="40">'
        +'<div class="sw-field-label">Harga awal untuk semua jenis kendaraan (Rp)</div>'
        +'<input id="swSvcHarga" class="swal2-input" type="number" placeholder="50000">'
        +'<div class="sw-judul">Harga awal ini bisa diatur per jenis kendaraan setelahnya.</div>',
    focusConfirm:false, showCancelButton:true,
    confirmButtonText:"Tambah", cancelButtonText:"Batal", confirmButtonColor:"#1B9E62",
    preConfirm: () => {
      const label = document.getElementById("swSvcLabel").value.trim();
      if(!label) return Swal.showValidationMessage("Nama tidak boleh kosong");
      return {label, price: Math.max(0, parseInt(document.getElementById("swSvcHarga").value,10)||0)};
    },
  });
  if(!h) return;
  try{ await api("/wash-services",{method:"POST",body:h}); await muatUlangKatalog(); }catch(e){ gagal(e); }
}
async function editLayanan(id, lama){
  const {value: label} = await Swal.fire({
    title:"Ubah nama layanan", input:"text", inputValue:lama, inputAttributes:{maxlength:40},
    showCancelButton:true, confirmButtonText:"Simpan", cancelButtonText:"Batal", confirmButtonColor:"#1B9E62",
    inputValidator: v => !v || !v.trim() ? "Nama tidak boleh kosong" : undefined,
  });
  if(label===undefined) return;
  try{ await api("/wash-services/"+id,{method:"PATCH",body:{label:label.trim()}}); await muatUlangKatalog(); }catch(e){ gagal(e); }
}
async function hapusLayanan(id, label){
  const r = await Swal.fire({title:"Hapus layanan "+label+"?", icon:"warning", showCancelButton:true,
    confirmButtonText:"Ya, hapus", cancelButtonText:"Batal", confirmButtonColor:"#d33"});
  if(!r.isConfirmed) return;
  try{ await api("/wash-services/"+id,{method:"DELETE"}); await muatUlangKatalog(); }
  catch(e){ Swal.fire({icon:"error", title:"Tidak bisa dihapus", text:e.message}); }
}

/* ---------- PENGATURAN: ADD-ON ---------- */
async function renderAddonPengaturan(){
  try{
    const list = await api("/addons");
    $("daftarAddon").innerHTML = list.length===0
      ? '<div class="cat-kosong">Belum ada layanan tambahan.</div>'
      : list.map(a =>
          '<div class="item-baris">'
          + barisItemAtas(esc(a.name), "",
              'editAddon('+a.id+',\''+jsStr(a.name)+'\')', 'hapusAddon('+a.id+')')
          + '<div class="item-bawah">'
          +   miniField("Harga (Rp)", a.price, 'ubahHargaAddon('+a.id+', this.value)')
          +   '<button class="btn-hadir'+(a.is_active?" aktif":"")+'" onclick="toggleAddonAktif('+a.id+','+(a.is_active?0:1)+')">'
          +     (a.is_active?"&#10003; Aktif":"Nonaktif")+'</button>'
          + '</div>'
          + '</div>'
        ).join("");
  }catch(e){ gagal(e); }
}
async function tambahAddon(){
  const name = $("inAddonNama").value.trim();
  const price = parseInt($("inAddonHarga").value,10);
  if(!name){ $("inAddonNama").focus(); return; }
  if(isNaN(price) || price<0){ $("inAddonHarga").focus(); return; }
  try{
    await api("/addons",{method:"POST",body:{name, price}});
    $("inAddonNama").value=""; $("inAddonHarga").value="";
    await muatUlangKatalog();
  }catch(e){ gagal(e); }
}
async function editAddon(id, lama){
  const {value: name} = await Swal.fire({
    title:"Ubah nama add-on", input:"text", inputValue:lama, inputAttributes:{maxlength:60},
    showCancelButton:true, confirmButtonText:"Simpan", cancelButtonText:"Batal", confirmButtonColor:"#1B9E62",
    inputValidator: v => !v || !v.trim() ? "Nama tidak boleh kosong" : undefined,
  });
  if(name===undefined) return;
  try{ await api("/addons/"+id,{method:"PATCH",body:{name:name.trim()}}); await muatUlangKatalog(); }catch(e){ gagal(e); }
}
async function ubahHargaAddon(id, v){
  try{ await api("/addons/"+id,{method:"PATCH",body:{price:Math.max(0,parseInt(v,10)||0)}}); await muatUlangKatalog(); }catch(e){ gagal(e); }
}
async function toggleAddonAktif(id, aktif){
  try{ await api("/addons/"+id,{method:"PATCH",body:{is_active:!!aktif}}); await muatUlangKatalog(); }catch(e){ gagal(e); }
}
async function hapusAddon(id){
  if(!await konfirmasiHapus()) return;
  try{ await api("/addons/"+id,{method:"DELETE"}); await muatUlangKatalog(); }catch(e){ gagal(e); }
}

/* ---------- PENGATURAN: KATALOG KENDARAAN (owner) ----------
   Daftar MOBIL-nya, bukan jenisnya: baris "Toyota Calya = Mobil Kecil" inilah
   yang menentukan harga begitu kasir mengetik "calya". Sampai sebelum ini
   daftar tersebut tidak punya layar sama sekali — ditanam seeder saat
   pemasangan, lalu ditambahi tebakan AI dari layar kasir, dan satu-satunya
   cara membetulkan yang salah adalah SQL manual. */
let kendaraanCariTimer = null;

/** Pilihan jenis kendaraan sesuai katalog owner yang berlaku sekarang. */
function opsiKategori(terpilih){
  return Object.entries(CFG.categories).map(([slug, k]) =>
    '<option value="'+slug+'"'+(slug===terpilih?" selected":"")+'>'
    + esc(k.label)+' &middot; '+rp(k.price)+'</option>').join("");
}

async function renderKendaraan(){
  if(!$("daftarKendaraan")) return;
  const q = ($("cariKendaraan").value || "").trim();
  try{
    if(!CFG) CFG = await api("/config");
    const r = await api("/vehicles"+(q? "?q="+encodeURIComponent(q) : ""), {penuh:true});

    // Dropdown "tambah" diisi sekali saja: mengisi ulang tiap render akan
    // membuang pilihan yang sedang diketik owner. Pilihan pertamanya sengaja
    // kosong — kalau langsung menunjuk jenis termurah, owner yang buru-buru
    // menambah mobil tanpa menyentuh dropdown justru membuat kebocoran baru.
    if($("inKendaraanKat").options.length===0){
      $("inKendaraanKat").innerHTML =
        '<option value="" selected disabled>Jenis&hellip;</option>' + opsiKategori(null);
    }

    // Angkanya dari meta (seluruh katalog), bukan dari daftar yang sedang
    // tersaring — kalau tidak, peringatannya hilang begitu owner mengetik
    // sesuatu di kotak cari.
    const perluCek = (r.meta && r.meta.needs_review) || 0;
    $("kendaraanPerluCek").innerHTML = perluCek===0 ? ""
      : '<div class="kendaraan-cek-judul">&#9888; '+perluCek+' mobil di bawah ini jenisnya dari tebakan AI '
        + 'dan belum kamu benarkan. Selama belum dicek, harganya ditentukan mesin.</div>';

    const BATAS = 40;   // tablet: daftar 100 baris berat digulir, bukan dibaca
    const daftar = r.data;
    $("daftarKendaraan").innerHTML = daftar.length===0
      ? '<div class="cat-kosong">'+(q? 'Tidak ada mobil bernama "'+esc(q)+'" di daftar.'
                                     : 'Katalog masih kosong.')+'</div>'
      : daftar.slice(0, BATAS).map(barisKendaraan).join("")
        + (daftar.length>BATAS
            ? '<div class="kendaraan-lain">&hellip; dan '+(daftar.length-BATAS)
              +' mobil lain. Ketik namanya di kotak cari untuk menemukannya.</div>'
            : "");
  }catch(e){ gagal(e); }
}

/* Jumlah pemakaian ikut di baris ATAS bersama nama, bukan di sebelah dropdown:
   di lebar HP (375px) dropdown jenis sudah memakan seluruh baris bawah, dan
   angka di sampingnya terpotong tepi kartu. */
function barisKendaraan(v){
  return '<div class="item-baris'+(v.needs_review?" perlu-cek":"")+'">'
    + '<div class="item-atas">'
    +   '<span class="item-nama">'+esc(v.name)
    +     (v.needs_review? ' <span class="item-tag tag-ai">tebakan AI</span>' : '')
    +     ' <span class="item-tag">'+(v.used_count? v.used_count+'x dicuci' : 'belum pernah')+'</span></span>'
    +   '<span class="item-aksi">'
    +     '<button class="btn-edit-pk" title="Ubah nama" onclick="editNamaKendaraan('+v.id+',\''+jsStr(v.name)+'\')">&#9998;</button>'
    +     '<button class="btn-hapus-pk" title="Hapus" onclick="hapusKendaraan('+v.id+',\''+jsStr(v.name)+'\')">&#10005;</button>'
    +   '</span>'
    + '</div>'
    + '<div class="item-bawah">'
    +   '<label class="mini-field"><span class="mini-label">Jenis &amp; harga</span>'
    +     '<select onchange="ubahKategoriKendaraan('+v.id+', this.value)">'+opsiKategori(v.category)+'</select></label>'
    + '</div>'
    + '</div>';
}

function jadwalCariKendaraan(){
  clearTimeout(kendaraanCariTimer);
  kendaraanCariTimer = setTimeout(renderKendaraan, 250);
}

async function tambahKendaraan(){
  const name = $("inKendaraanNama").value.trim();
  const category = $("inKendaraanKat").value;
  if(!name){ $("inKendaraanNama").focus(); return; }
  if(!category){ $("inKendaraanKat").focus(); return; }
  try{
    await api("/vehicles",{method:"POST",body:{name, category}});
    $("inKendaraanNama").value = "";
    await segarkanKatalogKendaraan();
  }catch(e){ gagal(e); }
}

/* Mengubah jenis = mengubah harga cucian BERIKUTNYA. Transaksi lampau sengaja
   tidak ikut berubah (itu uang yang sudah diterima, bukan daftar harga) —
   tapi jumlahnya dikatakan, supaya owner tahu ada yang bisa diperiksa. */
async function ubahKategoriKendaraan(id, category){
  try{
    const r = await api("/vehicles/"+id,{method:"PATCH",body:{category}, penuh:true});
    await segarkanKatalogKendaraan();
    const n = (r.meta && r.meta.transaksi_beda_kategori) || 0;
    if(n>0){
      Swal.fire({icon:"info", title:"Tersimpan", html:
        '<b>'+esc(r.data.name)+'</b> sekarang '+esc(labelKat(r.data.category))
        + ' untuk cucian berikutnya.<br><br>'
        + '<b>'+n+' transaksi lampau</b> masih tercatat dengan jenis yang lama. '
        + 'Angkanya tidak ikut berubah &mdash; kalau perlu dibetulkan, ubah satu per satu di Pembukuan.'});
    }
  }catch(e){ gagal(e); }
}

async function editNamaKendaraan(id, lama){
  const {value: name} = await Swal.fire({
    title:"Ubah nama mobil", input:"text", inputValue:lama, inputAttributes:{maxlength:100},
    showCancelButton:true, confirmButtonText:"Simpan", cancelButtonText:"Batal", confirmButtonColor:"#1B9E62",
    inputValidator: v => !v || !v.trim() ? "Nama tidak boleh kosong" : undefined,
  });
  if(name===undefined) return;
  try{
    await api("/vehicles/"+id,{method:"PATCH",body:{name:name.trim()}});
    await segarkanKatalogKendaraan();
  }catch(e){ gagal(e); }
}

async function hapusKendaraan(id, nama){
  const r = await Swal.fire({title:"Hapus "+esc(nama)+" dari daftar?", icon:"warning",
    text:"Kasir tidak akan menemukannya lagi saat mengetik nama itu. Transaksi lampau tidak terpengaruh.",
    showCancelButton:true, confirmButtonText:"Ya, hapus", cancelButtonText:"Batal", confirmButtonColor:"#d33"});
  if(!r.isConfirmed) return;
  try{ await api("/vehicles/"+id,{method:"DELETE"}); await segarkanKatalogKendaraan(); }catch(e){ gagal(e); }
}

/** Daftar + tanda merah + hasil pencarian kasir semuanya ikut berubah. */
async function segarkanKatalogKendaraan(){
  CFG = await api("/config");
  gambarTandaKatalog();
  await renderKendaraan();
}

/* Tanda merah di menu Pengaturan & tab Kendaraan: berapa mobil yang harganya
   masih ditentukan tebakan mesin. Tanpa ini owner tidak punya alasan untuk
   membuka layar katalog, dan tebakan AI mengendap jadi harga permanen. */
function gambarTandaKatalog(){
  const n = (CFG && CFG.vehicles_need_review) || 0;
  ["drTandaKatalog", "setTabTandaKendaraan"].forEach(id => {
    const el = document.getElementById(id);
    if(!el) return;
    el.textContent = n;
    el.classList.toggle("hidden", n===0 || ROLE!=="owner");
  });
}

/** Perubahan katalog memengaruhi layar kasir juga — segarkan CFG & grid. */
async function muatUlangKatalog(){
  CFG = await api("/config");
  renderGrid();
  gambarTandaKatalog();
  await renderKatalog();
  await renderKendaraan();
  await renderAddonPengaturan();
}
/* ---------- AKUN OWNER (khusus owner) ----------
   Owner mengubah username/password-nya sendiri. Password lama WAJIB diisi:
   sesi yang tertinggal terbuka di tablet tidak boleh cukup untuk mengambil
   alih akun ini. Server memeriksa ulang syarat itu — lihat
   OwnerAccountController. */
async function renderAkunOwner(){
  const blok = $("blokAkunOwner");
  if(!blok) return;
  if(ROLE!=="owner"){ blok.classList.add("hidden"); return; }
  blok.classList.remove("hidden");
  try{
    const a = await api("/owner-account");
    $("inOwnerUsername").value = a.username || "";
    // Diringkas jadi satu baris status. Ini satu-satunya tanda bahwa password
    // owner masih teks polos di .env, jadi tidak dihapus — hanya dipendekkan.
    $("akunOwnerInfo").innerHTML = a.dari_aplikasi
      ? 'Password tersimpan ter-<i>hash</i>.'
      : 'Password masih teks polos di <code>.env</code>.';
  }catch(e){ gagal(e); }
}

async function simpanAkunOwner(){
  const username = $("inOwnerUsername").value.trim();
  const password = $("inOwnerPasswordBaru").value;
  const current_password = $("inOwnerPasswordLama").value;
  if(username.length < 3){ alert("Username minimal 3 karakter"); $("inOwnerUsername").focus(); return; }
  if(password && password.length < 6){ alert("Password baru minimal 6 karakter"); $("inOwnerPasswordBaru").focus(); return; }
  if(!current_password){ alert("Isi password owner sekarang untuk memastikan ini benar Anda"); $("inOwnerPasswordLama").focus(); return; }

  const body = {username, current_password};
  if(password) body.password = password;
  try{
    const r = await api("/owner-account",{method:"PUT",body});
    $("inOwnerPasswordBaru").value = ""; $("inOwnerPasswordLama").value = "";
    await renderAkunOwner();
    // Sesi lama tetap berlaku (token tidak menyimpan password), tapi owner
    // perlu tahu kredensial mana yang dipakai saat login berikutnya.
    Swal.fire({icon:"success", title:"Akun owner tersimpan",
      html: "Login berikutnya pakai username <b>"+esc(r.username)+"</b>"
        + (r.password_diganti ? " dan password baru." : " dengan password yang sama.")
        + "<br><small>Sesi yang sedang berjalan tidak terputus.</small>"});
  }catch(e){ gagal(e); }
}

/* ---------- AKUN KASIR (khusus owner) ---------- */
async function renderAkunKasir(){
  const blok = $("blokAkunKasir");
  if(ROLE!=="owner"){ blok.classList.add("hidden"); return; }
  blok.classList.remove("hidden");
  try{
    const akun = await api("/users");
    $("daftarAkunKasir").innerHTML = akun.length===0
      ? '<div class="cat-kosong">Belum ada akun kasir. Buat di atas — kasir login pakai username &amp; password itu.</div>'
      : akun.map(u =>
          '<div class="pk-baris">'
          +'<span class="pk-nama">'+esc(u.name)+' <span class="waktu">'+esc(u.username)+'</span></span>'
          +'<button class="btn-edit-pk" onclick="editAkunKasir('+u.id+',\''+jsStr(u.name)+'\',\''+jsStr(u.username)+'\')" title="Ubah akun">&#9998;</button>'
          +'<button class="btn-hapus-pk" onclick="hapusAkunKasir('+u.id+')" title="Hapus">&#10005;</button>'
          +'</div>'
        ).join("");
  }catch(e){ gagal(e); }
}
async function tambahAkunKasir(){
  const name = $("inAkunNama").value.trim();
  const username = $("inAkunUsername").value.trim();
  const password = $("inAkunPassword").value;
  if(!name){ $("inAkunNama").focus(); return; }
  if(!username){ $("inAkunUsername").focus(); return; }
  if(!password){ $("inAkunPassword").focus(); return; }
  try{
    await api("/users",{method:"POST",body:{name, username, password}});
    $("inAkunNama").value=""; $("inAkunUsername").value=""; $("inAkunPassword").value="";
    renderAkunKasir();
  }catch(e){ gagal(e); }
}
async function hapusAkunKasir(id){
  if(!await konfirmasiHapus()) return;
  try{ await api("/users/"+id,{method:"DELETE"}); renderAkunKasir(); }catch(e){ gagal(e); }
}
async function editAkunKasir(id, namaLama, usernameLama){
  const {value: hasil} = await Swal.fire({
    title: "Ubah akun kasir",
    html:
      '<div class="sw-field-label">Nama kasir</div>'
      +'<input id="swAkunNama" class="swal2-input" placeholder="Budi..." maxlength="64" value="'+esc(namaLama)+'">'
      +'<div class="sw-field-label">Username (untuk login)</div>'
      +'<input id="swAkunUser" class="swal2-input" placeholder="budi" maxlength="64" autocapitalize="none" value="'+esc(usernameLama)+'">'
      +'<div class="sw-field-label">Password baru (kosongkan bila tidak diganti)</div>'
      +'<input id="swAkunPass" type="password" class="swal2-input" maxlength="255" placeholder="&bull;&bull;&bull;&bull;">',
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: "Simpan",
    cancelButtonText: "Batal",
    confirmButtonColor: "#1B9E62",
    preConfirm: () => {
      const name     = document.getElementById("swAkunNama").value.trim();
      const username = document.getElementById("swAkunUser").value.trim();
      const password = document.getElementById("swAkunPass").value;
      if(!name)               return Swal.showValidationMessage("Nama tidak boleh kosong");
      if(username.length < 3) return Swal.showValidationMessage("Username minimal 3 karakter");
      if(password && password.length < 4) return Swal.showValidationMessage("Password minimal 4 karakter");
      return {name, username, password};
    },
  });
  if(!hasil) return;
  const body = {name: hasil.name, username: hasil.username};
  if(hasil.password) body.password = hasil.password; // kosong = password lama dipertahankan
  try{
    await api("/users/"+id,{method:"PATCH",body});
    renderAkunKasir();
    Swal.fire({icon:"success", title:"Akun diperbarui",
      text: hasil.password ? "Nama, username & password tersimpan." : "Nama & username tersimpan.",
      timer:2000, showConfirmButton:false});
  }catch(e){ gagal(e); }
}

/* ---------- PENGATURAN: TAB DI DALAM LAYAR ----------
   Empat kelompok (kendaraan, makanan, shift, akun) menempati layar yang sama
   dan bergantian tampil. Pilihan terakhir diingat: owner yang sedang menyetel
   shift biasanya bolak-balik ke sana, dan mengembalikannya ke tab pertama tiap
   kali berarti menggulir melewati seluruh katalog lagi. */
const TAB_PENGATURAN = ["kendaraan", "makanan", "karyawan", "shift", "akun"];

/* `gulirKeAtas` sengaja jadi pilihan, bukan selalu: fungsi ini dipakai dua
   macam pemanggil. Menekan tab (atau baru masuk layar Pengaturan) memang
   layak melompat ke atas. Tapi renderMenuFnb() juga memanggilnya SETIAP
   selesai menyimpan — dan di sana melompat ke atas berarti owner yang baru
   mengubah stok menu ke-10 terlempar ke pucuk halaman dan harus menggulir
   turun lagi. */
function setTabPengaturan(tab, gulirKeAtas = true){
  if(!TAB_PENGATURAN.includes(tab)) tab = TAB_PENGATURAN[0];
  document.querySelectorAll("#setTabs .set-tab").forEach(b =>
    b.classList.toggle("aktif", b.dataset.tab === tab));
  document.querySelectorAll(".set-grup").forEach(g =>
    g.classList.toggle("hidden", g.dataset.grup !== tab));
  localStorage.setItem("otinTabPengaturan", tab);
  // Pindah tab = layar berganti isi; kalau posisi gulir dibiarkan, tab pendek
  // seperti Shift terbuka di tengah-tengah halaman.
  const layar = $("layarMenuFnb");
  if(gulirKeAtas && layar && !layar.classList.contains("hidden")) window.scrollTo({top:0, behavior:"smooth"});
}

/* ---------- PENGATURAN: KARYAWAN TRAINING (khusus owner) ----------
   Training TIDAK ikut bagi rata: ia menerima nominal tetap per cucian, sisanya
   baru dibagi ke pekerja senior. Angkanya diatur di sini; perhitungannya
   sendiri hidup di server (WageService::bagiUpah) supaya layar tidak pernah
   ikut memutuskan besaran upah. */
async function renderKaryawanTraining(){
  try{
    // CFG dimuat setelah layar pertama dibuka, jadi layar ini bisa jalan lebih
    // dulu. Tanpa penantian ini, nama kendaraan & layanan tampil sebagai slug
    // mentah ("kecil", "hidro") — tidak salah, tapi tidak terbaca owner.
    if(!CFG) CFG = await api("/config");

    const [tarif, daftar] = await Promise.all([
      api("/trainee-wage"),
      api("/workers"),
    ]);

    // Dikelompokkan per kendaraan supaya terbaca sebagai "nyuci motor sekian,
    // nyuci mobil sekian" — bukan deretan datar berisi 7 angka tanpa konteks.
    const perKat = {};
    tarif.forEach(t => { (perKat[t.category] ??= []).push(t); });

    $("daftarUpahTraining").innerHTML = tarif.length===0
      ? '<div class="cat-kosong">Belum ada jenis kendaraan. Tambahkan di tab Kendaraan dulu.</div>'
      : Object.entries(perKat).map(([kat, baris]) =>
          '<div class="item-baris">'
          // Judul kolom cukup sekali per kendaraan. Sebelumnya tiap baris
          // membawa label "TRAINING (RP)" sendiri, yang membuat kolom kanan
          // 24px lebih tinggi daripada kiri — selisih itu muncul sebagai ruang
          // kosong menganga di atas tiap nama layanan.
          + '<div class="item-atas"><span class="item-nama">'+esc(labelKat(kat))+'</span>'
          +   '<span class="tr-kepala">Jatah training (Rp)</span></div>'
          + baris.map(b =>
              '<div class="tr-baris">'
              + '<span class="tr-layanan">'+esc(labelSvc(b.service))
              +   '<span class="tr-jatah">jatah pekerja '+rp(b.wage)+'</span></span>'
              + '<label class="mini-field'+(b.trainee_amount > b.wage ? " habis" : "")+'">'
              +   '<input type="number" inputmode="numeric" min="0" value="'+b.trainee_amount+'"'
              +     ' onchange="simpanUpahTraining(\''+b.category+'\',\''+b.service+'\',this.value)">'
              + '</label>'
              + '</div>'
              // Jatah training di atas jatah pekerja tidak salah, tapi tidak
              // pernah terbayar penuh — lebih baik dikatakan daripada owner
              // mengira sudah beres.
              + (b.trainee_amount > b.wage
                  ? '<div class="tr-awas">Lebih besar dari jatah pekerjanya; akan dipotong otomatis saat dibayarkan.</div>'
                  : '')
            ).join("")
          + '</div>'
        ).join("");

    $("daftarStatusPekerja").innerHTML = daftar.length===0
      ? '<div class="cat-kosong">Belum ada pekerja.</div>'
      : daftar.map(p =>
          '<div class="pk-baris">'
          +'<span class="pk-nama">'+esc(p.name)
          +  (p.is_trainee? ' <span class="tag-training">TRAINING</span>' : '')
          +'</span>'
          +'<button class="btn-hadir'+(p.is_trainee?"":" aktif")+'" '
          +  'onclick="setStatusTraining('+p.id+','+(p.is_trainee?0:1)+')">'
          +  (p.is_trainee? "Jadikan senior" : "Jadikan training")+'</button>'
          +'</div>'
        ).join("");
  }catch(e){ gagal(e); }
}

/** Simpan nominal training untuk SATU kendaraan + layanan. */
async function simpanUpahTraining(category, service, nilai){
  const amount = parseInt(nilai, 10);
  if(isNaN(amount) || amount < 0){ renderKaryawanTraining(); return; }
  try{
    const r = await api("/trainee-wage",{method:"PUT",body:{category, service, amount}});
    // Digambar ulang supaya peringatan "lebih besar dari jatahnya" ikut
    // menyesuaikan angka yang baru saja disimpan.
    await renderKaryawanTraining();
    Swal.fire({toast:true, position:"top-end", icon:"success",
      title: labelKat(category)+" · "+labelSvc(service),
      text: "Jatah training "+rp(r.trainee_amount),
      showConfirmButton:false, timer:1800});
  }catch(e){ gagal(e); }
}

async function setStatusTraining(id, jadiTraining){
  const p = (await api("/workers")).find(x => x.id === id);
  if(!p) return;
  try{
    // Nama ikut dikirim karena aturan validasinya mewajibkan — dipakai ulang
    // dari StoreWorkerRequest supaya batas yang sama berlaku di semua jalur.
    await api("/workers/"+id,{method:"PATCH",body:{name:p.name, is_trainee:!!jadiTraining}});
    renderKaryawanTraining();
  }catch(e){ gagal(e); }
}

let produkSemua = [], menuCari = "", menuFilter = "semua";
/* Dipanggil dua macam: saat MASUK layar Pengaturan (gulirKeAtas=true, wajar
   mulai dari pucuk) dan setiap kali SELESAI MENYIMPAN sesuatu di layar itu
   (default false — posisi gulir owner dibiarkan apa adanya). */
async function renderMenuFnb(gulirKeAtas = false){
  setTabPengaturan(localStorage.getItem("otinTabPengaturan") || "kendaraan", gulirKeAtas);
  try{
    renderKatalog();
    renderKendaraan();
    renderPenitip();
    renderAddonPengaturan();
    renderAkunOwner();
    renderAkunKasir();
    renderKaryawanTraining();
    renderShiftPengaturan();
    produkSemua = await api("/products");
    renderDaftarProduk();
  }catch(e){ gagal(e); }
}
function setFilterMenu(f){
  menuFilter = f;
  document.querySelectorAll(".chip-filter").forEach(c => c.classList.toggle("aktif", c.dataset.f===f));
  renderDaftarProduk();
}
function renderDaftarProduk(){
  const q = menuCari.trim().toLowerCase();
  const cocok = produkSemua.filter(p =>
    (menuFilter==="semua" || p.type===menuFilter) &&
    (!q || p.name.toLowerCase().includes(q)));
  $("daftarProduk").innerHTML = cocok.length===0
    ? '<div class="cat-kosong">'+(produkSemua.length? "Tidak ada menu yang cocok." : "Belum ada menu.")+'</div>'
    : cocok.map(p =>
        '<div class="item-baris'+(p.consignor_id?" titipan":"")+'">'
        + barisItemAtas(
            esc(p.name)
              + (p.consignor_id
                  ? ' <span class="item-tag tag-titip">&#129309; '+esc(namaPenitip(p))+'</span>'
                  : ''),
            (p.type==="makanan"? "&#127836; Makanan" : "&#129380; Minuman"),
            'editProduk('+p.id+',\''+jsStr(p.name)+'\',\''+p.type+'\')',
            'hapusProduk('+p.id+')')
        + '<div class="item-bawah">'
        +   miniField("Harga (Rp)", p.price, 'ubahHargaProduk('+p.id+', this.value)')
        //  Barang titipan: kotak stok diganti harga setor + tombol barang
        //  masuk/retur. Stoknya memang tidak boleh diketik langsung —
        //  "masuk - laku - retur = sisa" harus tetap bisa dipertanggungjawabkan
        //  ke penitipnya (server menolak juga, lihat ProductController).
        +   (p.consignor_id
              ? miniField("Setor (Rp)", p.payout_price==null? 0 : p.payout_price,
                  'ubahSetorProduk('+p.id+', this.value)')
                + miniGerakTitipan(p)
              : miniStok(p))
        +   '<button class="btn-hadir'+(p.is_active?" aktif":"")+'" onclick="toggleProduk('+p.id+','+(p.is_active?0:1)+')">'
        +     (p.is_active?"&#10003; Aktif":"Nonaktif")+'</button>'
        + '</div>'
        + '</div>'
      ).join("");
}

/* ---------- PENGATURAN: TITIP JUAL (owner) ----------
   Barang orang lain yang dijualkan di sini. Layar ini tidak pernah dilihat
   kasir: baginya barang titipan sama saja dengan barang lain di menu F&B,
   cuma tombolnya berwarna ungu. Yang owner-only adalah keputusannya — siapa
   penitipnya, bagi hasilnya berapa, barang masuk berapa, kapan disetor.

   Angka utang di sini tidak pernah disimpan; ia selalu dihitung ulang dari
   (hak penitip yang sudah terjadi - yang sudah dibayar). Lihat catatan
   lengkapnya di App\Services\ConsignmentService. */
let penitipSemua = [];

async function renderPenitip(){
  if(!$("daftarPenitip")) return;
  try{
    const r = await api("/consignors", {penuh:true});
    penitipSemua = r.data || [];

    const utangTotal = (r.meta && r.meta.utang_total) || 0;
    $("titipUtangTotal").textContent = utangTotal ? "utang " + rp(utangTotal) : "";
    $("titipUtangTotal").classList.toggle("hidden", !utangTotal);

    $("daftarPenitip").innerHTML = penitipSemua.length===0
      ? '<div class="cat-kosong">Belum ada penitip. Tambahkan dulu orangnya, '
        + 'baru barangnya didaftarkan di "Tambah menu" di bawah.</div>'
      : penitipSemua.map(barisPenitip).join("");

    isiPilihanPenitip();
  }catch(e){ gagal(e); }
}

/** Ringkasan kesepakatan dalam satu frasa pendek. */
function bagiHasilTeks(c){
  return c.share_mode==="persen"
    ? "cucian ambil "+c.share_percent+"%"
    : "harga setor per barang";
}

function barisPenitip(c){
  return '<div class="item-baris'+(c.utang? " punya-utang":"")+'">'
    + '<div class="item-atas">'
    +   '<span class="item-nama">'+esc(c.name)
    +     ' <span class="item-tag">'+bagiHasilTeks(c)+'</span>'
    +     (c.jumlah_barang? ' <span class="item-tag">'+c.jumlah_barang+' barang</span>' : '')
    +     (c.is_active? '' : ' <span class="item-tag">nonaktif</span>')
    +   '</span>'
    +   '<span class="item-aksi">'
    +     '<button class="btn-edit-pk" title="Ubah" onclick="editPenitip('+c.id+')">&#9998;</button>'
    +     '<button class="btn-hapus-pk" title="Hapus" onclick="hapusPenitip('+c.id+')">&#10005;</button>'
    +   '</span>'
    + '</div>'
    + '<div class="item-bawah">'
    +   '<span class="titip-utang'+(c.utang? " ada":"")+'">'
    +     (c.utang? "Utang " + rp(c.utang) : "Tidak ada utang")+'</span>'
    +   '<button class="btn-hadir" onclick="rincianPenitip('+c.id+')">Rincian</button>'
    +   (c.utang? '<button class="btn-hadir aktif" onclick="setorPenitip('+c.id+')">Setor</button>' : '')
    + '</div>'
    + '</div>';
}

/** Form tambah/ubah penitip. id null = tambah baru. */
async function editPenitip(id){
  const c = id ? penitipSemua.find(x=>x.id===id) : null;
  const persen = c && c.share_mode==="persen";

  const {value: hasil} = await Swal.fire({
    title: c ? "Ubah "+esc(c.name) : "Penitip baru",
    html:
      '<div class="sw-field-label">Nama penitip</div>'
      +'<input id="swPenitipNama" class="swal2-input" placeholder="Bu Sri..." maxlength="80" value="'+esc(c?c.name:"")+'">'
      +'<div class="sw-field-label">No. HP (boleh kosong)</div>'
      +'<input id="swPenitipHp" class="swal2-input" placeholder="08..." maxlength="30" value="'+esc(c&&c.phone?c.phone:"")+'">'
      +'<div class="sw-field-label">Bagi hasil</div>'
      +'<select id="swPenitipMode" class="swal2-select" onchange="swTogglePersen()">'
      +  '<option value="setor"'+(!persen?" selected":"")+'>Harga setor tetap per barang</option>'
      +  '<option value="persen"'+(persen?" selected":"")+'>Cucian ambil persen dari harga jual</option>'
      +'</select>'
      +'<div id="swPersenBlok" class="'+(persen?"":"hidden")+'">'
      +  '<div class="sw-field-label">Bagian cucian (%)</div>'
      +  '<input id="swPenitipPersen" class="swal2-input" type="number" min="1" max="90" '
      +    'placeholder="20" value="'+(c&&c.share_percent?c.share_percent:"")+'">'
      +'</div>'
      +'<div class="sw-judul">Harga setor diisi per barang nanti, di daftar menu.</div>',
    focusConfirm:false, showCancelButton:true,
    confirmButtonText:"Simpan", cancelButtonText:"Batal", confirmButtonColor:"#1B9E62",
    preConfirm: () => {
      const name = document.getElementById("swPenitipNama").value.trim();
      const mode = document.getElementById("swPenitipMode").value;
      const pct  = parseInt(document.getElementById("swPenitipPersen").value,10);
      if(!name) return Swal.showValidationMessage("Nama tidak boleh kosong");
      if(mode==="persen" && (isNaN(pct) || pct<1 || pct>90))
        return Swal.showValidationMessage("Bagian cucian antara 1% dan 90%");
      return {
        name,
        phone: document.getElementById("swPenitipHp").value.trim() || null,
        share_mode: mode,
        share_percent: mode==="persen" ? pct : null,
      };
    },
  });
  if(!hasil) return;

  try{
    id ? await api("/consignors/"+id,{method:"PATCH",body:hasil})
       : await api("/consignors",{method:"POST",body:hasil});
    await renderPenitip();
  }catch(e){ gagal(e); }
}

/* Dipanggil dari dalam modal Swal, jadi harus global. */
function swTogglePersen(){
  const blok = document.getElementById("swPersenBlok");
  if(blok) blok.classList.toggle("hidden", document.getElementById("swPenitipMode").value!=="persen");
}

async function hapusPenitip(id){
  const c = penitipSemua.find(x=>x.id===id);
  if(!c) return;
  const r = await Swal.fire({title:"Hapus "+esc(c.name)+"?", icon:"warning",
    text:"Hanya bisa kalau utangnya sudah lunas dan barangnya tidak ada lagi di rak.",
    showCancelButton:true, confirmButtonText:"Ya, hapus", cancelButtonText:"Batal", confirmButtonColor:"#d33"});
  if(!r.isConfirmed) return;
  try{ await api("/consignors/"+id,{method:"DELETE"}); await renderPenitip(); }
  catch(e){ Swal.fire({icon:"error", title:"Tidak bisa dihapus", text:e.message}); }
}

/* Rincian yang dibuka BERSAMA penitipnya waktu setoran: per barang, berapa
   dititipkan, berapa laku, berapa diambil lagi, sisa berapa. Kolom "sisa
   seharusnya" dihitung dari riwayat sedangkan "di rak" dari stok — kalau
   keduanya berbeda, itu justru yang perlu dilihat, bukan yang disembunyikan. */
async function rincianPenitip(id){
  try{
    const d = await api("/consignors/"+id);
    const beda = d.items.some(i => i.sisa_seharusnya !== i.sisa_di_rak);

    const tabel = d.items.length===0
      ? '<div class="cat-kosong">Belum ada barang atas nama penitip ini.</div>'
      : '<table class="titip-tabel"><tr><th>Barang</th><th>Masuk</th><th>Laku</th><th>Retur</th><th>Sisa</th></tr>'
        + d.items.map(i =>
            '<tr'+(i.sisa_seharusnya!==i.sisa_di_rak? ' class="beda"':'')+'>'
            + '<td>'+esc(i.name)+'</td><td>'+i.masuk+'</td><td>'+i.terjual+'</td>'
            + '<td>'+i.retur+'</td><td>'+i.sisa_seharusnya
            + (i.sisa_seharusnya!==i.sisa_di_rak? ' <b>(rak: '+i.sisa_di_rak+')</b>':'')
            + '</td></tr>').join("")
        + '</table>';

    await Swal.fire({
      title: esc(d.consignor.name),
      width: 640,
      html: tabel
        + (beda? '<div class="titip-beda">&#9888; Ada barang yang sisa di raknya tidak sama dengan '
                 + 'hitungan riwayat. Cek fisiknya sebelum menyetor.</div>' : '')
        + '<div class="titip-ringkas">'
        +   '<div><span>Hak penitip (barang laku)</span><b>'+rp(d.hak_penitip)+'</b></div>'
        +   '<div><span>Sudah disetor</span><b>'+rp(d.sudah_setor)+'</b></div>'
        +   '<div class="tebal"><span>Sisa utang</span><b class="'+(d.utang?"merah":"hijau")+'">'+rp(d.utang)+'</b></div>'
        +   '<div><span>Bagian cucian (masuk laba)</span><b class="hijau">'+rp(d.bagian_cucian)+'</b></div>'
        + '</div>',
      confirmButtonText: d.utang? "Setor sekarang" : "Tutup",
      showCancelButton: d.utang>0, cancelButtonText:"Tutup", confirmButtonColor:"#1B9E62",
    }).then(r => { if(r.isConfirmed && d.utang) setorPenitip(id); });
  }catch(e){ gagal(e); }
}

/* Setoran ke penitip. Nilainya sudah terisi penuh — yang paling sering
   terjadi adalah melunasi semuanya, dan mengetik ulang angka besar di tablet
   justru mengundang salah ketik. */
async function setorPenitip(id){
  const c = penitipSemua.find(x=>x.id===id);
  if(!c || !c.utang) return;

  const {value: hasil} = await Swal.fire({
    title: "Setor ke "+esc(c.name),
    html:
      '<div class="void-ringkas">Utang sekarang <b>'+rp(c.utang)+'</b></div>'
      +'<div class="sw-field-label">Jumlah disetor (Rp)</div>'
      +'<input id="swSetorJml" class="swal2-input" type="number" value="'+c.utang+'">'
      +'<div class="sw-field-label">Catatan (boleh kosong)</div>'
      +'<input id="swSetorNote" class="swal2-input" maxlength="200" placeholder="setoran minggu ini...">'
      +'<div class="sw-judul">Uang keluar dari laci &amp; tercatat di Pengeluaran. '
      +'Laba TIDAK ikut berkurang — bagian penitip sudah dipotong sejak barangnya laku.</div>',
    focusConfirm:false, showCancelButton:true,
    confirmButtonText:"Setor", cancelButtonText:"Batal", confirmButtonColor:"#1B9E62",
    preConfirm: () => {
      const amount = parseInt(document.getElementById("swSetorJml").value,10);
      if(isNaN(amount) || amount<1) return Swal.showValidationMessage("Jumlah setoran minimal Rp 1");
      if(amount > c.utang) return Swal.showValidationMessage("Tidak boleh melebihi utang "+rp(c.utang));
      return {amount, note: document.getElementById("swSetorNote").value.trim() || null};
    },
  });
  if(!hasil) return;

  try{
    const r = await api("/consignors/"+id+"/payouts",{method:"POST",body:hasil,penuh:true});
    await renderPenitip();
    Swal.fire({icon:"success", title:"Tersetor",
      html: rp(hasil.amount)+" ke <b>"+esc(c.name)+"</b>.<br>"
        + (r.meta.sisa_utang
            ? "Sisa utang "+rp(r.meta.sisa_utang)+"."
            : "Utang lunas.")});
  }catch(e){ gagal(e); }
}

/* --- Barang titipan di daftar menu --- */

/** Isi dropdown pemilik di form "Tambah menu". */
function isiPilihanPenitip(){
  const sel = $("inFnbPemilik");
  if(!sel) return;
  const lama = sel.value;
  sel.innerHTML = '<option value="">&#127970; Milik cucian sendiri</option>'
    + penitipSemua.filter(c=>c.is_active).map(c =>
        '<option value="'+c.id+'">&#129309; '+esc(c.name)+' &middot; '+bagiHasilTeks(c)+'</option>').join("");
  sel.value = lama;
  gantiPemilikBaru();
}

/** Kotak yang relevan saja yang tampil: harga setor vs stok awal. */
function gantiPemilikBaru(){
  const id = parseInt($("inFnbPemilik").value,10) || null;
  const c  = id ? penitipSemua.find(x=>x.id===id) : null;
  // Mode persen tidak butuh harga setor — haknya dihitung dari harga jual.
  $("inFnbSetor").classList.toggle("hidden", !c || c.share_mode!=="setor");
  // Stok awal barang titipan selalu 0: jumlahnya masuk lewat "Barang masuk".
  $("inFnbStok").classList.toggle("hidden", !!c);
}

async function ubahSetorProduk(id, v){
  try{
    await api("/products/"+id,{method:"PATCH",body:{payout_price:Math.max(0,parseInt(v,10)||0)}});
    renderMenuFnb();
  }catch(e){ gagal(e); renderMenuFnb(); }
}

/** Pengganti kotak stok untuk barang titipan — stoknya hanya lewat sini. */
function miniGerakTitipan(p){
  return '<button class="mini-field mini-stok" title="Barang masuk / retur" '
    + 'onclick="gerakTitipan('+p.id+')">'
    + '<span class="mini-label">Sisa di rak</span>'
    + '<span class="mini-nilai">'+p.stock+'</span>'
    + '</button>';
}

/* Satu modal, dua arah: barang datang (masuk) & barang diambil lagi (retur).
   Disatukan karena keduanya selalu terjadi di momen yang sama — penitipnya
   sedang berdiri di depan meja. */
async function gerakTitipan(id){
  const p = produkSemua.find(x=>x.id===id);
  if(!p) return;

  const r = await Swal.fire({
    title: esc(p.name),
    html:
      '<div class="void-ringkas">Sisa di rak sekarang <b>'+p.stock+'</b></div>'
      +'<div class="sw-field-label">Jumlah barang</div>'
      +'<input id="swGerakQty" class="swal2-input" type="number" min="1" placeholder="0">'
      +'<div class="sw-judul">Masuk = penitip menambah barang. Retur = penitip mengambil kembali barang yang belum laku.</div>',
    focusConfirm:false,
    showDenyButton:true, showCancelButton:true,
    confirmButtonText:"&#10133; Barang masuk", denyButtonText:"&#8617;&#65039; Retur",
    cancelButtonText:"Batal", confirmButtonColor:"#1B9E62", denyButtonColor:"#8F6B00",
    preConfirm: () => bacaQtyGerak(),
    preDeny:    () => bacaQtyGerak(p.stock),
  });

  const qty = r.value;
  if(!qty || (!r.isConfirmed && !r.isDenied)) return;

  try{
    await api("/consignment-movements",{method:"POST",
      body:{product_id:id, type: r.isConfirmed? "masuk" : "retur", qty}});
    renderMenuFnb();
  }catch(e){ gagal(e); }
}

function bacaQtyGerak(maks){
  const qty = parseInt(document.getElementById("swGerakQty").value,10);
  if(isNaN(qty) || qty<1) return Swal.showValidationMessage("Jumlah barang minimal 1");
  if(maks!==undefined && qty>maks) return Swal.showValidationMessage("Sisa di rak cuma "+maks);
  return qty;
}

/* ---------- Baris daftar barang (menu F&B & add-on) ----------
   Isinya terlalu banyak untuk satu baris di tablet, jadi dipecah dua:
   nama + tombol di atas, angka + status di bawah. */
function barisItemAtas(namaHtml, tagHtml, onEdit, onHapus){
  return '<div class="item-atas">'
    + '<span class="item-nama">'+namaHtml
    +   (tagHtml? ' <span class="item-tag">'+tagHtml+'</span>' : '')+'</span>'
    + '<span class="item-aksi">'
    +   '<button class="btn-edit-pk" title="Ubah" onclick="'+onEdit+'">&#9998;</button>'
    +   '<button class="btn-hapus-pk" title="Hapus" onclick="'+onHapus+'">&#10005;</button>'
    + '</span>'
    + '</div>';
}
/** Kotak angka berlabel — tanpa label, kasir menebak mana harga mana stok. */
function miniField(label, nilai, onChange, waspada){
  return '<label class="mini-field'+(waspada?" habis":"")+'">'
    + '<span class="mini-label">'+label+'</span>'
    + '<input type="number" inputmode="numeric" value="'+nilai+'" onchange="'+onChange+'">'
    + '</label>';
}
async function editProduk(id, namaLama, jenisLama){
  const {value: hasil} = await Swal.fire({
    title: "Edit menu",
    html:
      '<div class="sw-field-label">Nama menu</div>'
      +'<input id="swProdukNama" class="swal2-input" placeholder="Kopi..." maxlength="80" value="'+esc(namaLama)+'">'
      +'<div class="sw-field-label">Kategori</div>'
      +'<select id="swProdukJenis" class="swal2-select">'
      +   '<option value="makanan"'+(jenisLama==="makanan"?" selected":"")+'>&#127836; Makanan</option>'
      +   '<option value="minuman"'+(jenisLama==="minuman"?" selected":"")+'>&#129380; Minuman</option>'
      +'</select>',
    focusConfirm: false,
    showCancelButton: true, confirmButtonText: "Simpan", cancelButtonText: "Batal",
    confirmButtonColor: "#1B9E62",
    preConfirm: () => {
      const nama = document.getElementById("swProdukNama").value.trim();
      if(!nama) return Swal.showValidationMessage("Nama tidak boleh kosong");
      return {name: nama, type: document.getElementById("swProdukJenis").value};
    },
  });
  if(!hasil) return;
  try{
    await api("/products/"+id,{method:"PATCH",body:hasil});
    produkSemua = await api("/products");
    renderDaftarProduk();
  }catch(e){ gagal(e); }
}
async function tambahProduk(){
  const nama = $("inFnbNama").value.trim();
  const harga = parseInt($("inFnbHarga").value,10);
  const stok = Math.max(0, parseInt($("inFnbStok").value,10)||0);
  const pemilik = parseInt($("inFnbPemilik").value,10) || null;
  if(!nama){ $("inFnbNama").focus(); return; }
  if(!harga || harga<100){ $("inFnbHarga").focus(); return; }

  const body = {name:nama, type:jenisFnbBaru, price:harga};

  if(pemilik){
    // Barang titipan lahir dengan stok 0 — jumlahnya masuk lewat "Barang
    // masuk" supaya ada riwayatnya. Server menegakkan aturan yang sama;
    // ini cuma supaya layar tidak menjanjikan hal yang akan ditolak.
    body.consignor_id = pemilik;
    if(!$("inFnbSetor").classList.contains("hidden")){
      const setor = parseInt($("inFnbSetor").value,10);
      if(isNaN(setor)){ $("inFnbSetor").focus(); return; }
      if(setor > harga){
        Swal.fire({icon:"error", title:"Harga setor kelewat tinggi",
          text:"Harga setor tidak boleh melebihi harga jual — tiap barang laku, cucian malah rugi."});
        return;
      }
      body.payout_price = setor;
    }
  }else{
    body.stock = stok;
  }

  try{
    await api("/products",{method:"POST",body});
    $("inFnbNama").value=""; $("inFnbHarga").value=""; $("inFnbStok").value=""; $("inFnbSetor").value="";
    renderMenuFnb();
  }catch(e){ gagal(e); }
}
async function ubahHargaProduk(id, v){
  try{ await api("/products/"+id,{method:"PATCH",body:{price:Math.max(100,parseInt(v,10)||0)}}); renderMenuFnb(); }catch(e){ gagal(e); }
}
/* Stok sengaja BUKAN kotak isian yang langsung menyimpan seperti harga:
   satu ketukan tak sengaja di tablet dulu cukup untuk mengubah stok tanpa
   kasir sadar. Sekarang angkanya cuma dipajang, dan perubahannya lewat
   modal yang harus ditekan "Simpan" — lihat ubahStokProduk(). */
function miniStok(p){
  return '<button class="mini-field mini-stok'+(p.stock<=0?" habis":"")+'" '
    + 'title="Ubah stok" onclick="ubahStokProduk('+p.id+')">'
    + '<span class="mini-label">Stok</span>'
    + '<span class="mini-nilai">'+p.stock+'</span>'
    + '</button>';
}
async function ubahStokProduk(id){
  const p = produkSemua.find(x=>x.id===id);
  if(!p) return;
  const {value: stok} = await Swal.fire({
    // esc(): judul Swal dirender sebagai HTML, dan nama menu diketik owner.
    title: "Stok " + esc(p.name),
    html:
      '<div class="sw-field-label">Stok sekarang</div>'
      +'<div class="sw-stok-kini">'+p.stock+'</div>'
      +'<div class="sw-field-label">Ubah jadi</div>'
      +'<input id="swStok" type="number" inputmode="numeric" min="0" max="1000000" '
      +  'class="swal2-input" value="'+p.stock+'">',
    focusConfirm: false,
    showCancelButton: true, confirmButtonText: "Simpan", cancelButtonText: "Batal",
    confirmButtonColor: "#1B9E62",
    didOpen: () => {
      // Isian langsung tersorot: kasir tinggal mengetik angka barunya.
      const el = document.getElementById("swStok");
      el.focus(); el.select();
    },
    preConfirm: () => {
      const v = parseInt(document.getElementById("swStok").value, 10);
      if(isNaN(v))     return Swal.showValidationMessage("Stok harus diisi angka");
      if(v < 0)        return Swal.showValidationMessage("Stok tidak boleh minus");
      if(v > 1000000)  return Swal.showValidationMessage("Stok terlalu besar (maks 1.000.000)");
      return v;
    },
  });
  // `undefined` = dibatalkan. Dibandingkan begini, bukan `if(!stok)`, supaya
  // menyetel stok jadi 0 (barang habis) tetap tersimpan.
  if(stok === undefined) return;
  if(stok === p.stock) return;   // tidak berubah — tak perlu menembak server
  try{
    await api("/products/"+id,{method:"PATCH",body:{stock:stok}});
    renderMenuFnb();
    Swal.fire({toast:true, position:"top-end", icon:"success",
      title:"Stok "+esc(p.name)+" jadi "+stok, showConfirmButton:false, timer:2000});
  }catch(e){ gagal(e); }
}
async function toggleProduk(id, aktif){
  try{ await api("/products/"+id,{method:"PATCH",body:{is_active:!!aktif}}); renderMenuFnb(); }catch(e){ gagal(e); }
}
async function hapusProduk(id){
  if(!await konfirmasiHapus()) return;
  try{ await api("/products/"+id,{method:"DELETE"}); renderMenuFnb(); }catch(e){ gagal(e); }
}

/* ---------- INIT ---------- */
async function mulaiAplikasi(){
  try{
    // Status shift diambil DULU: kalau kasir di luar jam operasional, layar
    // terkunci muncul sebelum layar lain sempat memuat data.
    await muatShift();

    // Layar dibuka DULUAN supaya tidak ada layar kosong selama data dimuat —
    // isinya terisi sendiri begitu siap. pergi() yang membelokkan kalau
    // halaman terakhir ternyata layar owner sementara yang login kasir.
    const lastPage = localStorage.getItem("otinLastPage") || layarAwal();
    pergi(lastPage);
    const [cfg, pk, pr] = await Promise.all([
      api("/config"), api("/workers"), api("/products?active=1"),
    ]);
    CFG = cfg; pekerja = pk; produk = pr;
    renderGrid();
    gambarTandaKatalog();
    renderDrafts();
    $("inputCari").addEventListener("input", jadwalCari);
  }catch(e){
    if (e.message === ERR_LOGIN || e.message === ERR_SHIFT) return; // layarnya sudah tampil
    document.body.innerHTML = '<div style="padding:40px;text-align:center;font-family:sans-serif">'
      +'<h2>⚠ Tidak bisa terhubung ke server</h2>'
      +'<p>Pastikan server Laravel berjalan (<code>php artisan serve</code>) dan file ini dibuka lewat alamat server, bukan double-click.</p></div>';
  }
}
(function boot(){
  simpanSesi(TOKEN, ROLE, NAMA); // sinkronkan badge role
  if (!TOKEN) { tampilkanLogin(""); return; }
  mulaiAplikasi();
})();
