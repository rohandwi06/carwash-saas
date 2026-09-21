<#
    OTIN CARWASH - menyiapkan paket UPDATE untuk situs cPanel yang SUDAH jalan.

    Bedanya dengan buat-paket.ps1 (deploy pertama): skrip ini TIDAK membuat
    dump database. Situs produksi sudah berisi pembukuan yang tidak ada di
    laptop - mengimpor dump lokal ke sana akan MENGHAPUS semua transaksi yang
    tercatat sejak backup terakhir diambil. Yang dikirim cuma berkas yang
    benar-benar berubah, plus satu berkas SQL berisi migrasi baru saja.

    Jalankan dari folder proyek:
        powershell -ExecutionPolicy Bypass -File deploy\cpanel\buat-update.ps1

    Kalau perubahannya sudah terlanjur di-commit, sebutkan versi produksinya:
        ... -File deploy\cpanel\buat-update.ps1 -Sejak HEAD~1

    Hasilnya di  deploy\cpanel\paket-update\  :

      1. update-app.zip     -> extract di  ~/otin-carwash
      2. update-public.zip  -> extract di  ~/public_html
      3. update-migrasi.sql -> HANYA kalau ada migrasi baru, dan HANYA dipakai
                               bila hosting tidak punya Terminal/SSH
#>

