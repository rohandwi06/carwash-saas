<#
    OTIN CARWASH - memasang start-tunnel.bat supaya jalan saat laptop menyala.

    Jalankan (Administrator TIDAK wajib):
        powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\otin-carwash\deploy\windows\scripts\pasang-startup-bat.ps1

    Cara kerjanya: membuat shortcut ke start-tunnel.bat di folder Startup
    milik Anda. Shortcut jalan saat LOGON (bukan saat boot), jadi lebih
    lambat sedikit dari tugas terjadwal - tapi jauh lebih gampang dilihat,
    diubah, dan dilepas sendiri tanpa perlu hak Administrator.

    PENTING - hanya boleh ada SATU pemicu.
    Kalau tugas terjadwal "OtinCarwash Stack" / "OtinCarwash Tunnel" masih
    terpasang, keduanya akan menyalakan hal yang sama. start-tunnel.bat
    memang punya shield sehingga tidak akan membuat MySQL dobel, tapi lebih
    bersih kalau yang lama dilepas. Skrip ini memberi tahu bila masih ada.

    Parameter:
      -Hapus     lepas shortcut-nya
      -Periksa   tampilkan kondisi sekarang
#>

param([switch]$Hapus, [switch]$Periksa)

$ErrorActionPreference = 'Stop'

$PROYEK  = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
$BAT     = Join-Path $PROYEK 'start-tunnel.bat'
$STARTUP = [Environment]::GetFolderPath('Startup')
$LNK     = Join-Path $STARTUP 'OTIN CARWASH - Server.lnk'

function Oke($t)  { Write-Host "  OK   $t" -ForegroundColor Green }
function Awas($t) { Write-Host "  !!   $t" -ForegroundColor Yellow }
function Mati($t) { Write-Host "  X    $t" -ForegroundColor Red; exit 1 }

Write-Host "`n=== Startup start-tunnel.bat ===" -ForegroundColor Cyan

function LaporKondisi {
    if (Test-Path $LNK) { Oke "Shortcut terpasang: $LNK" }
    else                { Awas "Shortcut belum terpasang" }

    # Pemicu lain yang bisa bentrok.
    $adaTugas = $false
    foreach ($t in 'OtinCarwash Stack', 'OtinCarwash Tunnel') {
        if (Get-ScheduledTask -TaskName $t -ErrorAction SilentlyContinue) {
            Awas "Tugas terjadwal '$t' MASIH ADA - pemicu ganda"
            $adaTugas = $true
        }
    }
    if (-not $adaTugas) { Oke "Tidak ada tugas terjadwal yang bentrok (atau tidak terbaca tanpa admin)" }

    $lama = Join-Path $STARTUP 'OTIN-CARWASH.lnk'
    if (Test-Path $lama) { Awas "Shortcut LAMA masih ada: $lama - sebaiknya dilepas" }
}

if ($Periksa) { LaporKondisi; Write-Host ""; exit 0 }

if ($Hapus) {
    if (Test-Path $LNK) { Remove-Item $LNK -Force; Oke "Shortcut dilepas" }
    else { Awas "Shortcut tidak ada" }
    exit 0
}

if (-not (Test-Path $BAT)) { Mati "Tidak ditemukan: $BAT" }

# --- Buat shortcut ----------------------------------------------------------
$sh = New-Object -ComObject WScript.Shell
$s  = $sh.CreateShortcut($LNK)
$s.TargetPath       = $BAT
$s.WorkingDirectory = $PROYEK
$s.WindowStyle      = 7          # 7 = minimized, tidak mengganggu saat logon
$s.Description      = 'Menyalakan MySQL, Apache, dan Cloudflare Tunnel OTIN CARWASH'
$s.IconLocation     = 'C:\Windows\System32\shell32.dll,13'
$s.Save()
Oke "Shortcut dibuat: $LNK"

# --- Peringatkan bila pemicu lama masih ada ---------------------------------
# Tanpa Administrator, Get-ScheduledTask tidak melihat tugas milik SYSTEM dan
# mengembalikan kosong. Diam saja di situ berarti melapor "tidak ada bentrok"
# padahal belum tentu - laporan palsu yang justru menyesatkan.
Write-Host ""
if (AdalahAdmin) {
    $bentrok = @()
    foreach ($t in 'OtinCarwash Stack', 'OtinCarwash Tunnel') {
        if (Get-ScheduledTask -TaskName $t -ErrorAction SilentlyContinue) { $bentrok += $t }
    }
    if ($bentrok) {
        Awas "Tugas terjadwal berikut masih terpasang dan menyalakan hal yang sama:"
        $bentrok | ForEach-Object { Write-Host "         - $_" -ForegroundColor Yellow }
        Write-Host "       Lepas dengan:" -ForegroundColor Gray
        Write-Host "         powershell -File deploy\windows\scripts\pasang-startup.ps1 -Hapus" -ForegroundColor Gray
    } else {
        Oke "Tidak ada tugas terjadwal yang bentrok"
    }
} else {
    Awas "Belum bisa memastikan tugas terjadwal lama sudah dilepas atau belum"
    Write-Host "       (daftar tugas tidak terbaca tanpa Administrator)." -ForegroundColor Gray
    Write-Host "       Kalau 'OtinCarwash Stack' / 'OtinCarwash Tunnel' masih ada, lepas" -ForegroundColor Gray
    Write-Host "       supaya pemicunya cuma satu. Lewat PowerShell Administrator:" -ForegroundColor Gray
    Write-Host "         powershell -File deploy\windows\scripts\pasang-startup.ps1 -Hapus" -ForegroundColor Gray
    Write-Host "       start-tunnel.bat sendiri sudah aman - shield-nya mencegah dobel." -ForegroundColor Gray
}

# --- Uji sekarang -----------------------------------------------------------
Write-Host "`nMenguji start-tunnel.bat sekarang..." -ForegroundColor Cyan
$sebelumMysqld = @(Get-Process mysqld -ErrorAction SilentlyContinue).Count
& cmd /c "`"$BAT`"" | ForEach-Object { Write-Host "  | $_" -ForegroundColor DarkGray }
$sesudahMysqld = @(Get-Process mysqld -ErrorAction SilentlyContinue).Count

Write-Host ""
if ($sesudahMysqld -le [math]::Max(1, $sebelumMysqld)) {
    Oke "Shield bekerja: mysqld tetap $sesudahMysqld proses (tidak dobel)"
} else {
    Awas "mysqld jadi $sesudahMysqld proses - periksa lagi!"
}

$f = Join-Path $PROYEK 'storage\logs\tunnel-url.txt'
if (Test-Path $f) {
    $m = Select-String -Path $f -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -ErrorAction SilentlyContinue
    if ($m) { Oke "URL tunnel: $($m.Matches[0].Value)" }
}

Write-Host "`nMulai sekarang, setiap kali Anda login Windows, server + tunnel" -ForegroundColor Gray
Write-Host "menyala sendiri. Alamat internetnya ditulis ke:" -ForegroundColor Gray
Write-Host "  $f" -ForegroundColor Gray
Write-Host "`nLepas lagi:  ... \pasang-startup-bat.ps1 -Hapus" -ForegroundColor Gray
Write-Host ""
