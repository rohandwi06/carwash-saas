<#
    OTIN CARWASH - memasang nginx + php-cgi (FastCGI) sebagai Windows Service.

    Jalankan sebagai ADMINISTRATOR:
        powershell -ExecutionPolicy Bypass -File deploy\windows\scripts\pasang-service.ps1

    Kenapa service, bukan dobel-klik:
    aplikasi kasir harus hidup lagi sendiri setelah listrik mati / komputer
    restart. Kalau dijalankan lewat jendela CMD, sekali jendelanya tertutup
    kasir langsung tidak bisa transaksi.

    Yang dipasang:
      OtinPHP9000 / OtinPHP9001  -> php-cgi.exe (padanan php-fpm di Windows)
      OtinNginx                  -> nginx.exe
    cloudflared dipasang terpisah dengan perintahnya sendiri (lihat README).

    Parameter -Hapus untuk membatalkan semua pemasangan di atas.
#>

param([switch]$Hapus)

$ErrorActionPreference = 'Stop'

# --- Wajib administrator ----------------------------------------------------
$id = [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "Skrip ini harus dijalankan sebagai Administrator." -ForegroundColor Red
    Write-Host "Klik kanan PowerShell -> Run as administrator, lalu ulangi." -ForegroundColor Yellow
    exit 1
}

$NSSM    = 'C:\nssm\nssm.exe'
$PHPCGI  = 'C:\xampp\php\php-cgi.exe'
$NGINX   = 'C:\nginx\nginx.exe'
$SERVIS  = @('OtinPHP9000', 'OtinPHP9001', 'OtinNginx')

# --- Mode hapus -------------------------------------------------------------
if ($Hapus) {
    foreach ($s in $SERVIS) {
        if (Get-Service $s -ErrorAction SilentlyContinue) {
            & $NSSM stop   $s confirm | Out-Null
            & $NSSM remove $s confirm | Out-Null
            Write-Host "dihapus: $s" -ForegroundColor Yellow
        }
    }
    Write-Host "Selesai. Semua service OTIN dilepas." -ForegroundColor Green
    exit 0
}

# --- Periksa prasyarat ------------------------------------------------------
$kurang = @()
if (-not (Test-Path $NSSM))   { $kurang += "NSSM belum ada di $NSSM  (unduh: https://nssm.cc/download)" }
if (-not (Test-Path $PHPCGI)) { $kurang += "php-cgi.exe tidak ada di $PHPCGI" }
if (-not (Test-Path $NGINX))  { $kurang += "nginx belum ada di $NGINX  (unduh: https://nginx.org/en/download.html)" }

if ($kurang) {
    Write-Host "Prasyarat belum lengkap:" -ForegroundColor Red
    $kurang | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
    exit 1
}

# --- php-cgi: dua instance ---------------------------------------------------
# Satu proses php-cgi hanya melayani SATU permintaan pada satu waktu. Layar
# Dashboard menembak beberapa API sekaligus, jadi satu instance akan membuat
# permintaan mengantre. Dua instance sudah cukup untuk 2-3 tablet kasir.
foreach ($port in 9000, 9001) {
    $nama = "OtinPHP$port"
    if (Get-Service $nama -ErrorAction SilentlyContinue) {
        Write-Host "lewati (sudah ada): $nama" -ForegroundColor DarkGray
        continue
    }
    & $NSSM install $nama $PHPCGI "-b" "127.0.0.1:$port" | Out-Null
    & $NSSM set $nama DisplayName "OTIN CARWASH PHP FastCGI :$port" | Out-Null
    & $NSSM set $nama Description "php-cgi FastCGI untuk aplikasi kasir OTIN CARWASH" | Out-Null
    & $NSSM set $nama Start SERVICE_AUTO_START | Out-Null
    # Bawaan php-cgi keluar sendiri setelah 500 permintaan. 0 = jangan keluar;
    # kalau toh mati, NSSM menyalakannya lagi dalam 3 detik.
    & $NSSM set $nama AppEnvironmentExtra "PHP_FCGI_MAX_REQUESTS=0" | Out-Null
    & $NSSM set $nama AppExit Default Restart | Out-Null
    & $NSSM set $nama AppRestartDelay 3000 | Out-Null
    Write-Host "dipasang: $nama" -ForegroundColor Green
}

# --- nginx -------------------------------------------------------------------
if (Get-Service 'OtinNginx' -ErrorAction SilentlyContinue) {
    Write-Host "lewati (sudah ada): OtinNginx" -ForegroundColor DarkGray
} else {
    & $NSSM install OtinNginx $NGINX | Out-Null
    & $NSSM set OtinNginx AppDirectory (Split-Path $NGINX) | Out-Null
    & $NSSM set OtinNginx DisplayName "OTIN CARWASH nginx" | Out-Null
    & $NSSM set OtinNginx Start SERVICE_AUTO_START | Out-Null
    # nginx bercabang sendiri; beri tahu NSSM supaya tidak salah kira mati.
    & $NSSM set OtinNginx AppExit Default Restart | Out-Null
    & $NSSM set OtinNginx AppStopMethodConsole 1500 | Out-Null
    Write-Host "dipasang: OtinNginx" -ForegroundColor Green
}

# --- Nyalakan ----------------------------------------------------------------
foreach ($s in $SERVIS) { & $NSSM start $s | Out-Null }
Start-Sleep -Seconds 2

Write-Host ""
Get-Service $SERVIS | Format-Table Name, Status, StartType -AutoSize

Write-Host "Uji cepat:  curl.exe -I http://localhost:8080" -ForegroundColor Cyan
Write-Host "Log nginx:  C:\nginx\logs\otin-error.log" -ForegroundColor Cyan
