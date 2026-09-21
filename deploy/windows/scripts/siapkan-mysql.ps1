<#
    OTIN CARWASH - menyiapkan MySQL untuk dipakai sungguhan.

    Yang dikerjakan:
      1. Backup database + semua file konfigurasi yang akan disentuh
      2. bind-address = 127.0.0.1  (berhenti mendengarkan ke seluruh WiFi)
      3. Password root dibuat (sekarang kosong)
      4. Akun otin_app (dipakai Laravel) & otin_dev (developer) dengan hak minimum
      5. phpMyAdmin disesuaikan supaya tetap bisa login
      6. .env diperbarui ke akun otin_app
      7. MySQL dipasang sebagai Windows Service supaya hidup lagi setelah restart

    Jalankan sebagai ADMINISTRATOR.

    BAWAANNYA MODE UJI (tidak mengubah apa pun) - hanya menampilkan rencana:
        powershell -ExecutionPolicy Bypass -File deploy\windows\scripts\siapkan-mysql.ps1

    Baru benar-benar menerapkan kalau ditambah -Terapkan :
        powershell -ExecutionPolicy Bypass -File deploy\windows\scripts\siapkan-mysql.ps1 -Terapkan
#>

param([switch]$Terapkan)

$ErrorActionPreference = 'Stop'

$XAMPP    = 'C:\xampp'
$MYSQL    = "$XAMPP\mysql\bin\mysql.exe"
$MYSQLD   = "$XAMPP\mysql\bin\mysqld.exe"
$DUMP     = "$XAMPP\mysql\bin\mysqldump.exe"
$MYINI    = "$XAMPP\mysql\bin\my.ini"
$PMACONF  = "$XAMPP\phpMyAdmin\config.inc.php"
$PROYEK   = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
$ENVFILE  = Join-Path $PROYEK '.env'
$DB       = 'otin_carwash'
$SERVIS   = 'OtinMySQL'

function Info($t) { Write-Host "  $t" -ForegroundColor Gray }
function Oke($t)  { Write-Host "  OK   $t" -ForegroundColor Green }
function Awas($t) { Write-Host "  !!   $t" -ForegroundColor Yellow }
function Mati($t) { Write-Host "  X    $t" -ForegroundColor Red; exit 1 }

Write-Host "`n=== Menyiapkan MySQL OTIN CARWASH ===" -ForegroundColor Cyan
if (-not $Terapkan) {
    Write-Host "MODE UJI - tidak ada yang diubah. Tambahkan -Terapkan untuk benar-benar menjalankan.`n" -ForegroundColor Yellow
}

