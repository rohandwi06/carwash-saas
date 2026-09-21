# Hosting OTIN CARWASH lewat Cloudflare Tunnel (Windows)

Panduan ini menggantikan mode LAN di [`DEPLOY.md`](../../DEPLOY.md) ketika aplikasi
mau bisa dibuka dari luar toko.

## Yang sebenarnya Anda bangun

Cloudflare Tunnel **bukan** serverless. Komputer toko tetap jadi servernya dan
harus tetap hidup. Yang Anda dapat dari tunnel adalah:

- tidak perlu IP publik dan tidak perlu buka port di router,
- HTTPS otomatis,
- port MySQL tidak pernah terbuka ke internet.

Alurnya:

```
HP kasir / laptop developer
        |  https
        v
  Cloudflare edge
        |  tunnel keluar (outbound), router tidak dibuka sama sekali
        v
  cloudflared  (komputer toko)
        |
        +--> nginx :8080 --> php-cgi :9000/:9001 --> Laravel
        |
        +--> MySQL :3306   (hanya lewat Zero Trust, lihat Tahap 3)
```

### Catatan Windows: php-fpm tidak ada

php-fpm hanya untuk Linux. Padanannya di Windows adalah **php-cgi.exe** yang
dijalankan sebagai FastCGI listener - itulah yang dipakai di sini. Bedanya satu
proses php-cgi melayani satu permintaan pada satu waktu, jadi kita jalankan dua
instance (port 9000 dan 9001) dan nginx membagi beban ke keduanya.

Kalau nanti trafiknya tumbuh atau Anda ingin lebih tenang, pindah ke VPS Linux
kecil (nginx + php-fpm asli) jauh lebih ringan diurus daripada menambal ini.

---

## Tahap 0 - WAJIB: tutup lubang keamanan dulu

Aplikasi ini dirancang untuk LAN toko. Ada beberapa hal yang aman di LAN tapi
berbahaya begitu tersambung internet. Jalankan:

```bash
powershell -ExecutionPolicy Bypass -File deploy\windows\scripts\preflight-keamanan.ps1
```

Saat panduan ini ditulis, hasilnya **6 GAGAL**. Semuanya harus hijau sebelum
Anda lanjut ke Tahap 1:

| Masalah | Kenapa gawat | Cara memperbaiki |
|---|---|---|
| `APP_KEY` masih yang bocor di GitHub | Siapa pun yang punya kunci itu bisa **memalsukan cookie sesi** dan masuk sebagai owner | `php artisan key:generate` |
| `APP_DEBUG=true` | Halaman error menampilkan **isi `.env`** lengkap dengan password | set `APP_DEBUG=false` |
| `OWNER_PASSWORD` cuma 4 digit | Habis ditebak dalam hitungan menit | ganti, minimal 12 karakter acak |
| `DB_PASSWORD` kosong | - | isi, lalu samakan di MySQL |
| `GEMINI_API_KEY` masih yang bocor | Tagihan API bisa dipakai orang lain | revoke di Google AI Studio, buat baru |
| root MySQL tanpa password | Akses penuh ke seluruh database | jalankan `mysql\akses-developer.sql` |

> Mengganti `APP_KEY` membuat semua sesi login sekarang gugur - semua orang
> tinggal login ulang. Data transaksi tidak terpengaruh (tidak ada kolom
> terenkripsi di aplikasi ini).

Lalu di `.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://kasir.domain-anda.com     # atau URL quick tunnel saat uji coba
```

Terakhir:

```bash
php artisan config:clear
```

---

## Jalur yang dipakai sekarang: Apache + Quick Tunnel

Setelah ditimbang, **Apache bawaan XAMPP** yang dipakai, bukan nginx + php-cgi.
Alasannya: Apache sudah terpasang, php-nya menyatu, dan tidak butuh dua proses
php-cgi plus NSSM yang harus dijaga hidup sendiri-sendiri. Di Windows itu jauh
lebih sedikit yang bisa rusak. Bagian nginx di bawah tetap disimpan kalau
suatu saat pindah ke Linux.

Yang sudah terpasang dan teruji:

| Bagian | Keadaan |
|---|---|
| Apache melayani `public/` di **port 8080** | sudah, `deploy/windows/apache/otin-carwash.conf` |
| `.env` ditolak dari web (403) | sudah diuji |
| Routing Laravel & aset statis | sudah diuji |
| cloudflared terpasang di `C:\cloudflared` | sudah, tanda tangan Cloudflare terverifikasi |
| Quick Tunnel + HTTPS | sudah diuji, `asset()` benar memakai `https://` |

### Menyalakan tunnel

```bash
powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\otin-carwash\deploy\windows\scripts\jalankan-tunnel.ps1
```

