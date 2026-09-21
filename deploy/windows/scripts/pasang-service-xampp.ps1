<#
    OTIN CARWASH - menjadikan MySQL & Apache sebagai Windows Service.

    Jalankan sebagai ADMINISTRATOR:
        powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\otin-carwash\deploy\windows\scripts\pasang-service-xampp.ps1

    KENAPA INI PENTING

    Selama MySQL & Apache dinyalakan lewat skrip biasa (tugas terjadwal
    "OtinCarwash Stack"), XAMPP Control Panel TIDAK BISA MELIHATNYA - panel
    hanya mengenali service, dan proses milik SYSTEM tidak tampak olehnya.
    Panel terus menulis "stopped" padahal MySQL sedang melayani.

    Akibatnya nyata: pada 27 Juli 2026 tombol Start ditekan empat kali
    berturut-turut (15:25:44, 15:26:25, 15:26:44, 15:27:41) karena panel
    tidak pernah berubah hijau. Dua mysqld pada satu folder data membuat
    InnoDB gagal dan kasir mati total.

    Sebagai service, tiga hal beres sekaligus:
      1. Panel membaca status yang benar - tidak ada lagi dorongan menekan Start
      2. Windows sendiri MENOLAK instance kedua - salah klik jadi tidak berbahaya
      3. Windows yang menyalakan & mengawasi, lebih andal daripada skrip VBS

    Nama service sengaja memakai nama baku XAMPP ('mysql' dan 'Apache2.4')
    supaya Control Panel mengenalinya.

    Parameter:
      -Hapus     lepas kedua service, kembali ke cara lama
      -Periksa   tampilkan kondisi sekarang
#>

param([switch]$Hapus, [switch]$Periksa)

$ErrorActionPreference = 'Stop'

$PROYEK  = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
$MYSQLD  = 'C:\xampp\mysql\bin\mysqld.exe'
$MYINI   = 'C:\xampp\mysql\bin\my.ini'
$HTTPD   = 'C:\xampp\apache\bin\httpd.exe'
$SVC_SQL = 'mysql'
$SVC_WEB = 'Apache2.4'
$T_STACK = 'OtinCarwash Stack'

function Oke($t)  { Write-Host "  OK   $t" -ForegroundColor Green }
function Awas($t) { Write-Host "  !!   $t" -ForegroundColor Yellow }
function Mati($t) { Write-Host "  X    $t" -ForegroundColor Red; exit 1 }

function AdalahAdmin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
}

function LaporKondisi {
    foreach ($s in $SVC_SQL, $SVC_WEB) {
        $svc = Get-Service $s -ErrorAction SilentlyContinue
        if ($svc) { Oke "Service '$s' - $($svc.Status), start $($svc.StartType)" }
        else      { Awas "Service '$s' belum terpasang" }
    }
    foreach ($n in 'mysqld','httpd') {
        $c = @(Get-Process $n -ErrorAction SilentlyContinue).Count
        $pesan = "$n : $c proses"
        # httpd memang selalu sepasang (induk + pekerja). mysqld harus SATU.
        if ($n -eq 'mysqld' -and $c -gt 1) { Awas "$pesan  <- DOBEL, ini yang bikin InnoDB gagal" }
        else { Oke $pesan }
    }
}

Write-Host "`n=== MySQL & Apache sebagai Windows Service ===" -ForegroundColor Cyan

if ($Periksa) { LaporKondisi; Write-Host ""; exit 0 }
if (-not (AdalahAdmin)) { Mati "Harus dijalankan sebagai Administrator." }

# --- Mode hapus -------------------------------------------------------------
if ($Hapus) {
    foreach ($s in $SVC_SQL, $SVC_WEB) {
        if (Get-Service $s -ErrorAction SilentlyContinue) {
            Stop-Service $s -Force -ErrorAction SilentlyContinue
            if ($s -eq $SVC_WEB) { & $HTTPD -k uninstall -n $SVC_WEB | Out-Null }
            else                 { & $MYSQLD --remove $SVC_SQL | Out-Null }
            Oke "Service '$s' dilepas"
        } else { Awas "Service '$s' tidak ada" }
    }
    Awas "Nyalakan lagi lewat XAMPP Control Panel, atau pasang kembali tugas"
    Awas "'$T_STACK' dengan pasang-startup.ps1."
    exit 0
}

foreach ($f in $MYSQLD, $MYINI, $HTTPD) {
    if (-not (Test-Path $f)) { Mati "Tidak ditemukan: $f" }
}

