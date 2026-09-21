<#
    Menyiapkan paket PENUH yang diunggah ke satu akun cPanel.

    Jalankan dari folder proyek:
        powershell -ExecutionPolicy Bypass -File deploy\cpanel\buat-paket.ps1 -FolderApp rapiin-app

    Hasilnya 3 berkas di folder  deploy\cpanel\paket\  :

      1. app.zip        -> di-extract ke  ~/<FolderApp>     (di LUAR public_html)
      2. public.zip     -> di-extract ke  ~/public_html
      3. database.sql   -> diimpor lewat phpMyAdmin

    -FolderApp  nama folder aplikasi di server. Cucian baru: rapiin-app
                (sama dengan ops/config.json). OTIN: otin-carwash.
    -Database   baru  (bawaan) database KOSONG hasil migrate:fresh --seed,
                      untuk cucian baru: katalog awal saja, tanpa transaksi.
                lokal database di .env laptop ini apa adanya. Isinya
                      pembukuan OTIN - JANGAN untuk cucian lain.
                tidak tanpa berkas database (mis. hanya memperbarui kode).

    Kenapa vendor/ ikut dibungkus: shared hosting cPanel umumnya tidak punya
    composer, jadi dependensi harus dibawa dari sini. .env, storage/logs,
    storage/backups, dan .git SENGAJA tidak ikut - .env berisi password dan
    dibuat langsung di server.
#>

param(
    [Parameter(Mandatory)] [string]$FolderApp,
    [ValidateSet('baru', 'lokal', 'tidak')] [string]$Database = 'baru',
    [switch]$TanpaDatabase   # nama lama untuk -Database tidak
)
if ($TanpaDatabase) { $Database = 'tidak' }
if ($FolderApp -notmatch '^[A-Za-z0-9._-]+$') { throw "FolderApp '$FolderApp' tidak sah." }

$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression

<#
    JANGAN pakai Compress-Archive di sini.

    Compress-Archive bawaan Windows PowerShell 5.1 menulis pemisah folder
    memakai BACKSLASH ("vendor\symfony\..."). Standar ZIP mewajibkan garis
    miring biasa. Pembongkar di server Linux (cPanel) membaca backslash itu
    sebagai bagian dari NAMA berkas, bukan pemisah folder - hasilnya ada entri
    yang terlihat di daftar isi tapi tidak bisa dibuka, dan autoloader Composer
    langsung mati dengan "Failed opening required ...".

    Persis itu yang terjadi 30 Agustus 2026 saat deploy pertama ke ArenHost:
    aplikasi balas HTTP 500 dengan badan kosong, dan tidak ada satu pun log
    yang tertulis karena PHP mati sebelum Laravel sempat hidup.

    ZipFile::CreateFromDirectory (.NET) menulis garis miring yang benar.
#>
function Buat-Zip {
    param([string]$Sumber, [string]$Tujuan)

    if (Test-Path $Tujuan) { Remove-Item $Tujuan -Force }

    # CreateFromDirectory pun TIDAK bisa dipakai: di .NET Framework (Windows
    # PowerShell 5.1) ia ikut menulis backslash. Jadi entri dibuat satu per
    # satu, dengan nama yang kita normalkan sendiri jadi garis miring.
    $akar = (Resolve-Path $Sumber).Path.TrimEnd([char]92) + [char]92
    $zip  = [IO.Compression.ZipFile]::Open($Tujuan, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($berkas in Get-ChildItem -LiteralPath $Sumber -Recurse -File -Force) {
            $relatif = $berkas.FullName.Substring($akar.Length).Replace([char]92, '/')
            $entri   = $zip.CreateEntry($relatif, [IO.Compression.CompressionLevel]::Optimal)
            $masuk   = $entri.Open()
            try {
                $keluar = [IO.File]::OpenRead($berkas.FullName)
                try { $keluar.CopyTo($masuk) } finally { $keluar.Dispose() }
            } finally {
                $masuk.Dispose()
            }
        }
    } finally {
        $zip.Dispose()
    }

    # Sabuk pengaman: pastikan tidak ada satu pun entri berbackslash.
    $zip = [IO.Compression.ZipFile]::OpenRead($Tujuan)
    try {
        $rusak = @($zip.Entries | Where-Object { $_.FullName.Contains([char]92) }).Count
    } finally {
        $zip.Dispose()
    }
    if ($rusak -gt 0) { throw "$Tujuan berisi $rusak entri berbackslash - jangan diunggah." }
}

