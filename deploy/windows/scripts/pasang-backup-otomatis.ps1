<#
    OTIN CARWASH - memasang backup otomatis harian.

    Jalankan sebagai ADMINISTRATOR:
        powershell -ExecutionPolicy Bypass -File deploy\windows\scripts\pasang-backup-otomatis.ps1

    Latar belakang: jadwal backup 21:30 sudah lama tertulis di
    routes/console.php, TAPI tidak pernah berjalan karena tidak ada satu pun
    yang memanggil `artisan schedule:run`. Akibatnya backup terakhir berumur
    12 hari saat ini ditemukan. Skrip ini memasang pemanggilnya.

    Yang dipasang: tugas "OtinCarwash Scheduler" yang menjalankan
    deploy\scheduler-run.vbs tiap menit. Laravel sendiri yang memutuskan
    perintah mana yang waktunya tiba - jadi menambah jadwal baru cukup di
    routes/console.php, tanpa menyentuh Task Scheduler lagi.

    Parameter:
      -Hapus        lepas kembali tugasnya
      -Periksa      laporkan kondisi backup sekarang, tanpa mengubah apa pun
      -UjiSekarang  buktikan SYSTEM benar-benar bisa membuat backup, tanpa
                    menunggu jam 21:30 (perlu Administrator)

    PENTING soal "hasil terakhir: 0" di Task Scheduler:
    scheduler-run.vbs memanggil PHP dengan shell.Run(..., 0, False) - argumen
    False berarti "jangan tunggu selesai", jadi wscript langsung keluar dengan
    kode 0 TANPA peduli PHP-nya berhasil atau gagal. Kode 0 hanya membuktikan
    wscript terpanggil. Bukti backup benar-benar jadi cuma satu: tanggal berkas
    di storage\backups - atau jalankan -UjiSekarang.
#>

param([switch]$Hapus, [switch]$Periksa, [switch]$UjiSekarang)

$ErrorActionPreference = 'Stop'

$PROYEK  = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
$VBS     = Join-Path $PROYEK 'deploy\scheduler-run.vbs'
$BACKUP  = Join-Path $PROYEK 'storage\backups'
$TUGAS   = 'OtinCarwash Scheduler'

function Oke($t)  { Write-Host "  OK   $t" -ForegroundColor Green }
function Awas($t) { Write-Host "  !!   $t" -ForegroundColor Yellow }
function Mati($t) { Write-Host "  X    $t" -ForegroundColor Red; exit 1 }

function AdalahAdmin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
}

Write-Host "`n=== Backup otomatis OTIN CARWASH ===" -ForegroundColor Cyan

# --- Laporan kondisi --------------------------------------------------------
function LaporKondisi {
    $t = Get-ScheduledTask -TaskName $TUGAS -ErrorAction SilentlyContinue
    if ($t) {
        $info = Get-ScheduledTaskInfo -TaskName $TUGAS
        Oke "Tugas '$TUGAS' terpasang (status: $($t.State))"
        Write-Host "       terakhir jalan : $($info.LastRunTime)" -ForegroundColor Gray
        Write-Host "       hasil terakhir : $($info.LastTaskResult)  (0 = sukses)" -ForegroundColor Gray
        Write-Host "       CATATAN: hasil 0 hanya berarti wscript berhasil DIPANGGIL." -ForegroundColor DarkGray
        Write-Host "       Bukti backup benar-benar jadi = tanggal berkas di bawah." -ForegroundColor DarkGray
    } elseif (-not (AdalahAdmin)) {
        # Tugas milik SYSTEM tidak selalu terbaca oleh sesi biasa. Jangan
        # mengaku "belum terpasang" - itu laporan palsu yang menyesatkan.
        Awas "Tidak bisa membaca daftar tugas tanpa Administrator."
        Write-Host "       Jalankan ulang lewat PowerShell (Run as administrator)" -ForegroundColor Gray
        Write-Host "       untuk memastikan tugasnya ada atau tidak." -ForegroundColor Gray
    } else {
        Awas "Tugas '$TUGAS' BELUM terpasang - tidak ada backup otomatis"
    }

    if (Test-Path $BACKUP) {
        $f = Get-ChildItem $BACKUP -Filter *.sql -ErrorAction SilentlyContinue |
             Sort-Object LastWriteTime -Descending
        if ($f) {
            $umur = [math]::Round(((Get-Date) - $f[0].LastWriteTime).TotalDays, 1)
            $pesan = "Backup terbaru: $($f[0].Name)  ($umur hari lalu, $([math]::Round($f[0].Length/1KB)) KB)"
            if ($umur -gt 2) { Awas $pesan } else { Oke $pesan }
            Write-Host "       jumlah tersimpan: $($f.Count) berkas" -ForegroundColor Gray
        } else {
            Awas "Folder backup kosong - belum ada backup sama sekali"
        }
        Write-Host "       lokasi: $BACKUP" -ForegroundColor Gray
    }
}

if ($Periksa) { LaporKondisi; Write-Host ""; exit 0 }

