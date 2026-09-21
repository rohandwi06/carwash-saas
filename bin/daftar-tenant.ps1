<#
    Ringkasan armada: siapa saja, statusnya, dan versi aplikasi yang terpasang
    dibanding HEAD repo aplikasi.

        powershell -ExecutionPolicy Bypass -File bin\daftar-tenant.ps1
#>

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'Armada.psm1') -Force

$a = Get-Armada
$head = $null
if (Test-Path $a.repo_aplikasi) {
    $head = (git -C $a.repo_aplikasi rev-parse --short HEAD 2>$null)
}

$baris = foreach ($t in Get-Tenant) {
    $versi = $t.versi.commit
    if (-not $versi) { $versi = '-' }
    elseif (-not $t.versi.pasti) { $versi = "$($versi)?" }

    $ketinggalan = ''
    if ($head -and $t.versi.commit) {
        $n = git -C $a.repo_aplikasi rev-list --count "$($t.versi.commit)..HEAD" 2>$null
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
if ($head) { Write-Host "HEAD aplikasi: $head  ($($a.repo_aplikasi))" }
Write-Host "Versi bertanda ? = belum dipastikan; update berikutnya wajib paket penuh."
