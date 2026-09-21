<#
    OTIN CARWASH — pemeriksaan keamanan sebelum aplikasi dibuka ke internet.

    Jalankan:  powershell -ExecutionPolicy Bypass -File deploy\windows\scripts\preflight-keamanan.ps1

    Skrip ini TIDAK mengubah apa pun. Dia hanya memeriksa dan melaporkan.
    Selama masih ada baris GAGAL, jangan nyalakan tunnel — di LAN toko
    kelemahan ini tidak terlihat, di internet langsung jadi pintu masuk.
#>

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
$envPath = Join-Path $root '.env'

$gagal = 0
$peringatan = 0

function Hasil($status, $judul, $pesan) {
    $warna = switch ($status) { 'OK' { 'Green' } 'GAGAL' { 'Red' } default { 'Yellow' } }
    Write-Host ("[{0,-6}] {1}" -f $status, $judul) -ForegroundColor $warna
    if ($pesan) { Write-Host ("         -> {0}" -f $pesan) -ForegroundColor DarkGray }
}
function Lulus($j)        { Hasil 'OK' $j $null }
function Gagal($j, $p)    { $script:gagal++;      Hasil 'GAGAL' $j $p }
function Waspada($j, $p)  { $script:peringatan++; Hasil 'WARN'  $j $p }

Write-Host "`n=== Preflight keamanan OTIN CARWASH ===`n" -ForegroundColor Cyan

if (-not (Test-Path $envPath)) {
    Gagal '.env ada' "File .env tidak ditemukan di $root"
    Write-Host "`nHentikan. Tanpa .env aplikasi tidak bisa jalan." -ForegroundColor Red
    exit 1
}

# Baca .env jadi hashtable sederhana (KEY=VALUE, abaikan komentar).
$conf = @{}
Get-Content $envPath | ForEach-Object {
    if ($_ -match '^\s*([A-Z_0-9]+)\s*=\s*(.*)$') {
        $conf[$Matches[1]] = $Matches[2].Trim().Trim('"')
    }
}

# --- 1. APP_KEY -------------------------------------------------------------
# Kunci lama pernah ter-commit ke GitHub publik. Siapa pun yang punya kunci
# itu bisa memalsukan cookie sesi & membuka data terenkripsi.
#
# Nilai yang bocor dibandingkan lewat SHA-256, bukan ditulis apa adanya:
# berkas ini ikut masuk repo, dan menuliskan ulang rahasia lama di sana
# sama saja membocorkannya untuk kedua kalinya.
function Sidik($teks) {
    $sha = [System.Security.Cryptography.SHA256]::Create()
    $b = $sha.ComputeHash([System.Text.Encoding]::UTF8.GetBytes([string]$teks))
    return (($b | ForEach-Object { $_.ToString('x2') }) -join '')
}
$SIDIK_BOCOR = @{
    APP_KEY        = '752250ef528b8ab2b1ff7e981e1f412da5c2a847642aea720ec5dfb5584ab65e'
    DB_PASSWORD    = '4a9e1e5e0682f23e964bc75388e58c92b43ffc0198f2bb43050abf931b0858eb'
    GEMINI_API_KEY = '4dc65a5f439513a46f531db575af5344a1609f9df94f888fc3ed8f521a0ae982'
}

if (-not $conf['APP_KEY']) {
    Gagal 'APP_KEY terisi' 'APP_KEY kosong. Jalankan: php artisan key:generate'
} elseif ((Sidik $conf['APP_KEY']) -eq $SIDIK_BOCOR.APP_KEY) {
    Gagal 'APP_KEY sudah diganti' 'Masih memakai kunci yang BOCOR di git history. Jalankan: php artisan key:generate'
} else {
    Lulus 'APP_KEY sudah diganti dari yang bocor'
}

# --- 2. Mode aplikasi -------------------------------------------------------
if ($conf['APP_DEBUG'] -match '^(true|1)$') {
    Gagal 'APP_DEBUG mati' 'APP_DEBUG=true membocorkan isi .env & stack trace ke pengunjung. Set APP_DEBUG=false'
} else { Lulus 'APP_DEBUG mati' }

if ($conf['APP_ENV'] -ne 'production') {
    Waspada 'APP_ENV=production' ("Sekarang '{0}'. Set production sebelum dipakai sungguhan." -f $conf['APP_ENV'])
} else { Lulus 'APP_ENV=production' }

