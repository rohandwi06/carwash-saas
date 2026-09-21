<#
    OTIN CARWASH - menyalakan Quick Tunnel Cloudflare.

    Jalankan (tidak perlu Administrator):
        powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\otin-carwash\deploy\windows\scripts\jalankan-tunnel.ps1

    Yang dilakukan:
      1. Memastikan Apache melayani aplikasi di port 8080
      2. Menjalankan preflight keamanan - MENOLAK jalan kalau masih ada
         temuan yang memblokir (kecuali dipaksa dengan -Abaikan)
      3. Menyalakan cloudflared dan menampilkan URL publiknya

    Parameter:
      -Abaikan     lewati gerbang keamanan (untuk uji coba sebentar)
      -Matikan     hentikan tunnel yang sedang jalan
      -Menit <N>   matikan sendiri setelah N menit. Dipakai otomatis (60 menit)
                   bila -Abaikan dipilih tanpa menyebut angka, supaya tunnel
                   uji coba tidak tertinggal menyala semalaman.

    Ingat: Quick Tunnel memberi URL ACAK yang berubah setiap kali dinyalakan.
    URL tetap butuh domain di Cloudflare - lihat README.md Tahap 2b.
#>

param([switch]$Abaikan, [switch]$Matikan, [int]$Menit = 0, [switch]$TanpaBatas)

$ErrorActionPreference = 'Stop'

$PROYEK     = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
$CLOUDFLARED = 'C:\cloudflared\cloudflared.exe'
$PREFLIGHT  = Join-Path $PSScriptRoot 'preflight-keamanan.ps1'
$LOG        = 'C:\cloudflared\quick-tunnel.log'
$PORT       = 8080

function Oke($t)  { Write-Host "  OK   $t" -ForegroundColor Green }
function Awas($t) { Write-Host "  !!   $t" -ForegroundColor Yellow }
function Mati($t) { Write-Host "  X    $t" -ForegroundColor Red; exit 1 }

Write-Host "`n=== Tunnel OTIN CARWASH ===" -ForegroundColor Cyan

if ($Matikan) {
    $p = Get-Process cloudflared -ErrorAction SilentlyContinue
    if ($p) { $p | Stop-Process -Force; Oke "Tunnel dimatikan - aplikasi tidak lagi bisa diakses dari internet" }
    else    { Awas "Tunnel memang tidak sedang jalan" }
    exit 0
}

if (-not (Test-Path $CLOUDFLARED)) {
    Mati "cloudflared belum ada di $CLOUDFLARED - unduh dari github.com/cloudflare/cloudflared/releases"
}
if (Get-Process cloudflared -ErrorAction SilentlyContinue) {
    Mati "Tunnel sudah jalan. Matikan dulu dengan -Matikan bila mau URL baru."
}

# --- 1. Apache melayani? ----------------------------------------------------
try {
    $r = Invoke-WebRequest -Uri "http://localhost:$PORT/" -UseBasicParsing -TimeoutSec 15
    if ($r.StatusCode -ne 200) { Mati "Apache membalas $($r.StatusCode), bukan 200." }
    Oke "Apache melayani aplikasi di port $PORT"
} catch {
    Mati "Aplikasi tidak menjawab di http://localhost:$PORT - nyalakan Apache dulu dari XAMPP Control Panel."
}

# --- 2. Gerbang keamanan ----------------------------------------------------
# Membuka aplikasi ke internet sebelum temuan preflight beres berarti
# menaruh pembukuan toko di tempat yang bisa dijangkau siapa saja.
if ($Abaikan) {
    Awas "Gerbang keamanan DILEWATI atas permintaan Anda (-Abaikan)."
    # Uji coba gampang terlupakan, jadi bawaannya diberi batas waktu.
    # Tugas startup memakai -TanpaBatas karena memang harus terus menyala.
    if ($TanpaBatas) {
        Awas "Tanpa batas waktu - tunnel menyala sampai dimatikan manual."
    } else {
        if ($Menit -le 0) { $Menit = 60 }
        Awas "Tunnel akan mati sendiri setelah $Menit menit."
    }
} else {
    Write-Host "`nMemeriksa keamanan..." -ForegroundColor Cyan
    & powershell -ExecutionPolicy Bypass -File $PREFLIGHT
    if ($LASTEXITCODE -ne 0) {
        Write-Host ""
        Mati "Preflight gagal. Perbaiki dulu temuan di atas, atau paksa dengan -Abaikan."
    }
}

# --- 3. Nyalakan tunnel -----------------------------------------------------
Remove-Item $LOG -ErrorAction SilentlyContinue
Start-Process -FilePath $CLOUDFLARED `
    -ArgumentList 'tunnel','--no-autoupdate','--url',"http://localhost:$PORT" `
    -RedirectStandardError $LOG -RedirectStandardOutput "$LOG.out" -WindowStyle Hidden

Write-Host "`nMenunggu URL terbit..." -ForegroundColor Cyan
$url = $null
for ($i = 0; $i -lt 40; $i++) {
    Start-Sleep -Seconds 1
    if (Test-Path $LOG) {
        $m = Select-String -Path $LOG -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -ErrorAction SilentlyContinue
        if ($m) { $url = $m.Matches[0].Value; break }
    }
}

if (-not $url) {
    Get-Process cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
    Write-Host "Isi log:" -ForegroundColor Red
    Get-Content $LOG -Tail 20 -ErrorAction SilentlyContinue
    Mati "Gagal mendapatkan URL. Tunnel dimatikan lagi."
}

# URL disimpan ke berkas: saat dijalankan otomatis waktu startup, tidak ada
# jendela yang bisa dibaca. Buka berkas ini untuk tahu alamat hari ini.
$berkasUrl = Join-Path $PROYEK 'storage\logs\tunnel-url.txt'
@"
URL tunnel OTIN CARWASH
Dibuat : $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')

    $url

URL ini ACAK dan berubah setiap kali tunnel dinyalakan ulang
(termasuk setiap kali laptop dihidupkan). Untuk alamat tetap,
perlu domain di Cloudflare - lihat deploy\windows\README.md Tahap 2b.
"@ | Set-Content -Path $berkasUrl -Encoding UTF8

# --- 4. Batas waktu otomatis ------------------------------------------------
if ($Menit -gt 0 -and -not $TanpaBatas) {
    # Proses terpisah yang menunggu lalu mematikan tunnel. Dilepas dari
    # jendela ini supaya tetap berlaku walau PowerShell-nya ditutup.
    $perintah = "Start-Sleep -Seconds $($Menit * 60); " +
                "Get-Process cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force"
    Start-Process powershell -ArgumentList '-NoProfile','-WindowStyle','Hidden','-Command',$perintah `
        -WindowStyle Hidden
}

Write-Host ""
Write-Host "==================================================" -ForegroundColor Green
Write-Host "  $url" -ForegroundColor Green
Write-Host "==================================================" -ForegroundColor Green
Write-Host "Buka alamat itu dari HP mana pun." -ForegroundColor Gray
Write-Host "Tersimpan juga di: $berkasUrl" -ForegroundColor Gray
Write-Host "URL ini ACAK dan akan berbeda setiap kali dinyalakan ulang." -ForegroundColor Yellow
if ($Menit -gt 0 -and -not $TanpaBatas) {
    Write-Host "Mati sendiri pada: $((Get-Date).AddMinutes($Menit).ToString('HH:mm'))" -ForegroundColor Yellow
}
Write-Host "`nMatikan dengan:" -ForegroundColor Cyan
Write-Host "  powershell -File `"$PSCommandPath`" -Matikan" -ForegroundColor Gray
Write-Host ""