# --- Prasyarat --------------------------------------------------------------
if ($Terapkan) {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    if (-not (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole(
            [Security.Principal.WindowsBuiltInRole]::Administrator)) {
        Mati "Harus dijalankan sebagai Administrator (untuk memasang service)."
    }
}
foreach ($f in $MYSQL, $MYSQLD, $DUMP, $MYINI) {
    if (-not (Test-Path $f)) { Mati "Tidak ditemukan: $f" }
}
if (-not (Get-Process mysqld -ErrorAction SilentlyContinue)) {
    Mati "MySQL sedang tidak jalan. Nyalakan dulu dari XAMPP Control Panel."
}

# Cek root memang masih tanpa password (kalau sudah ada, jangan ditimpa diam-diam)
& $MYSQL -u root -e "SELECT 1;" *> $null
if ($LASTEXITCODE -ne 0) {
    Awas "root SUDAH punya password. Skrip ini dirancang untuk root yang masih kosong."
    Awas "Kalau memang mau mengulang, hapus dulu password root secara manual."
    exit 1
}
Oke "root masih tanpa password (sesuai dugaan)"

# --- Buat password acak -----------------------------------------------------
# Hanya huruf+angka: aman ditaruh di .env, my.ini, dan perintah SQL tanpa
# perlu escaping yang gampang salah.
function PasswordAcak([int]$panjang = 28) {
    $huruf = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'
    $bytes = [byte[]]::new($panjang)
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    -join ($bytes | ForEach-Object { $huruf[$_ % $huruf.Length] })
}
$pwRoot = PasswordAcak
$pwApp  = PasswordAcak
$pwDev  = PasswordAcak

# --- Rencana ----------------------------------------------------------------
Write-Host "`nRencana:" -ForegroundColor Cyan
Info "1. Backup $DB + my.ini + config.inc.php + .env  ->  storage\backups\mysql-setup\"
Info "2. my.ini          : tambah bind-address=127.0.0.1"
Info "3. root@localhost  : diberi password acak 28 karakter"
Info "4. otin_app@127.0.0.1 : SELECT/INSERT/UPDATE/DELETE + DDL pada $DB saja"
Info "5. otin_dev@127.0.0.1 : ALL PRIVILEGES pada $DB saja, maks 5 koneksi"
Info "6. Hapus akun anonim, root@'%', dan database 'test'"
Info "7. phpMyAdmin      : dipakaikan password root yang baru"
Info "8. .env            : DB_USERNAME=otin_app + password barunya"
Info "9. Pasang service '$SERVIS' (start otomatis saat Windows menyala)"

if (-not $Terapkan) {
    Write-Host "`nSelesai (mode uji). Tidak ada yang diubah." -ForegroundColor Yellow
    Write-Host "Jalankan ulang dengan -Terapkan bila rencana di atas sudah cocok.`n"
    exit 0
}

# --- 1. Backup --------------------------------------------------------------
$stamp  = Get-Date -Format 'yyyyMMdd-HHmmss'
$bakDir = Join-Path $PROYEK "storage\backups\mysql-setup\$stamp"
New-Item -ItemType Directory -Force -Path $bakDir | Out-Null

& $DUMP -u root --databases $DB --routines --events --single-transaction `
    --result-file="$bakDir\$DB.sql"
if ($LASTEXITCODE -ne 0) { Mati "mysqldump gagal - dihentikan sebelum mengubah apa pun." }
foreach ($f in $MYINI, $PMACONF, $ENVFILE) {
    if (Test-Path $f) { Copy-Item $f (Join-Path $bakDir (Split-Path $f -Leaf)) }
}
Oke "Backup tersimpan di $bakDir"

# --- 2. bind-address --------------------------------------------------------
$ini = Get-Content $MYINI -Raw
if ($ini -notmatch '(?m)^\s*bind-address') {
    # Sisipkan tepat setelah [mysqld] supaya pasti terbaca.
    $ini = $ini -replace '(?m)^\[mysqld\]', "[mysqld]`r`n# Hanya melayani koneksi dari komputer ini. Akses dari luar lewat tunnel.`r`nbind-address=127.0.0.1"
    Set-Content -Path $MYINI -Value $ini -Encoding ASCII
    Oke "bind-address=127.0.0.1 ditambahkan ke my.ini"
} else {
    Awas "bind-address sudah ada di my.ini - dibiarkan apa adanya"
}

# --- 3-6. Akun & pembersihan ------------------------------------------------
$sql = @"
ALTER USER 'root'@'localhost' IDENTIFIED BY '$pwRoot';

CREATE USER IF NOT EXISTS 'otin_app'@'127.0.0.1' IDENTIFIED BY '$pwApp';
GRANT SELECT, INSERT, UPDATE, DELETE ON ``$DB``.* TO 'otin_app'@'127.0.0.1';
GRANT CREATE, ALTER, INDEX, DROP, REFERENCES ON ``$DB``.* TO 'otin_app'@'127.0.0.1';

CREATE USER IF NOT EXISTS 'otin_dev'@'127.0.0.1' IDENTIFIED BY '$pwDev';
GRANT ALL PRIVILEGES ON ``$DB``.* TO 'otin_dev'@'127.0.0.1';
ALTER USER 'otin_dev'@'127.0.0.1' WITH MAX_USER_CONNECTIONS 5 MAX_QUERIES_PER_HOUR 20000;

DELETE FROM mysql.user WHERE User = '';
DROP USER IF EXISTS 'root'@'%';
DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
"@
$sqlTmp = Join-Path $env:TEMP "otin-mysql-$stamp.sql"
Set-Content -Path $sqlTmp -Value $sql -Encoding ASCII
try {
    & $MYSQL -u root -e "source $($sqlTmp -replace '\\','/')"
    if ($LASTEXITCODE -ne 0) { Mati "Perintah SQL gagal. Database dipulihkan dari $bakDir bila perlu." }
} finally {
    Remove-Item $sqlTmp -Force -ErrorAction SilentlyContinue   # jangan tinggalkan password di disk
}
Oke "Password root dipasang; akun otin_app & otin_dev dibuat; akun bawaan dibersihkan"

# --- 7. phpMyAdmin ----------------------------------------------------------
if (Test-Path $PMACONF) {
    $pma = Get-Content $PMACONF -Raw
    $pma = $pma -replace "(\`$cfg\['Servers'\]\[\`$i\]\['password'\]\s*=\s*)'[^']*'", "`$1'$pwRoot'"
    Set-Content -Path $PMACONF -Value $pma -Encoding ASCII
    Oke "phpMyAdmin disesuaikan dengan password root baru"
} else {
    Awas "phpMyAdmin tidak ditemukan - dilewati"
}

