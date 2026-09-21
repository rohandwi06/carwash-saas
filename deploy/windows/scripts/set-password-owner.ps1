<#
    OTIN CARWASH - mengganti password akun OWNER di .env.

    Jalankan (tidak perlu Administrator):
        powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\otin-carwash\deploy\windows\scripts\set-password-owner.ps1

    Bawaannya membuatkan password acak yang kuat lalu menampilkannya SEKALI
    di layar ini. Kalau Anda lebih suka memilih sendiri:

        ... \set-password-owner.ps1 -Ketik

    Kenapa harus panjang: begitu aplikasi terbuka di internet, password owner
    adalah satu-satunya penjaga seluruh pembukuan. Empat digit angka habis
    dicoba semua kemungkinannya dalam hitungan menit.

    Catatan: mengganti ini TIDAK mempengaruhi akun kasir (tersimpan di tabel
    users, di-hash). Hanya akun owner yang dibaca dari .env.
#>

param([switch]$Ketik, [int]$Panjang = 20)

$ErrorActionPreference = 'Stop'

$PROYEK = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
$ENVFILE = Join-Path $PROYEK '.env'

function Oke($t)  { Write-Host "  OK   $t" -ForegroundColor Green }
function Mati($t) { Write-Host "  X    $t" -ForegroundColor Red; exit 1 }

Write-Host "`n=== Ganti password owner ===" -ForegroundColor Cyan

if (-not (Test-Path $ENVFILE)) { Mati "Tidak ada .env di $PROYEK" }

if ($Ketik) {
    $s1 = Read-Host "Password baru (minimal 12 karakter)" -AsSecureString
    $s2 = Read-Host "Ulangi sekali lagi" -AsSecureString
    $p1 = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
            [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s1))
    $p2 = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
            [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s2))
    if ($p1 -ne $p2)        { Mati "Dua isian tidak sama." }
    if ($p1.Length -lt 12)  { Mati "Terlalu pendek ($($p1.Length) karakter). Minimal 12." }
    # Tanda '#' memulai komentar di .env, dan spasi memotong nilai.
    if ($p1 -match '[#\s]') { Mati "Jangan pakai spasi atau tanda pagar (#) - keduanya merusak pembacaan .env." }
    $baru = $p1
} else {
    # Huruf+angka saja: aman ditaruh di .env tanpa tanda kutip, dan tidak ada
    # karakter yang gampang salah baca saat diketik ulang di tablet.
    $huruf = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'
    $bytes = [byte[]]::new($Panjang)
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $baru = -join ($bytes | ForEach-Object { $huruf[$_ % $huruf.Length] })
}

# Cadangkan dulu, lalu ganti barisnya.
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$bak = Join-Path $PROYEK "storage\backups\env-$stamp.bak"
New-Item -ItemType Directory -Force -Path (Split-Path $bak) | Out-Null
Copy-Item $ENVFILE $bak -Force

$isi = [System.IO.File]::ReadAllText($ENVFILE)
if ($isi -match '(?m)^OWNER_PASSWORD=') {
    $isi = $isi -replace '(?m)^OWNER_PASSWORD=.*$', "OWNER_PASSWORD=$baru"
} else {
    $isi = $isi.TrimEnd() + "`r`nOWNER_PASSWORD=$baru`r`n"
}
[System.IO.File]::WriteAllText($ENVFILE, $isi)

Push-Location $PROYEK
try { & 'C:\xampp\php\php.exe' artisan config:clear 2>&1 | Out-Null } finally { Pop-Location }

Oke "Password owner diganti ($($baru.Length) karakter)"
Oke "Cadangan .env lama: $bak"

Write-Host "`n=============== PASSWORD OWNER BARU ===============" -ForegroundColor Yellow
Write-Host "  username : " -NoNewline; Write-Host (
    (Select-String -Path $ENVFILE -Pattern '^OWNER_USERNAME=(.*)$').Matches[0].Groups[1].Value)
Write-Host "  password : " -NoNewline; Write-Host $baru -ForegroundColor White
Write-Host "===================================================" -ForegroundColor Yellow
Write-Host "Simpan SEKARANG ke password manager / catatan aman." -ForegroundColor Yellow
Write-Host "Setelah jendela ini ditutup, password tidak bisa dilihat lagi" -ForegroundColor Yellow
Write-Host "kecuali dengan membuka berkas .env langsung.`n" -ForegroundColor Yellow
Write-Host "Semua sesi login yang sedang berjalan tetap sah sampai kedaluwarsa;" -ForegroundColor Gray
Write-Host "password baru dipakai pada login berikutnya." -ForegroundColor Gray