Skrip ini menolak menyala selama preflight keamanan masih merah. Kalau benar-
benar perlu dipaksa (mis. uji coba sebentar di jaringan sendiri), tambahkan
`-Abaikan`. Mematikan: tambahkan `-Matikan`.

URL yang keluar **acak dan berubah setiap kali dinyalakan**. Untuk URL tetap,
lanjut ke Tahap 2b (perlu domain).

### Kalau Apache belum jalan

Nyalakan dari XAMPP Control Panel, atau:

```bash
C:\xampp\apache\bin\httpd.exe
```

Konfigurasi vhost-nya ada di repo (`deploy/windows/apache/otin-carwash.conf`)
dan dipanggil lewat satu baris `Include` di `C:\xampp\apache\conf\httpd.conf`.
Cadangan berkas asli: `httpd.conf.bak-otin`.

---

## Alternatif - nginx + php-cgi jalan sebagai service

### 1.1 Unduh yang belum ada

| Perangkat | Dari | Taruh di |
|---|---|---|
| nginx (Windows, stable) | <https://nginx.org/en/download.html> | `C:\nginx\` |
| NSSM | <https://nssm.cc/download> | `C:\nssm\nssm.exe` |
| cloudflared | <https://github.com/cloudflare/cloudflared/releases> (`cloudflared-windows-amd64.exe`) | `C:\cloudflared\cloudflared.exe` |

php-cgi.exe sudah ada bawaan XAMPP di `C:\xampp\php\php-cgi.exe`.

### 1.2 Pasang konfigurasi nginx

```bash
copy deploy\windows\nginx\otin-carwash.conf C:\nginx\conf\conf.d\
```

Buat foldernya kalau belum ada, lalu buka `C:\nginx\conf\nginx.conf` dan
tambahkan satu baris di **dalam** blok `http { ... }`:

```nginx
include conf.d/*.conf;
```

Uji sintaksnya:

```bash
C:\nginx\nginx.exe -t
```

### 1.3 Matikan Apache XAMPP

Apache dan nginx tidak boleh berebut port. Lewat XAMPP Control Panel: **Stop**
Apache, lalu klik **Config -> Service and Port Settings** dan matikan autostart-nya.
MySQL tetap dinyalakan.

### 1.4 Pasang service

Klik kanan PowerShell -> **Run as administrator**:

```bash
powershell -ExecutionPolicy Bypass -File deploy\windows\scripts\pasang-service.ps1
```

Uji:

```bash
curl.exe -I http://localhost:8080
```

Harus membalas `HTTP/1.1 200 OK`. Kalau `502 Bad Gateway`, berarti php-cgi belum
hidup - cek `Get-Service OtinPHP9000`.

Untuk melepas semuanya lagi: tambahkan `-Hapus` di perintah yang sama.

---

## Tahap 2 - Cloudflare Tunnel

### 2a. Tanpa domain (uji coba - Quick Tunnel)

```bash
C:\cloudflared\cloudflared.exe tunnel --url http://localhost:8080
```

cloudflared mencetak URL acak seperti `https://xxx-yyy-zzz.trycloudflare.com`.
Buka dari HP mana pun - langsung jalan, tanpa daftar akun.

**Batasnya, dan kenapa ini bukan untuk produksi:**

- URL berubah **setiap kali** cloudflared di-restart,
- tidak bisa dipakai untuk MySQL (Quick Tunnel hanya HTTP),
- tidak ada Access Policy - siapa pun yang tahu URL-nya bisa membuka halaman
  login. Password owner Anda satu-satunya penjaga.

Pakai ini untuk membuktikan jalurnya bekerja, lalu naik ke 2b.

### 2b. Dengan domain (produksi)

Perlu domain yang nameserver-nya diarahkan ke Cloudflare (paket gratis cukup).
Domain `.com` sekitar Rp 150-200 ribu per tahun.

```bash
# 1. Login - browser terbuka, Anda pilih domainnya. Lakukan sendiri,
#    jangan diwakilkan; ini mengikat akun Cloudflare Anda.
cloudflared tunnel login

# 2. Buat tunnel
cloudflared tunnel create otin-carwash
#    Catat TUNNEL-ID yang muncul.

# 3. Arahkan subdomain ke tunnel
cloudflared tunnel route dns otin-carwash kasir.domain-anda.com

# 4. Pasang konfigurasi
copy deploy\windows\cloudflared\config.yml %USERPROFILE%\.cloudflared\config.yml
#    lalu edit: ganti <TUNNEL-ID>, <USER>, dan <DOMAIN-ANDA>

# 5. Jadikan service supaya hidup lagi setelah komputer restart
cloudflared service install
```

