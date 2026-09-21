<#
    OTIN CARWASH - menyalakan aplikasi + tunnel otomatis saat laptop dihidupkan.

    Jalankan sebagai ADMINISTRATOR:
        powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\otin-carwash\deploy\windows\scripts\pasang-startup.ps1

    Yang dipasang - dua tugas Task Scheduler:

      OtinCarwash Stack    saat Windows menyala : MySQL + Apache
      OtinCarwash Tunnel   2 menit sesudahnya   : jalankan-tunnel.ps1

    Jeda 2 menit itu perlu. Tunnel yang menyala sebelum Apache siap akan
    langsung gagal dan tidak mencoba lagi.

    Parameter:
      -Abaikan   tunnel dijalankan dengan gerbang keamanan dimatikan.
                 Dipakai kalau preflight masih merah tapi Anda tetap mau
                 aplikasinya online. BACA peringatan di bawah.
      -Hapus     lepas kedua tugas
      -Periksa   tampilkan kondisi sekarang

    PERINGATAN soal -Abaikan pada startup:
    -Abaikan dirancang untuk uji coba sebentar, bukan dipasang permanen.
    Memakainya di startup berarti aplikasi terbuka ke internet setiap kali
    laptop menyala, tanpa satu pun pemeriksaan. Selama OWNER_PASSWORD masih
    pendek, password itulah satu-satunya yang menjaga seluruh pembukuan toko.
    Perbaiki dulu kalau bisa - lihat set-password-owner.ps1, cuma 30 detik.
#>

param([switch]$Abaikan, [switch]$Hapus, [switch]$Periksa)

$ErrorActionPreference = 'Stop'

$PROYEK   = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
$VBS      = Join-Path $PROYEK 'deploy\start-stack.vbs'
$TUNNEL   = Join-Path $PSScriptRoot 'jalankan-tunnel.ps1'
$T_STACK  = 'OtinCarwash Stack'
$T_TUNNEL = 'OtinCarwash Tunnel'

function Oke($t)  { Write-Host "  OK   $t" -ForegroundColor Green }
function Awas($t) { Write-Host "  !!   $t" -ForegroundColor Yellow }
function Mati($t) { Write-Host "  X    $t" -ForegroundColor Red; exit 1 }

function AdalahAdmin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
}

Write-Host "`n=== Startup otomatis OTIN CARWASH ===" -ForegroundColor Cyan

# --- Periksa ----------------------------------------------------------------
if ($Periksa) {
    foreach ($n in $T_STACK, $T_TUNNEL) {
        $t = Get-ScheduledTask -TaskName $n -ErrorAction SilentlyContinue
        if ($t) {
            $i = Get-ScheduledTaskInfo -TaskName $n
            Oke "$n - $($t.State), terakhir $($i.LastRunTime) (hasil $($i.LastTaskResult))"
        } elseif (-not (AdalahAdmin)) {
            Awas "$n - tidak terbaca tanpa Administrator"
        } else {
            Awas "$n - BELUM terpasang"
        }
    }
    $f = Join-Path $PROYEK 'storage\logs\tunnel-url.txt'
    if (Test-Path $f) { Write-Host "`nURL terakhir:" -ForegroundColor Cyan; Get-Content $f | ForEach-Object { "  $_" } }
    Write-Host ""
    exit 0
}

if (-not (AdalahAdmin)) { Mati "Harus dijalankan sebagai Administrator." }

# --- Hapus ------------------------------------------------------------------
if ($Hapus) {
    foreach ($n in $T_STACK, $T_TUNNEL) {
        if (Get-ScheduledTask -TaskName $n -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskName $n -Confirm:$false
            Oke "$n dilepas"
        } else { Awas "$n tidak ada" }
    }
    exit 0
}

if (-not (Test-Path $VBS))    { Mati "Tidak ditemukan: $VBS" }
if (-not (Test-Path $TUNNEL)) { Mati "Tidak ditemukan: $TUNNEL" }

if ($Abaikan) {
    Write-Host ""
    Awas "Tunnel akan dipasang TANPA gerbang keamanan."
    Awas "Aplikasi terbuka ke internet setiap laptop menyala."
    Awas "Pastikan OWNER_PASSWORD sudah panjang - itu satu-satunya penjaga."
    Write-Host ""
}

