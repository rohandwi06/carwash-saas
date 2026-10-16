# Rilis aplikasi Kasir Carwash ke Play Store

Aplikasi Android (`android-app/`, Flutter) hanya tampilan: semua hitungan, data, AI, dan resi
PDF ada di server Laravel tiap cucian. HP hanya menyimpan token login, alamat server, dan
halaman terakhir.

## 1. Pasang daftar kode cucian (sekali, lalu tiap ada klien baru)

Pengguna Play Store tidak mengetik alamat server; mereka mengisi **kode cucian**. Aplikasi
membaca alamatnya dari `https://byrapiin.my.id/cucian.json`.

1. File Manager akun **byrapiin** → `public_html` → Upload `cucian.json` dari folder ini.
2. Cek: buka https://byrapiin.my.id/cucian.json di browser — harus tampil isinya.
3. Klien baru: edit berkasnya, tambah satu baris di dalam `"cucian"`:

   ```json
   "kodebaru": { "nama": "NAMA CARWASH", "url": "https://kodebaru-carwash.byrapiin.my.id" },
   ```

   Kode: huruf kecil dan angka saja. Perhatikan koma antarbaris — JSON yang rusak membuat
   SEMUA pengguna baru tidak bisa masuk (pengguna lama tidak terpengaruh).

Cucian yang belum masuk daftar tetap bisa dipakai lewat "Punya alamat server? Isi alamatnya".

## 2. Kebijakan privasi (wajib untuk Play Store)

`privasi.html` di folder ini → isi bagian bertanda `[...]` (nama penyedia, email kontak,
tanggal) → unggah ke `public_html` akun byrapiin → alamatnya
`https://byrapiin.my.id/privasi.html` dipakai di Play Console.

## 3. Kunci tanda tangan (sekali, SIMPAN BAIK-BAIK)

Kunci ini membuktikan setiap pembaruan berasal dari Anda. Hilang = repot (harus minta reset ke
Google). Jangan masuk git, jangan dikirim lewat chat.

```powershell
keytool -genkey -v -keystore C:\kunci\kasir-carwash-upload.jks -keyalg RSA -keysize 2048 -validity 10000 -alias upload
```

(`keytool` ada di folder `jbr\bin` Android Studio bila tidak dikenal.) Lalu buat berkas
`android-app\android\key.properties`:

```
storePassword=<kata sandi keystore>
keyPassword=<kata sandi kunci>
keyAlias=upload
storeFile=C:\\kunci\\kasir-carwash-upload.jks
```

Simpan salinan `.jks` dan kata sandinya di tempat aman di luar laptop.

## 4. Membangun

```powershell
cd android-app
flutter build appbundle            # untuk Play Store -> build\app\outputs\bundle\release\app-release.aab
flutter build apk --dart-define=DISTRIBUSI=apk   # APK untuk tablet yang dijual di luar Play
```

Versi Play **tidak** menampilkan bilah "versi baru tersedia" (aturan Play melarang aplikasi
menawarkan APK sendiri). Naikkan `version:` di `pubspec.yaml` (angka setelah `+`) tiap rilis.

## 5. Play Console

- Akun Google Play Developer (biaya pendaftaran sekali).
- ID aplikasi: `id.rapiin.kasir_carwash` — **tidak bisa diganti** setelah rilis pertama.
- Aktifkan *Play App Signing*; kunci di langkah 3 menjadi *upload key*.
- Formulir **Keamanan data**: data yang dikumpulkan = nama & nomor telepon pelanggan, foto
  (opsional), data akun; disimpan di server usaha; dikirim ke Google Gemini hanya teks/foto
  kendaraan; dienkripsi saat dikirim (HTTPS); pengguna bisa meminta penghapusan.
- Kebijakan privasi: URL dari langkah 2.
- Akun pengembang pribadi yang baru saat ini wajib menjalankan **uji tertutup** dengan
  sejumlah penguji selama beberapa minggu sebelum boleh rilis produksi — cek aturan terbaru di
  Play Console saat mendaftar.