# --- 1. Amankan data dulu ---------------------------------------------------
Write-Host "`nMembuat backup sebelum menyentuh MySQL..." -ForegroundColor Cyan
Push-Location $PROYEK
try {
    & 'C:\xampp\php\php.exe' artisan db:backup 2>&1 | Select-Object -Last 1 | ForEach-Object { Oke $_.Trim() }
} catch { Awas "Backup gagal - lanjut, tapi hati-hati." } finally { Pop-Location }

# --- 2. Lepas tugas lama supaya tidak bertabrakan ---------------------------
# Kalau tugas ini dibiarkan, VBS-nya akan menyalakan mysqld SENDIRI di samping
# service - persis tabrakan yang sedang kita berantas.
if (Get-ScheduledTask -TaskName $T_STACK -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $T_STACK -Confirm:$false
    Oke "Tugas '$T_STACK' dilepas (digantikan service)"
} else {
    Awas "Tugas '$T_STACK' tidak ada - tidak apa-apa"
}

# --- 3. Hentikan proses yang sedang jalan -----------------------------------
Write-Host "`nMenghentikan MySQL & Apache yang sedang jalan..." -ForegroundColor Cyan
Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
# MySQL dimatikan baik-baik lewat shutdown, bukan dibunuh, supaya InnoDB
# sempat menutup berkasnya dengan rapi.
& 'C:\xampp\mysql\bin\mysqladmin.exe' -u root shutdown 2>$null
Start-Sleep -Seconds 5
Get-Process mysqld -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Start-Sleep -Seconds 3
Oke "Semua proses berhenti"

# --- 4. Pasang service ------------------------------------------------------
Write-Host "`nMemasang service..." -ForegroundColor Cyan

if (Get-Service $SVC_SQL -ErrorAction SilentlyContinue) {
    Awas "Service '$SVC_SQL' sudah ada - dilewati"
} else {
    & $MYSQLD --install $SVC_SQL --defaults-file="$MYINI"
    if ($LASTEXITCODE -ne 0) { Mati "Gagal memasang service MySQL." }
    Oke "Service '$SVC_SQL' terpasang"
}

if (Get-Service $SVC_WEB -ErrorAction SilentlyContinue) {
    Awas "Service '$SVC_WEB' sudah ada - dilewati"
} else {
    & $HTTPD -k install -n $SVC_WEB | Out-Null
    if (-not (Get-Service $SVC_WEB -ErrorAction SilentlyContinue)) { Mati "Gagal memasang service Apache." }
    Oke "Service '$SVC_WEB' terpasang"
}

foreach ($s in $SVC_SQL, $SVC_WEB) { Set-Service $s -StartupType Automatic }
Oke "Keduanya diatur menyala otomatis saat Windows hidup"

# --- 5. Nyalakan ------------------------------------------------------------
Write-Host "`nMenyalakan..." -ForegroundColor Cyan
Start-Service $SVC_SQL; Start-Sleep -Seconds 6
Start-Service $SVC_WEB; Start-Sleep -Seconds 4

# --- 6. Buktikan ------------------------------------------------------------
Write-Host "`nVerifikasi:" -ForegroundColor Cyan
LaporKondisi

$webOk = $false
try { $webOk = (Invoke-WebRequest 'http://localhost:8080/' -UseBasicParsing -TimeoutSec 20).StatusCode -eq 200 } catch {}
if ($webOk) { Oke "Aplikasi menjawab di http://localhost:8080" } else { Awas "Aplikasi BELUM menjawab - cek storage\logs\apache-error.log" }

Push-Location $PROYEK
try {
    $t = & 'C:\xampp\php\php.exe' artisan tinker --execute="echo App\Models\Transaction::count();" 2>&1 | Select-Object -Last 1
    if ($t -match '^\d+$') { Oke "Laravel tersambung ke MySQL ($t transaksi terbaca)" }
    else { Awas "Laravel belum bisa baca MySQL - periksa .env" }
} catch { Awas "Uji koneksi Laravel gagal" } finally { Pop-Location }

Write-Host "`nSelesai." -ForegroundColor Green
Write-Host "Buka XAMPP Control Panel - sekarang Apache & MySQL akan tampil HIJAU" -ForegroundColor Gray
Write-Host "dengan tanda centang di kolom Service. Tombol Start tidak lagi" -ForegroundColor Gray
Write-Host "bisa membuat instance kedua." -ForegroundColor Gray
Write-Host "`nPeriksa kapan saja:  ... \pasang-service-xampp.ps1 -Periksa" -ForegroundColor Gray
Write-Host ""