# --- Uji: benarkah SYSTEM bisa membuat backup? ------------------------------
# Tugas terjadwal berjalan sebagai SYSTEM, bukan sebagai Anda. Hak akses
# berkas & koneksi MySQL-nya bisa saja berbeda. Mode ini membuktikannya
# sekarang juga, tanpa menunggu jam 21:30.
if ($UjiSekarang) {
    if (-not (AdalahAdmin)) { Mati "Mode -UjiSekarang perlu Administrator." }

    $sebelum = @(Get-ChildItem $BACKUP -Filter *.sql -ErrorAction SilentlyContinue).Count
    $tugasUji = 'OtinCarwash UjiBackup'
    Write-Host "`nMenjalankan db:backup sebagai SYSTEM..." -ForegroundColor Cyan

    $aksiUji = New-ScheduledTaskAction -Execute 'C:\xampp\php\php.exe' `
        -Argument 'artisan db:backup' -WorkingDirectory $PROYEK
    $sbgSystem = New-ScheduledTaskPrincipal -UserId 'SYSTEM' `
        -LogonType ServiceAccount -RunLevel Highest
    Register-ScheduledTask -TaskName $tugasUji -Action $aksiUji -Principal $sbgSystem `
        -Description 'Uji sekali pakai - dihapus otomatis.' -Force | Out-Null
    try {
        Start-ScheduledTask -TaskName $tugasUji
        for ($i = 0; $i -lt 30; $i++) {
            Start-Sleep -Seconds 1
            if ((Get-ScheduledTaskInfo -TaskName $tugasUji).LastTaskResult -ne 267009) { break }
        }
        $kode = (Get-ScheduledTaskInfo -TaskName $tugasUji).LastTaskResult
    } finally {
        Unregister-ScheduledTask -TaskName $tugasUji -Confirm:$false -ErrorAction SilentlyContinue
    }

    $sesudah = @(Get-ChildItem $BACKUP -Filter *.sql -ErrorAction SilentlyContinue).Count
    if ($sesudah -gt $sebelum) {
        $baru = Get-ChildItem $BACKUP -Filter *.sql | Sort-Object LastWriteTime -Descending | Select-Object -First 1
        Oke "TERBUKTI: SYSTEM berhasil membuat $($baru.Name) ($([math]::Round($baru.Length/1KB)) KB)"
        Write-Host "       Backup otomatis 21:30 dipastikan akan jalan.`n" -ForegroundColor Green
        exit 0
    }
    Write-Host "  X    GAGAL: tidak ada berkas backup baru (kode keluar php: $kode)" -ForegroundColor Red
    Write-Host "       SYSTEM tidak bisa menjalankan backup. Periksa hak akses folder" -ForegroundColor Red
    Write-Host "       storage\backups dan koneksi MySQL di .env.`n" -ForegroundColor Red
    exit 1
}

# --- Wajib administrator ----------------------------------------------------
$id = [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Mati "Harus dijalankan sebagai Administrator."
}

# --- Mode hapus -------------------------------------------------------------
if ($Hapus) {
    if (Get-ScheduledTask -TaskName $TUGAS -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $TUGAS -Confirm:$false
        Oke "Tugas '$TUGAS' dilepas"
    } else { Awas "Tugas tidak ada" }
    exit 0
}

# --- Prasyarat --------------------------------------------------------------
if (-not (Test-Path $VBS)) { Mati "Tidak ditemukan: $VBS" }

# Uji dulu backup-nya memang bisa jalan, sebelum menjadwalkan sesuatu yang
# gagal diam-diam. Inilah pelajaran dari typo "xamppp" kemarin.
Write-Host "`nUji coba backup manual dulu..." -ForegroundColor Cyan
Push-Location $PROYEK
try {
    $out = & 'C:\xampp\php\php.exe' artisan db:backup 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) { Write-Host $out -ForegroundColor Red; Mati "db:backup gagal - perbaiki dulu sebelum dijadwalkan." }
    Oke ("db:backup jalan: " + ($out.Trim() -split "`n")[0])
} finally { Pop-Location }

# --- Pasang tugas -----------------------------------------------------------
if (Get-ScheduledTask -TaskName $TUGAS -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $TUGAS -Confirm:$false
    Awas "Tugas lama dengan nama sama dilepas dulu"
}

$aksi = New-ScheduledTaskAction -Execute 'wscript.exe' `
    -Argument "`"$VBS`"" -WorkingDirectory $PROYEK

# Tiap menit selamanya: Laravel yang menentukan perintah mana yang waktunya
# tiba. Beban sangat ringan (satu proses php singkat).
$pemicu = New-ScheduledTaskTrigger -Once -At (Get-Date).Date `
    -RepetitionInterval (New-TimeSpan -Minutes 1)

$pengaturan = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

# SYSTEM: tetap jalan walau tidak ada yang login ke Windows.
$sebagai = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask -TaskName $TUGAS -Action $aksi -Trigger $pemicu `
    -Settings $pengaturan -Principal $sebagai `
    -Description 'Menjalankan Laravel scheduler OTIN CARWASH tiap menit (backup harian 21:30).' | Out-Null

Oke "Tugas '$TUGAS' terpasang, berjalan tiap menit sebagai SYSTEM"

Start-ScheduledTask -TaskName $TUGAS
Start-Sleep -Seconds 5

Write-Host ""
LaporKondisi

Write-Host "`nPeriksa kapan saja dengan:" -ForegroundColor Cyan
Write-Host "  powershell -File deploy\windows\scripts\pasang-backup-otomatis.ps1 -Periksa" -ForegroundColor Gray
Write-Host "`nIngat: backup ini masih di komputer yang sama." -ForegroundColor Yellow
Write-Host "Salin folder backups ke Google Drive/flashdisk minimal seminggu sekali." -ForegroundColor Yellow
