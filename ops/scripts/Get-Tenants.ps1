<#
    Ringkasan armada: siapa saja, statusnya, dan versi aplikasi yang terpasang
    dibanding HEAD repo aplikasi.

        powershell -ExecutionPolicy Bypass -File scripts\Get-Tenants.ps1
#>

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'Common.psm1') -Force

$repo = Get-AppRoot
$head = (git -C $repo rev-parse --short HEAD 2>$null)

$baris = foreach ($t in Get-Tenant) {
    $versi = $t.versi.commit
    if (-not $versi) { $versi = '-' }
    elseif (-not $t.versi.pasti) { $versi = "$($versi)?" }

    $ketinggalan = ''
    # Commit yang tidak dikenal repo ini (mis. hash lama dari otin-carwash)
    # dilewati tanpa error; -q membuat rev-parse diam saat gagal.
    $dikenal = $t.versi.commit -and (git -C $repo rev-parse -q --verify "$($t.versi.commit)^{commit}")
    if ($head -and $dikenal) {
        $n = git -C $repo rev-list --count "$($t.versi.commit)..HEAD" 2>$null
        if ($LASTEXITCODE -eq 0) { $ketinggalan = if ($n -eq '0') { 'terbaru' } else { "$n commit" } }
    }

    [pscustomobject]@{
        Slug        = $t.slug
        Nama        = $t.nama_bisnis
        Status      = $t.status
        Paket       = $t.paket_harga
        Domain      = $t.domain
        Versi       = $versi
        Ketinggalan = $ketinggalan
    }
}

$baris | Format-Table -AutoSize
if ($head) { Write-Host "HEAD aplikasi: $head  ($($repo))" }
Write-Host "Versi bertanda ? = belum dipastikan; update berikutnya wajib paket penuh."
