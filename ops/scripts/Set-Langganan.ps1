<#
    Mengaktifkan / memperpanjang langganan satu cucian, atau mengganti
    paketnya, dari laptop - tanpa Terminal di cPanel.

        powershell -ExecutionPolicy Bypass -File scripts\Set-Langganan.ps1 -Slug budi -Hari 30 -Paket usaha -Catatan "transfer BCA 26/10"
        ... -Slug budi -Paket kasir          ganti paket saja, tanggal akhir tetap
        ... -Slug budi -Hari 30 -Uji         cetak permintaannya saja, tidak dikirim

    Memanggil POST /api/langganan/perpanjang di server cucian, ditandatangani
    HMAC-SHA256 dengan LANGGANAN_KUNCI dari secrets\<slug>.env (harus sama
    persis dengan .env di server). Jalur yang sama nanti dipakai payment
    gateway, jadi server cucian tidak perlu diubah saat pindah ke gateway.

    Hari dihitung termasuk hari ini, dan disambung dari tanggal akhir kalau
    masih aktif - membayar lebih awal tidak menghanguskan sisa hari.
    Nomor pesanan (bawaan MANUAL-<slug>-<waktu>) dicatat server: kiriman ulang
    dengan nomor yang sama tidak menambah hari dua kali.

    Hasilnya dicatat di tenants\<slug>.json (langganan: sampai, paket,
    riwayat). -Url untuk menguji ke server lokal; hasilnya tidak dicatat.
    Alur lengkapnya di ops\PANDUAN-TAGIHAN.md.
#>

param(
    [Parameter(Mandatory)] [string]$Slug,
    [ValidateRange(0, 3660)] [int]$Hari = 0,
    [ValidateSet('kasir', 'usaha', 'juragan')] [string]$Paket,
    [string]$Catatan,
    [string]$Pesanan,
    [string]$Url,
    [switch]$Uji
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'Common.psm1') -Force

$Slug = $Slug.ToLower()
if ($Hari -lt 1 -and -not $Paket) { throw 'Isi -Hari (mis. 30) dan/atau -Paket kasir|usaha|juragan.' }
$t = Get-Tenant $Slug

# --- Kunci dari secrets\<slug>.env ------------------------------------------
$pRahasia = Join-Path (Get-OpsRoot) "secrets\$Slug.env"
if (-not (Test-Path $pRahasia)) { throw "secrets\$Slug.env tidak ada." }
$baris = [IO.File]::ReadAllLines($pRahasia) | Where-Object { $_ -match '^\s*LANGGANAN_KUNCI\s*=' } | Select-Object -First 1
$kunci = if ($baris) { ($baris -split '=', 2)[1].Trim().Trim('"') } else { '' }
if (-not $kunci) {
    throw ("LANGGANAN_KUNCI belum ada di secrets\$Slug.env. Cucian yang dibuat sebelum 26-10 belum punya: " +
           "buat kuncinya lalu tambahkan ke secrets DAN ke .env server - lihat PANDUAN-TAGIHAN.md bagian 'Cucian lama'.")
}

# --- Permintaan bertanda tangan ---------------------------------------------
if (-not $Pesanan) { $Pesanan = "MANUAL-$Slug-" + (Get-Date -Format 'yyyyMMddHHmmss') }
$isi = [ordered]@{
    pesanan = $Pesanan
    hari    = $Hari
    # Server menolak permintaan yang selisihnya > 10 menit dari jamnya.
    waktu   = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
}
if ($Paket) { $isi.paket = $Paket }
$badan = $isi | ConvertTo-Json -Compress
$bita  = [Text.Encoding]::UTF8.GetBytes($badan)
$hmac  = New-Object Security.Cryptography.HMACSHA256 (, [Text.Encoding]::UTF8.GetBytes($kunci))
$tanda = -join ($hmac.ComputeHash($bita) | ForEach-Object { $_.ToString('x2') })

$basis = if ($Url) { $Url.TrimEnd('/') } else { "https://$($t.domain)" }
$alamat = "$basis/api/langganan/perpanjang"

Write-Host "=== $($t.nama_bisnis) ($Slug) ===" -ForegroundColor Cyan
Write-Host "  kirim ke : $alamat"
Write-Host "  isi      : $badan"
if ($Uji) {
    Write-Host "Mode uji: tidak dikirim." -ForegroundColor Yellow
    return
}

# Windows PowerShell 5.1 bawaannya masih TLS 1.0; hosting menolaknya.
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
try {
    $r = Invoke-WebRequest -Uri $alamat -Method Post -Body $bita -ContentType 'application/json' `
        -Headers @{ 'X-Tanda' = $tanda; 'Accept' = 'application/json' } -UseBasicParsing
} catch {
    $kode = 0
    if ($_.Exception.Response) { $kode = [int]$_.Exception.Response.StatusCode }
    $pesan = if ($_.ErrorDetails -and $_.ErrorDetails.Message) { $_.ErrorDetails.Message } else { $_.Exception.Message }
    $sebab = switch ($kode) {
        404 { 'Rute aktivasi tidak ada: LANGGANAN_KUNCI belum ada di .env server, atau kode server lebih tua dari 19-10.' }
        403 { 'Tanda tangan ditolak: kunci di secrets berbeda dengan .env server.' }
        422 { 'Ditolak server: paket/hari tidak sah, atau jam laptop selisih > 10 menit dari server.' }
        default { 'Server tidak bisa dihubungi atau galat lain.' }
    }
    throw "Gagal ($kode). $sebab`n$pesan"
}

$d = ($r.Content | ConvertFrom-Json).data
$status = if ($d.aktif) { "AKTIF sampai $($d.sampai) (sisa $($d.sisa_hari) hari)" } else { 'BELUM AKTIF / HABIS' }
$namaPaket = if ($d.paket) { $d.paket } else { 'tanpa setelan (semua modul)' }
Write-Host "  langganan: $status" -ForegroundColor Green
Write-Host "  paket    : $namaPaket" -ForegroundColor Green
if ($d.ulang) {
    Write-Host "  [!] Nomor pesanan $Pesanan sudah pernah dicatat server - tidak ada yang berubah." -ForegroundColor Yellow
    return
}
if ($Url) {
    Write-Host "  (-Url: server uji, tenants\$Slug.json tidak diubah)"
    return
}

# --- Catat di tenants\<slug>.json -------------------------------------------
$riwayat = @()
if ($t.PSObject.Properties['langganan'] -and $t.langganan.riwayat) { $riwayat = @($t.langganan.riwayat) }
$riwayat += [pscustomobject][ordered]@{
    tanggal = (Get-Date -Format 'yyyy-MM-dd HH:mm')
    hari    = $Hari
    paket   = $(if ($Paket) { $Paket } else { $null })
    sampai  = $d.sampai
    pesanan = $Pesanan
    catatan = $(if ($Catatan) { $Catatan } else { $null })
}
$t | Add-Member -NotePropertyName langganan -Force -NotePropertyValue ([pscustomobject][ordered]@{
    sampai  = $d.sampai
    paket   = $d.paket
    riwayat = $riwayat
})
Save-Tenant $t
Write-Host "  tercatat : tenants\$Slug.json (commit bersama catatan lain)"