Setelah itu perbarui `.env`:

```
APP_URL=https://kasir.domain-anda.com
```

lalu `php artisan config:clear`.

### 2c. Kunci pintunya (sangat disarankan)

Aplikasi kasir tidak seharusnya bisa dibuka publik. Di dashboard
**Zero Trust -> Access -> Applications**, buat aplikasi Self-hosted untuk
`kasir.domain-anda.com` dengan policy:

- **Allow** -> *Emails* -> daftar email kasir & owner, atau
- **Allow** -> *IP ranges* -> IP publik toko (kalau IP-nya tetap).

Dengan begini, sebelum halaman login OTIN muncul pun, Cloudflare sudah menyaring
lebih dulu. Dua lapis kunci untuk data yang isinya uang.

---

## Tahap 3 - MySQL untuk developer

### Kenapa tidak buka port 3306 saja

Karena MySQL yang menganga di internet akan dipindai dan dicoba-tebak dalam
hitungan jam, dan isinya seluruh pembukuan toko. Cloudflare Zero Trust memberi
akses yang sama tanpa membuka port apa pun.

**Prasyarat: Tahap 2b sudah jalan.** Zero Trust butuh domain - tidak bisa dengan
Quick Tunnel.

### 3.1 Rapikan akun MySQL

Buka `deploy\windows\mysql\akses-developer.sql`, ganti ketiga password contoh,
lalu jalankan:

```bash
C:\xampp\mysql\bin\mysql.exe -u root -p < deploy\windows\mysql\akses-developer.sql
```

Isi `.env` dengan akun aplikasi yang baru:

```
DB_USERNAME=otin_app
DB_PASSWORD=<password-aplikasi-yang-tadi>
```

Pastikan juga `C:\xampp\mysql\bin\my.ini` punya baris ini di bawah `[mysqld]`:

```ini
bind-address=127.0.0.1
```

### 3.2 Buka jalur TCP di tunnel

Bagian `db.<DOMAIN-ANDA>` di `config.yml` sudah disiapkan. Restart cloudflared,
lalu di dashboard Zero Trust buat Access Application untuk hostname itu dengan
policy **Allow -> Emails -> email developer**.

> Tanpa policy ini, hostname tersebut terbuka bagi siapa pun yang menebak
> namanya. Policy-nya bukan opsional.

### 3.3 Dari sisi developer

Di laptop developer (cukup punya cloudflared, tidak perlu akses ke komputer toko):

```bash
cloudflared access tcp --hostname db.domain-anda.com --url localhost:3307
```

Browser terbuka sekali untuk verifikasi email. Selama perintah itu jalan,
MySQL toko muncul sebagai `localhost:3307` di laptop developer:

```bash
mysql -h 127.0.0.1 -P 3307 -u otin_dev -p otin_carwash
```

DBeaver / TablePlus / HeidiSQL tinggal diarahkan ke `127.0.0.1:3307`. Koneksinya
langsung ke MySQL yang sama dengan yang dipakai kasir - real time, tanpa replikasi.

---

## Operasional harian

| Kebutuhan | Perintah |
|---|---|
| Lihat status | `Get-Service OtinNginx, OtinPHP9000, OtinPHP9001, cloudflared` |
| Restart sesudah update kode | `Restart-Service OtinPHP9000, OtinPHP9001` |
| Log aplikasi | `storage\logs\laravel.log` |
| Log nginx | `C:\nginx\logs\otin-error.log` |
| Log tunnel | Event Viewer -> Application, sumber `cloudflared` |

Sesudah menarik kode baru:

```bash
php artisan migrate --force
php artisan config:clear
Restart-Service OtinPHP9000, OtinPHP9001
```

---

## Yang masih jadi risiko

Jujur soal batasnya, supaya Anda memutuskan dengan sadar:

1. **Komputer toko = titik tunggal kegagalan.** Mati listrik, kena virus, atau
   hardisk rusak berarti kasir berhenti total. Backup harian ke `storage/backups/`
   sudah jalan, tapi **salin keluar** (Google Drive/flashdisk) minimal seminggu sekali.
2. **Listrik dan internet toko** menentukan aplikasi hidup atau tidak. UPS kecil
   sangat membantu.
3. **Belum ada rate limit di endpoint login** selain throttle 10/menit bawaan.
   Access Policy di Tahap 2c yang menutupi celah ini - jangan dilewati.
4. **Jejak audit masih per-role, bukan per-orang** untuk sebagian aksi.
5. **Rahasia yang pernah bocor tetap ada di histori git GitHub.** Mengganti
   nilainya sudah benar, tapi commit lamanya masih bisa dibaca siapa pun.
   Kalau repo-nya publik, pertimbangkan menjadikannya privat.
