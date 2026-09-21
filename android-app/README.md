# OTIN CARWASH — Aplikasi Android

Aplikasi kasir untuk tablet Android, memakai backend Laravel yang sama dengan
web kasir. Dibangun dengan Flutter.

Web kasir (`resources/views/kasir/`) **tidak diubah** dan tetap jalan seperti
biasa. Keduanya menembak API yang sama, jadi transaksi dari tablet dan dari
browser masuk ke pembukuan yang sama, termasuk draft yang dibuat di satu sisi
dan dilanjutkan di sisi lain.

---

## Kenapa Flutter, bukan bungkus WebView

Aplikasi ini dirancang untuk **dijual bersama tabletnya**, bukan cuma dipakai
sendiri. Itu yang menentukan pilihannya:

| Pertimbangan | Alasan |
|---|---|
| Tablet murah | WebView berat di tablet 3–4GB RAM; daftar transaksi panjang patah-patah. Flutter menggambar sendiri ke GPU. |
| Printer struk | Bluetooth ESC/POS jauh lebih stabil lewat native daripada menembus lapisan WebView. |
| Kunci perangkat | Kiosk mode, auto-nyala saat boot, layar tidak mati — mudah di native. |
| Nilai jual | Pembeli merasakan bedanya app asli vs web yang dibungkus. |

**Ongkosnya jujur:** 3.715 baris `kasir.js` ditulis ulang, dan update ke tablet
pelanggan harus lewat APK (lihat bagian Pembaruan di bawah).

---

## Penyiapan

### Sudah selesai di mesin ini

- Android Studio terpasang (`winget install --id Google.AndroidStudio -e`).
- Flutter 3.47.2 stable ter-clone ke **`C:\src\flutter`**.
  Flutter SDK **tidak ada di winget** — `Flutter.Flutter` tidak akan ketemu,
  yang muncul di hasil pencarian itu aplikasi-aplikasi yang *dibuat pakai*
  Flutter. Cara resminya git clone:
  ```powershell
  git clone https://github.com/flutter/flutter.git -b stable --depth 1 C:\src\flutter
  ```
- Folder `android/` sudah dibuat lewat `setup.ps1`, izin internet & Bluetooth
  sudah masuk manifest, dan semua paket sudah ter-resolve.

### Yang masih perlu kamu lakukan sendiri

**1. Masukkan Flutter ke PATH** (biar tidak perlu ketik path panjang terus),
lalu **buka PowerShell baru**:

```powershell
[Environment]::SetEnvironmentVariable('Path', $env:Path + ';C:\src\flutter\bin', 'User')
```

**2. Pasang Android SDK.** Buka Android Studio **satu kali** dan selesaikan
wizard awalnya — SDK-nya tidak ikut terpasang bersama Android Studio, dan
wizard itu langkah berjendela yang tidak bisa dijalankan dari terminal.

**3. Setujui lisensi SDK:**

```powershell
flutter doctor --android-licenses
flutter doctor
```

`flutter doctor` harus bersih di baris **Android toolchain**. Baris *Visual
Studio* boleh tetap merah — itu cuma untuk build aplikasi Windows desktop,
tidak dipakai di sini.

### Menjalankan ulang setup

`setup.ps1` aman dijalankan ulang kapan saja (langkah yang sudah beres
dilewati). Folder `android/` sengaja **tidak** ditulis tangan: isinya berubah
tiap Flutter naik versi, dan versi yang salah menghasilkan error Gradle yang
sulit dibaca.

### Jalankan

```powershell
flutter run
```

Untuk membuat APK yang dipasang ke tablet jualan:

```powershell
flutter build apk --release
```

Hasilnya di `build/app/outputs/flutter-apk/app-release.apk`.

### Mutu kode

```powershell
flutter analyze   # harus "No issues found!"
flutter test      # 33 tes: format plat/rupiah + ketahanan pembaca JSON
```

Tesnya sengaja memusat pada dua hal yang paling mahal kalau salah:
aturan format yang harus sama persis dengan web, dan jawaban server yang
bentuknya tidak seragam (MySQL lewat PDO kadang mengirim angka sebagai
string, SQLite tidak) — kelas kesalahan yang jalan mulus di laptop lalu mati
di tablet pelanggan.

---

## Susunan kode

```
lib/
├── main.dart                 titik masuk: locale id_ID, kunci orientasi, wakelock
├── core/
│   ├── theme.dart            palet warna, disalin dari :root di kasir.css
│   ├── format.dart           rp(), tanggal, format plat nomor
│   ├── cetak.dart            printer thermal Bluetooth (ESC/POS 58mm)
│   └── pembaruan.dart        pengecek versi baru
├── data/
│   ├── api_client.dart       klien REST + akal-akalan WAF hosting
│   ├── session.dart          token, role, alamat server (SharedPreferences)
│   ├── models/               pembaca JSON, satu file per bidang
│   └── repositories/repo.dart  SATU-SATUNYA pintu ke server
├── state/providers.dart      Riverpod: sesi, data server, keranjang kasir
└── ui/
    ├── gerbang.dart          penentu layar: server -> login -> shift -> app
    ├── kerangka.dart         header + drawer + perpindahan halaman
    ├── widgets/              kartu, siluet kendaraan, isian, pemuat
    └── screens/              satu file per layar
```