# --- 8. .env ----------------------------------------------------------------
if (Test-Path $ENVFILE) {
    $envIsi = Get-Content $ENVFILE -Raw
    $envIsi = $envIsi -replace '(?m)^DB_USERNAME=.*$', 'DB_USERNAME=otin_app'
    $envIsi = $envIsi -replace '(?m)^DB_PASSWORD=.*$', "DB_PASSWORD=$pwApp"
    $envIsi = $envIsi -replace '(?m)^DB_HOST=.*$',     'DB_HOST=127.0.0.1'
    Set-Content -Path $ENVFILE -Value $envIsi -Encoding ASCII -NoNewline
    Oke ".env diarahkan ke akun otin_app"
}

# --- 9. Service -------------------------------------------------------------
if (Get-Service $SERVIS -ErrorAction SilentlyContinue) {
    Awas "Service $SERVIS sudah ada - dilewati"
} else {
    # Hentikan mysqld yang dijalankan XAMPP Control Panel supaya port bebas.
    Get-Process mysqld -ErrorAction SilentlyContinue | Stop-Process -Force
    Start-Sleep -Seconds 3
    & $MYSQLD --install $SERVIS --defaults-file="$MYINI"
    if ($LASTEXITCODE -ne 0) { Mati "Gagal memasang service. MySQL bisa dinyalakan lagi dari XAMPP Control Panel." }
    Set-Service $SERVIS -StartupType Automatic
    Start-Service $SERVIS
    Start-Sleep -Seconds 4
    Oke "Service $SERVIS terpasang & jalan (otomatis saat Windows menyala)"
    Awas "Mulai sekarang MySQL dikelola service ini, JANGAN dinyalakan lagi dari XAMPP Control Panel."
}

# --- Verifikasi -------------------------------------------------------------
Write-Host "`nVerifikasi:" -ForegroundColor Cyan
& $MYSQL -u otin_app "-p$pwApp" -h 127.0.0.1 -e "SELECT COUNT(*) AS transaksi FROM $DB.transactions;" 2>$null
if ($LASTEXITCODE -eq 0) { Oke "Laravel bisa konek pakai otin_app" } else { Awas "otin_app GAGAL konek - periksa .env" }

& $MYSQL -u root -e "SELECT 1;" *> $null
if ($LASTEXITCODE -ne 0) { Oke "root tanpa password sudah ditolak" } else { Awas "root MASIH bisa tanpa password!" }

# --- Password: tampilkan sekali ---------------------------------------------
$catatan = Join-Path $bakDir 'PASSWORD-BARU.txt'
@"
OTIN CARWASH - password MySQL yang dibuat $stamp

root      : $pwRoot
otin_app  : $pwApp    (sudah otomatis masuk ke .env)
otin_dev  : $pwDev    (untuk developer lewat tunnel)

SIMPAN DI TEMPAT AMAN (password manager), LALU HAPUS FILE INI.
File ini berada di dalam folder proyek - jangan sampai ikut ter-commit.
"@ | Set-Content -Path $catatan -Encoding UTF8

Write-Host "`n================ PASSWORD BARU ================" -ForegroundColor Yellow
Write-Host "root     : $pwRoot"
Write-Host "otin_app : $pwApp"
Write-Host "otin_dev : $pwDev"
Write-Host "===============================================" -ForegroundColor Yellow
Write-Host "Juga disalin ke: $catatan" -ForegroundColor Gray
Write-Host "Simpan ke password manager, lalu HAPUS file itu.`n" -ForegroundColor Yellow
Write-Host "Langkah terakhir:  php artisan config:clear" -ForegroundColor Cyan