$proyek = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$paket  = Join-Path $PSScriptRoot 'paket'
$kerja  = Join-Path $env:TEMP ('carwash-paket-' + [guid]::NewGuid().ToString('N').Substring(0, 8))

Write-Host "=== Menyiapkan paket unggah -> ~/$FolderApp (database: $Database) ===" -ForegroundColor Cyan
Write-Host "Proyek: $proyek"

# HANYA berkas hasil bungkusan yang dibuang. Folder ini pernah dikosongkan
# seluruhnya, dan itu ikut menghapus catatan password owner yang disimpan
# pengguna di sini (30 Agustus 2026) - jangan diulangi.
foreach ($lama in 'app.zip', 'public.zip', 'database.sql', 'otin-app.zip', 'otin-public.zip', 'otin-database.sql') {
    $j = Join-Path $paket $lama
    if (Test-Path $j) { Remove-Item $j -Force }
}
New-Item -ItemType Directory -Path $paket, $kerja -Force | Out-Null

# --- 1. Salin proyek ke folder kerja, tanpa yang tidak boleh ikut ------------
# robocopy dipakai (bukan Copy-Item) karena ia punya /XD /XF dan tahan path
# panjang di dalam vendor/.
$kecualiFolder = @(
    (Join-Path $proyek '.git'),
    (Join-Path $proyek 'node_modules'),
    (Join-Path $proyek 'storage\logs'),
    (Join-Path $proyek 'storage\backups'),
    (Join-Path $proyek 'storage\framework\cache\data'),
    (Join-Path $proyek 'storage\framework\sessions'),
    (Join-Path $proyek 'storage\framework\views'),
    (Join-Path $proyek 'tests'),
    # ops\ berisi daftar SEMUA cucian dan ops\secrets\ berisi .env
    # (password database & owner) tiap cucian. Paket ini diunggah ke hosting
    # milik satu klien - kalau ikut, klien itu bisa membaca rahasia klien lain
    # dari File Manager. docs\ (bahan jualan), deploy\ (skrip laptop), dan
    # android-app\ dikecualikan dengan alasan yang sama seperti di buat-update.ps1.
    (Join-Path $proyek 'ops'),
    (Join-Path $proyek 'docs'),
    (Join-Path $proyek 'deploy'),
    (Join-Path $proyek 'android-app'),
    (Join-Path $proyek 'public')          # dibungkus terpisah ke public_html
)
$kecualiBerkas = @('.env', '*.sqlite')

$aplikasi = Join-Path $kerja 'app-root'
robocopy $proyek $aplikasi /E /XD $kecualiFolder /XF $kecualiBerkas /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy gagal (kode $LASTEXITCODE)." }

# Folder storage yang tadi dikecualikan tetap harus ADA (kosong) di server,
# kalau tidak Laravel gagal menulis cache/sesi/view dan langsung error 500.
foreach ($d in 'logs', 'backups', 'framework\cache\data', 'framework\sessions', 'framework\views') {
    $t = Join-Path $aplikasi "storage\$d"
    New-Item -ItemType Directory -Path $t -Force | Out-Null
    # Berkas penanda supaya folder kosong tetap ikut masuk ke dalam zip.
    Set-Content -Path (Join-Path $t '.keep') -Value '' -Encoding utf8
}

Buat-Zip $aplikasi (Join-Path $paket 'app.zip')

# --- 2. Isi public_html -----------------------------------------------------
$pub = Join-Path $kerja 'public-root'
robocopy (Join-Path $proyek 'public') $pub /E /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy public/ gagal (kode $LASTEXITCODE)." }