# --- 3. Password owner ------------------------------------------------------
$pw = $conf['OWNER_PASSWORD']
if (-not $pw) {
    Gagal 'OWNER_PASSWORD kuat' 'Kosong — owner tidak bisa login sama sekali.'
} elseif ($pw.Length -lt 12) {
    Gagal 'OWNER_PASSWORD kuat' ("Panjang cuma {0} karakter. Di internet ini ditebak dalam hitungan menit. Pakai minimal 12 karakter acak." -f $pw.Length)
} else { Lulus 'OWNER_PASSWORD kuat' }

# --- 4. Password database ---------------------------------------------------
if (-not $conf['DB_PASSWORD']) {
    Gagal 'DB_PASSWORD terisi' 'Password MySQL kosong. Wajib diisi sebelum MySQL bisa dijangkau dari luar.'
} elseif ((Sidik $conf['DB_PASSWORD']) -eq $SIDIK_BOCOR.DB_PASSWORD) {
    Gagal 'DB_PASSWORD sudah dirotasi' 'Masih memakai password yang BOCOR di git history. Ganti di MySQL lalu di .env.'
} else { Lulus 'DB_PASSWORD terisi & bukan yang bocor' }

# --- 5. API key Gemini ------------------------------------------------------
if ((Sidik $conf['GEMINI_API_KEY']) -eq $SIDIK_BOCOR.GEMINI_API_KEY) {
    Gagal 'GEMINI_API_KEY dirotasi' 'Masih memakai key yang BOCOR di git history. Revoke di Google AI Studio, buat key baru.'
} elseif (-not $conf['GEMINI_API_KEY']) {
    Waspada 'GEMINI_API_KEY terisi' 'Kosong — fitur Tanya AI tidak jalan (fitur lain aman).'
} else { Lulus 'GEMINI_API_KEY bukan yang bocor' }

# --- 6. .env tidak ikut git -------------------------------------------------
Push-Location $root
try {
    $tracked = & git ls-files --error-unmatch .env 2>$null
    if ($LASTEXITCODE -eq 0) { Gagal '.env tidak dilacak git' '.env masih ter-track. Jalankan: git rm --cached .env' }
    else { Lulus '.env tidak dilacak git' }
} catch { Lulus '.env tidak dilacak git' }
Pop-Location

# --- 7. MySQL hanya mendengar di localhost ----------------------------------
# Tunnel menyambung dari mesin ini juga, jadi MySQL TIDAK perlu (dan tidak
# boleh) mendengarkan di 0.0.0.0.
$myIni = 'C:\xampp\mysql\bin\my.ini'
if (Test-Path $myIni) {
    $bind = Select-String -Path $myIni -Pattern '^\s*bind-address\s*=' | Select-Object -Last 1
    if (-not $bind) {
        Waspada 'MySQL bind ke localhost' "bind-address belum diatur di $myIni. Tambahkan 'bind-address=127.0.0.1' di bawah [mysqld]."
    } elseif ($bind.Line -match '127\.0\.0\.1|localhost') {
        Lulus 'MySQL bind ke localhost'
    } else {
        Gagal 'MySQL bind ke localhost' ("Sekarang: {0}. Ubah jadi bind-address=127.0.0.1" -f $bind.Line.Trim())
    }
} else {
    Waspada 'MySQL bind ke localhost' "my.ini tidak ditemukan di $myIni — periksa manual."
}

# --- 8. Root MySQL punya password -------------------------------------------
$mysqlExe = 'C:\xampp\mysql\bin\mysql.exe'
if (Test-Path $mysqlExe) {
    & $mysqlExe -u root -e "SELECT 1;" *> $null
    if ($LASTEXITCODE -eq 0) {
        Gagal 'root MySQL berpassword' 'root MASIH BISA login tanpa password. Set password root sebelum MySQL dijangkau dari luar.'
    } else {
        Lulus 'root MySQL berpassword'
    }
}

# --- Ringkasan --------------------------------------------------------------
Write-Host ""
if ($gagal -gt 0) {
    Write-Host ("GAGAL: {0} masalah wajib diperbaiki, {1} peringatan." -f $gagal, $peringatan) -ForegroundColor Red
    Write-Host "JANGAN nyalakan tunnel dulu." -ForegroundColor Red
    exit 1
}
if ($peringatan -gt 0) {
    Write-Host ("Lolos, dengan {0} peringatan. Baca lagi di atas." -f $peringatan) -ForegroundColor Yellow
    exit 0
}
Write-Host "Semua pemeriksaan lolos. Aman dilanjutkan." -ForegroundColor Green