param(
    [string]$Sejak = 'HEAD'    # versi yang sedang jalan di produksi
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression

# Zip ditulis entri per entri dengan garis miring biasa - alasan lengkapnya
# ada di buat-paket.ps1: Compress-Archive menulis backslash dan bikin
# autoloader mati di server Linux.
function Buat-ZipDaftar {
    param([string]$Akar, [string[]]$Berkas, [string]$Tujuan)

    if (Test-Path $Tujuan) { Remove-Item $Tujuan -Force }
    $zip = [IO.Compression.ZipFile]::Open($Tujuan, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($rel in $Berkas) {
            $penuh = Join-Path $Akar $rel
            if (-not (Test-Path -LiteralPath $penuh)) { continue }
            $entri = $zip.CreateEntry($rel.Replace([char]92, '/'), [IO.Compression.CompressionLevel]::Optimal)
            $masuk = $entri.Open()
            try {
                $baca = [IO.File]::OpenRead($penuh)
                try { $baca.CopyTo($masuk) } finally { $baca.Dispose() }
            } finally { $masuk.Dispose() }
        }
    } finally { $zip.Dispose() }

    $zip = [IO.Compression.ZipFile]::OpenRead($Tujuan)
    try { $rusak = @($zip.Entries | Where-Object { $_.FullName.Contains([char]92) }).Count }
    finally { $zip.Dispose() }
    if ($rusak -gt 0) { throw "$Tujuan berisi $rusak entri berbackslash - jangan diunggah." }
}

$proyek = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$paket  = Join-Path $PSScriptRoot 'paket-update'
Push-Location $proyek

Write-Host "=== Paket UPDATE OTIN CARWASH ===" -ForegroundColor Cyan
Write-Host "Membandingkan folder kerja dengan: $Sejak"

# --- Berkas apa saja yang berubah ------------------------------------------
# Yang dihapus (status D) sengaja tidak ikut: menghapus berkas di server
# harus disadari betul, jadi dilaporkan saja supaya dikerjakan manual.
$ubah  = @(git diff --name-only --diff-filter=ACMR $Sejak) |
         Where-Object { $_ }
$baru  = @(git ls-files --others --exclude-standard) | Where-Object { $_ }
$hapus = @(git diff --name-only --diff-filter=D $Sejak) | Where-Object { $_ }

$semua = @($ubah + $baru | Sort-Object -Unique)
if ($semua.Count -eq 0) { Write-Host "Tidak ada perubahan. Berhenti." -ForegroundColor Yellow; Pop-Location; return }

# .env & kawan-kawannya tidak pernah ikut dikirim.
# deploy/ tidak ikut: isinya skrip & panduan untuk laptop, tidak ada yang
# dijalankan server. .env dan log jelas tidak pernah dikirim.
#
# android-app/ juga tidak: itu proyek Flutter yang berdiri sendiri, tidak ada
# satu berkas pun yang dijalankan hosting PHP. Perlu disebut di sini karena
# $baru di atas menyapu SEMUA berkas untracked -- selama folder itu belum
# di-commit, tanpa baris ini 64 berkas Dart/Gradle ikut terekstrak ke
# ~/otin-carwash pada setiap update.
# ops/ sama sekali tidak boleh: berisi daftar semua cucian, dan
# ops/secrets/ (untracked, jadi ikut tersapu $baru) berisi .env tiap cucian.
#
# tests/ juga bukan urusan server: tidak pernah dijalankan di produksi.
#
# docs/ juga tidak -- dan yang ini bukan cuma soal tidak perlu. Isinya bahan
# jualan & strategi (MODEL-BISNIS, BUKTI-LAPANGAN berisi kutipan chat owner,
# AUDIT-MULTITENANT), sedangkan hosting ini akun milik KLIEN. Di luar
# public_html memang tidak bisa diunduh lewat web, tapi owner yang membuka
# File Manager tetap bisa membacanya.
$semua = $semua | Where-Object {
    $_ -notmatch '^\.env' -and $_ -notmatch '^storage/(logs|backups)/' -and $_ -notmatch '^deploy/' -and
    $_ -notmatch '^android-app/' -and $_ -notmatch '^ops/' -and $_ -notmatch '^tests/' -and $_ -notmatch '^docs/' -and
    $_ -notin @('.gitignore', '.gitattributes', '.editorconfig', '.phpunit.result.cache', 'phpunit.xml')
}

$sisiPublic = $semua | Where-Object { $_ -like 'public/*' }
$sisiApp    = $semua | Where-Object { $_ -notlike 'public/*' }

if (Test-Path $paket) { Remove-Item $paket -Recurse -Force }
New-Item -ItemType Directory -Path $paket -Force | Out-Null

if ($sisiApp) {
    Buat-ZipDaftar $proyek $sisiApp (Join-Path $paket 'update-app.zip')
}
if ($sisiPublic) {
    # public/index.php TIDAK boleh ikut: di server yang dipakai versi cPanel
    # (index-public_html.php) yang path-nya menunjuk ke ~/otin-carwash.
    $pub = $sisiPublic | Where-Object { $_ -ne 'public/index.php' }
    if ($sisiPublic -contains 'public/index.php') {
        Write-Host "[!] public/index.php berubah - JANGAN diunggah apa adanya." -ForegroundColor Yellow
        Write-Host "    Samakan perubahannya ke deploy\cpanel\index-public_html.php dulu." -ForegroundColor Yellow
    }
    if ($pub) {
        # Nama entri dipotong prefix "public/" supaya extract-nya pas di public_html.
        $tmp = Join-Path $env:TEMP ('otin-pub-' + [guid]::NewGuid().ToString('N').Substring(0,8))
        New-Item -ItemType Directory -Path $tmp -Force | Out-Null
        foreach ($f in $pub) {
            $rel = $f.Substring('public/'.Length)
            $tuj = Join-Path $tmp $rel
            New-Item -ItemType Directory -Path (Split-Path -Parent $tuj) -Force | Out-Null
            Copy-Item (Join-Path $proyek $f) $tuj -Force
        }
        $relPub = Get-ChildItem -LiteralPath $tmp -Recurse -File |
                  ForEach-Object { $_.FullName.Substring($tmp.Length + 1) }
        Buat-ZipDaftar $tmp $relPub (Join-Path $paket 'update-public.zip')
        Remove-Item $tmp -Recurse -Force
    }
}

# --- Migrasi baru -> SQL cadangan bila hosting tanpa Terminal ---------------
# vendor/ tidak dilacak git, jadi perubahan dependensi tidak pernah masuk
# paket update. Kalau composer.lock berubah, paket ini TIDAK cukup.
if ($semua -contains 'composer.lock') {
    Write-Host ""
    Write-Host "[!] composer.lock berubah - vendor/ tidak ikut paket update." -ForegroundColor Yellow
    Write-Host "    Pakai buat-paket.ps1 -TanpaDatabase dan unggah otin-app.zip penuh." -ForegroundColor Yellow
}

$migrasi = $semua | Where-Object { $_ -like 'database/migrations/*' }
if ($migrasi) {
    Write-Host ""
    Write-Host "Migrasi baru terdeteksi:" -ForegroundColor Yellow
    $migrasi | ForEach-Object { Write-Host "  - $(Split-Path $_ -Leaf)" }
    Write-Host "  Cara utama di server: php artisan migrate --force" -ForegroundColor Yellow
    Write-Host "  Tanpa Terminal: pakai update-migrasi.sql (tulis sendiri, lihat README)" -ForegroundColor Yellow
}

if ($hapus) {
    Write-Host ""
    Write-Host "Berkas yang DIHAPUS - hapus manual di File Manager:" -ForegroundColor Yellow
    $hapus | ForEach-Object { Write-Host "  - $_" }
}

Pop-Location
Write-Host ""
Write-Host "Selesai. Berkas ada di: $paket" -ForegroundColor Green
Get-ChildItem $paket | ForEach-Object { "{0,-24} {1,10:N1} KB" -f $_.Name, ($_.Length / 1KB) }