### Dua aturan yang tidak boleh dilanggar

**1. Layar tidak pernah memanggil `ApiClient` langsung.** Semua lewat
`repositories/repo.dart`. Aturannya terasa berlebihan untuk app yang online-only
seperti sekarang, tapi itulah yang membuat mode offline bisa ditambahkan nanti
tanpa membongkar satu pun layar — cukup sisipkan cache/antrean lokal di dalam
repository, dan seluruh app ikut mendapatkannya.

**2. App tidak pernah menghitung uang.** Harga, upah, laba, dan omzet semuanya
datang dari server (`PricingService`, `WageService`, `BookkeepingService`).
Satu-satunya angka yang dihitung di app adalah **perkiraan** total di layar
konfirmasi, dan itu pun jelas ditandai sebagai perkiraan. Kalau app ikut
berhitung, satu hari akan punya dua versi "omzet" yang beda beberapa ribu
rupiah dan tidak ada yang tahu mana yang benar.

---

## Yang berbeda dari web

| Hal | Web | Tablet |
|---|---|---|
| Alamat server | selalu `/api` (satu origin) | diatur di layar pertama, bisa beda tiap tablet |
| Struk | `window.print()` | printer thermal Bluetooth |
| Pembaruan | refresh browser | pemberitahuan APK versi baru |
| Layar mati | — | dicegah (wakelock) |
| Orientasi | bebas | dikunci |

Layar **Tablet** di Pengaturan berisi ketiga hal pertama; tidak ada padanannya
di web karena di sana memang tidak relevan.

---

## Pembaruan aplikasi (tablet yang dijual)

Tablet pelanggan tidak lewat Play Store, jadi tidak ada yang memberitahu ada
versi baru. App mengecek sendiri ke `GET /api/app-version` dan menampilkan
bilah kuning kalau ada yang lebih baru. **App tidak pernah memasang sendiri** —
pemasangan diam-diam di tengah antrean cucian adalah cara tercepat kehilangan
pelanggan.

Endpoint-nya **belum ada di backend**; app menanganinya dengan diam kalau
server menjawab 404, jadi tidak ada yang rusak sampai kamu menambahkannya.
Ketika siap, tambahkan di `routes/api.php`:

```php
// Di dalam grup 'pin.auth', DI LUAR grup 'shift' — kasir yang sedang
// terkunci tetap boleh tahu ada pembaruan.
Route::get('/app-version', fn () => response()->json(['data' => [
    'version'   => '1.0.1',
    'build'     => 2,          // dibandingkan dengan buildNumber di pubspec.yaml
    'url'       => 'https://contoh.com/otin/app-release.apk',
    'notes'     => 'Perbaikan hitungan tip di struk.',
    'mandatory' => false,
]]));
```

Naikkan `version:` di `pubspec.yaml` (`1.0.0+1` -> `1.0.1+2`) tiap rilis —
angka setelah `+` itulah yang dibandingkan.

---

## Printer struk

Printer thermal Bluetooth 58mm (ESC/POS). Alurnya:

1. Pasangkan printer lewat **Pengaturan Bluetooth Android** (sekali, saat
   pemasangan tablet).
2. Di app: **Pengaturan → Tablet → Cari**, lalu pilih printernya.
3. Pilihan tersimpan; kasir tidak perlu memilih ulang.

Sambungan sengaja dibuka ulang tiap kali mencetak, bukan dipelihara terus —
sambungan Bluetooth ke printer thermal sering putus sendiri setelah menganggur,
dan menyambung ulang jauh lebih murah daripada satu struk gagal di depan
pelanggan.

**Tablet tanpa printer tetap berfungsi penuh.** Yang hilang cuma struk kertas.

---

## Yang belum ada

- **Mode offline.** Sekarang online-only: WiFi mati = kasir tidak bisa
  transaksi, sama seperti web. Untuk pemakaian sendiri ini aman. Tapi begitu
  dijual ke cucian dengan WiFi seadanya, ini akan jadi keluhan nomor satu —
  lapisan repository sudah disiapkan supaya penambahannya nanti tidak
  membongkar layar.
- **Kiosk mode.** Belum dikunci; tablet masih bisa dipakai membuka aplikasi
  lain. Untuk tablet jualan, pertimbangkan Android screen pinning atau
  peluncur kiosk.
- **Deposit pekerja & penyesuaian upah.** Repository-nya sudah ada
  (`PekerjaRepo.deposit`, `.penyesuaian`), layarnya belum — sementara ini
  dikerjakan lewat web.
- **Ikon aplikasi.** Masih ikon bawaan Flutter. Ganti dengan
  `flutter_launcher_icons` sebelum tablet pertama dijual.