# index.php diganti versi cPanel, dengan $app_base menunjuk ke ~/<FolderApp>.
$index = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'index-public_html.php'))
$baris = '$app_base = __DIR__.''/../' + $FolderApp + ''';'
$indexBaru = [regex]::Replace($index, '(?m)^\$app_base = .*;', $baris.Replace('$', '$$'))
if (-not $indexBaru.Contains($baris)) { throw 'Baris $app_base di index-public_html.php tidak ditemukan.' }
[IO.File]::WriteAllText((Join-Path $pub 'index.php'), $indexBaru, (New-Object Text.UTF8Encoding($false)))

Buat-Zip $pub (Join-Path $paket 'public.zip')

# --- 3. Database ------------------------------------------------------------
# Kredensial MySQL diambil dari .env laptop ini, bukan ditulis di skrip:
# root XAMPP di laptop ini berpassword.
function Baca-Env {
    $h = @{}
    foreach ($b in Get-Content (Join-Path $proyek '.env')) {
        if ($b -match '^\s*([A-Z_]+)\s*=\s*(.*)$') { $h[$Matches[1]] = $Matches[2].Trim().Trim('"') }
    }
    $h
}

# mysql/mysqldump dicari di samping PHP yang dipakai (XAMPP: <xampp>\php\php.exe
# dan <xampp>\mysql\bin\), baru ke PATH - sama seperti BackupDatabase.php.
function Cari-MySql([string]$Nama) {
    $php = (Get-Command php -ErrorAction SilentlyContinue).Source
    if ($php) {
        $calon = Join-Path (Split-Path -Parent (Split-Path -Parent $php)) "mysql\bin\$Nama.exe"
        if (Test-Path $calon) { return $calon }
    }
    $c = Get-Command $Nama -ErrorAction SilentlyContinue
    if ($c) { return $c.Source }
    throw "$Nama tidak ditemukan. Nyalakan XAMPP atau tambahkan mysql\bin ke PATH."
}

if ($Database -ne 'tidak') {
    $envLokal = Baca-Env
    $mysql    = Cari-MySql 'mysql'
    $dump     = Cari-MySql 'mysqldump'
    $sql      = Join-Path $paket 'database.sql'
    $koneksi  = @("--host=$($envLokal.DB_HOST)", "--port=$($envLokal.DB_PORT)", "--user=$($envLokal.DB_USERNAME)")
    $namaDb   = $null
    # Password lewat variabel lingkungan supaya tidak terlihat di daftar proses.
    $env:MYSQL_PWD = $envLokal.DB_PASSWORD

    try {
        if ($Database -eq 'lokal') {
            $namaDb = $envLokal.DB_DATABASE
            Write-Host "[!] Mendump database LOKAL '$namaDb' - isinya pembukuan nyata." -ForegroundColor Yellow
        } else {
            # Database sementara: dibuat, diisi migrasi + seeder, di-dump, lalu dibuang.
            $namaDb = 'carwash_cetakan_' + [guid]::NewGuid().ToString('N').Substring(0, 8)
            & $mysql @koneksi -e "CREATE DATABASE $namaDb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            if ($LASTEXITCODE -ne 0) { throw "Gagal membuat database sementara $namaDb." }

            # Variabel lingkungan proses menang atas isi .env (dotenv Laravel
            # tidak menimpa nilai yang sudah ada), jadi artisan menulis ke
            # database sementara, bukan ke database kerja di .env.
            $env:DB_DATABASE = $namaDb
            Push-Location $proyek
            try {
                & php artisan migrate:fresh --seed --force
                if ($LASTEXITCODE -ne 0) { throw "migrate:fresh --seed gagal." }
            } finally {
                Pop-Location
                Remove-Item Env:DB_DATABASE
            }
        }

        # --no-tablespaces: user MySQL shared hosting tidak punya hak PROCESS,
        # tanpa flag ini dump berisi perintah yang ditolak saat diimpor.
        & $dump @koneksi --single-transaction --routines --no-tablespaces "--result-file=$sql" $namaDb
        if ($LASTEXITCODE -ne 0) { throw "mysqldump gagal." }
    } finally {
        if ($Database -eq 'baru' -and $namaDb) {
            & $mysql @koneksi -e "DROP DATABASE IF EXISTS $namaDb"
        }
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    }
}

Remove-Item $kerja -Recurse -Force

Write-Host ""
Write-Host "Selesai. Berkas ada di: $paket" -ForegroundColor Green
Get-ChildItem $paket | ForEach-Object {
    "{0,-24} {1,8:N1} MB" -f $_.Name, ($_.Length / 1MB)
}
Write-Host ""
Write-Host "Langkah berikutnya: baca deploy\cpanel\README.md" -ForegroundColor Cyan
