# =====================================================================
#  OTIN CARWASH — penyiapan proyek Android (jalankan SEKALI)
#
#  Kode Dart-nya sudah lengkap di lib/, tapi folder android/ (Gradle,
#  manifest, ikon) belum ada karena itu bagian yang HARUS dibuat oleh
#  Flutter SDK sesuai versinya sendiri — kalau ditulis tangan, ia akan
#  usang tiap kali Flutter naik versi dan build-nya gagal dengan error
#  Gradle yang sulit dibaca.
#
#  Skrip ini:
#    1. memeriksa Flutter sudah terpasang
#    2. mengamankan lib/ + pubspec.yaml
#    3. menjalankan `flutter create` untuk membuat folder android/
#    4. mengembalikan lib/ + pubspec.yaml (flutter create menimpanya)
#    5. menambahkan izin internet & Bluetooth ke AndroidManifest.xml
#    6. mengambil semua paket
#
#  Aman dijalankan ulang: langkah yang sudah beres akan dilewati.
# =====================================================================

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

Write-Host ''
Write-Host '=== OTIN CARWASH — penyiapan Android ===' -ForegroundColor Yellow
Write-Host ''

# --- 1. Flutter ada? -------------------------------------------------
if (-not (Get-Command flutter -ErrorAction SilentlyContinue)) {
    Write-Host 'Flutter belum terpasang atau belum masuk PATH.' -ForegroundColor Red
    Write-Host ''
    Write-Host 'Pasang dulu:'
    Write-Host '  winget install --id Google.AndroidStudio -e'
    Write-Host '  winget install --id Flutter.Flutter -e'
    Write-Host ''
    Write-Host 'Setelah itu TUTUP dan BUKA LAGI PowerShell, lalu jalankan skrip ini lagi.'
    exit 1
}

Write-Host '[1/6] Flutter ditemukan:' -ForegroundColor Green
flutter --version | Select-Object -First 1

# --- 2 & 3 & 4. Buat folder android/ ---------------------------------
if (Test-Path 'android') {
    Write-Host '[2/6] Folder android/ sudah ada — dilewati.' -ForegroundColor Green
} else {
    Write-Host '[2/6] Mengamankan lib/ dan pubspec.yaml...' -ForegroundColor Cyan

    # flutter create MENIMPA lib/main.dart dan pubspec.yaml kalau proyeknya
    # belum pernah dibuat olehnya. Disalin dulu, dikembalikan setelahnya.
    $aman = Join-Path $env:TEMP "otin-backup-$(Get-Date -Format 'yyyyMMddHHmmss')"
    New-Item -ItemType Directory -Force -Path $aman | Out-Null
    Copy-Item -Recurse -Force 'lib' $aman
    Copy-Item -Force 'pubspec.yaml' $aman
    if (Test-Path 'analysis_options.yaml') {
        Copy-Item -Force 'analysis_options.yaml' $aman
    }

    Write-Host '[3/6] Membuat kerangka Android (Gradle, manifest, ikon)...' -ForegroundColor Cyan
    flutter create --platforms=android --project-name otin_carwash --org com.otincarwash .

    Write-Host '[4/6] Mengembalikan kode aplikasi...' -ForegroundColor Cyan
    Remove-Item -Recurse -Force 'lib'
    Copy-Item -Recurse -Force (Join-Path $aman 'lib') '.'
    Copy-Item -Force (Join-Path $aman 'pubspec.yaml') '.'
    if (Test-Path (Join-Path $aman 'analysis_options.yaml')) {
        Copy-Item -Force (Join-Path $aman 'analysis_options.yaml') '.'
    }
    Remove-Item -Recurse -Force $aman
}

# --- 5. Izin di AndroidManifest.xml ----------------------------------
$manifest = 'android/app/src/main/AndroidManifest.xml'
if (-not (Test-Path $manifest)) {
    Write-Host "[5/6] $manifest tidak ditemukan — lewati." -ForegroundColor Yellow
} else {
    $isi = Get-Content $manifest -Raw

    if ($isi -match 'android.permission.INTERNET') {
        Write-Host '[5/6] Izin sudah ada di manifest — dilewati.' -ForegroundColor Green
    } else {
        Write-Host '[5/6] Menambahkan izin internet & Bluetooth...' -ForegroundColor Cyan

        $izin = @'
    <!-- Semua data hidup di server Laravel; tanpa ini app tidak bisa apa-apa. -->
    <uses-permission android:name="android.permission.INTERNET"/>
    <uses-permission android:name="android.permission.ACCESS_NETWORK_STATE"/>

    <!-- Printer struk thermal. BLUETOOTH_CONNECT/SCAN untuk Android 12+;
         dua baris di bawahnya untuk Android 11 ke bawah yang belum
         mengenal izin baru itu. -->
    <uses-permission android:name="android.permission.BLUETOOTH_CONNECT"/>
    <uses-permission android:name="android.permission.BLUETOOTH_SCAN"
        android:usesPermissionFlags="neverForLocation"/>
    <uses-permission android:name="android.permission.BLUETOOTH"
        android:maxSdkVersion="30"/>
    <uses-permission android:name="android.permission.BLUETOOTH_ADMIN"
        android:maxSdkVersion="30"/>

    <!-- Tablet tanpa printer tetap harus bisa dipasangi aplikasi ini. -->
    <uses-feature android:name="android.hardware.bluetooth" android:required="false"/>

'@

        # Disisipkan tepat setelah tag <manifest ...> pembuka.
        $isi = [regex]::Replace(
            $isi,
            '(?s)(<manifest[^>]*>\s*\r?\n)',
            "`$1$izin",
            [System.Text.RegularExpressions.RegexOptions]::None,
            [TimeSpan]::FromSeconds(5)
        )

        # Nama yang muncul di bawah ikon aplikasi.
        $isi = $isi -replace 'android:label="otin_carwash"', 'android:label="OTIN Carwash"'

        Set-Content -Path $manifest -Value $isi -NoNewline
        Write-Host '      Izin ditambahkan.' -ForegroundColor Green
    }
}

# --- 6. Paket --------------------------------------------------------
Write-Host '[6/6] Mengambil paket...' -ForegroundColor Cyan
flutter pub get

Write-Host ''
Write-Host '=== Selesai ===' -ForegroundColor Green
Write-Host ''
Write-Host 'Langkah berikutnya:'
Write-Host '  flutter doctor              # pastikan tidak ada tanda silang merah'
Write-Host '  flutter devices             # colok tablet (USB debugging menyala)'
Write-Host '  flutter run                 # jalankan di tablet'
Write-Host '  flutter build apk --release # bikin APK untuk dipasang di tablet jualan'
Write-Host ''