# --- 1. MySQL + Apache saat Windows menyala ---------------------------------
# Berjalan sebagai SYSTEM: tidak menunggu ada orang login ke Windows.
$sbgSystem = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$setelan   = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
             -StartWhenAvailable -MultipleInstances IgnoreNew

if (Get-ScheduledTask -TaskName $T_STACK -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $T_STACK -Confirm:$false
}
Register-ScheduledTask -TaskName $T_STACK `
    -Action (New-ScheduledTaskAction -Execute 'wscript.exe' -Argument "`"$VBS`"" -WorkingDirectory $PROYEK) `
    -Trigger (New-ScheduledTaskTrigger -AtStartup) `
    -Principal $sbgSystem -Settings $setelan `
    -Description 'Menyalakan MySQL + Apache OTIN CARWASH saat Windows menyala.' | Out-Null
Oke "'$T_STACK' terpasang (saat Windows menyala)"

# --- 2. Tunnel, 2 menit sesudahnya ------------------------------------------
$argTunnel = "-NoProfile -ExecutionPolicy Bypass -File `"$TUNNEL`"" +
             $(if ($Abaikan) { " -Abaikan -TanpaBatas" } else { "" })

$pemicu = New-ScheduledTaskTrigger -AtStartup
$pemicu.Delay = 'PT2M'   # beri waktu Apache & MySQL benar-benar siap

if (Get-ScheduledTask -TaskName $T_TUNNEL -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $T_TUNNEL -Confirm:$false
}
Register-ScheduledTask -TaskName $T_TUNNEL `
    -Action (New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $argTunnel -WorkingDirectory $PROYEK) `
    -Trigger $pemicu -Principal $sbgSystem -Settings $setelan `
    -Description 'Menyalakan Cloudflare Tunnel OTIN CARWASH, 2 menit setelah Windows menyala.' | Out-Null
Oke "'$T_TUNNEL' terpasang (2 menit setelah menyala$(if($Abaikan){', tanpa gerbang keamanan'}))"

# --- Uji sekarang -----------------------------------------------------------
Write-Host "`nMenguji sekarang tanpa perlu restart..." -ForegroundColor Cyan
Start-ScheduledTask -TaskName $T_STACK
Start-Sleep -Seconds 8
$apacheOk = $false
try {
    $apacheOk = (Invoke-WebRequest 'http://localhost:8080/' -UseBasicParsing -TimeoutSec 15).StatusCode -eq 200
} catch {}
if ($apacheOk) { Oke "Apache melayani di port 8080" } else { Awas "Apache belum menjawab - periksa XAMPP" }

Start-ScheduledTask -TaskName $T_TUNNEL
Write-Host "  menunggu tunnel..." -ForegroundColor Gray
$berkasUrl = Join-Path $PROYEK 'storage\logs\tunnel-url.txt'
$sebelum = if (Test-Path $berkasUrl) { (Get-Item $berkasUrl).LastWriteTime } else { [datetime]::MinValue }
$url = $null
for ($i = 0; $i -lt 60; $i++) {
    Start-Sleep -Seconds 1
    if ((Test-Path $berkasUrl) -and (Get-Item $berkasUrl).LastWriteTime -gt $sebelum) {
        $m = Select-String -Path $berkasUrl -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com'
        if ($m) { $url = $m.Matches[0].Value; break }
    }
}

Write-Host ""
if ($url) {
    Write-Host "==================================================" -ForegroundColor Green
    Write-Host "  $url" -ForegroundColor Green
    Write-Host "==================================================" -ForegroundColor Green
    Oke "Startup otomatis TERBUKTI jalan."
} else {
    Awas "Tunnel belum memberi URL. Periksa dengan -Periksa, atau jalankan"
    Awas "jalankan-tunnel.ps1 manual untuk melihat pesan galatnya."
}

Write-Host "`nSetiap laptop dinyalakan, URL BARU akan ditulis ke:" -ForegroundColor Cyan
Write-Host "  $berkasUrl" -ForegroundColor Gray
Write-Host "`nLepas lagi dengan:  ... \pasang-startup.ps1 -Hapus" -ForegroundColor Gray
Write-Host ""
