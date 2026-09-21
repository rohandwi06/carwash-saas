<#
    Fungsi bersama skrip armada rapiin.id. Dipakai lewat:
        Import-Module (Join-Path $PSScriptRoot 'Armada.psm1') -Force

    Ditulis untuk Windows PowerShell 5.1 (bawaan Windows, yang dipakai skrip
    deploy di otin-carwash) - jadi tanpa ??, ternary, atau -AsHashtable.
#>

$ErrorActionPreference = 'Stop'

$script:Akar = Split-Path -Parent $PSScriptRoot

function Get-AkarArmada { $script:Akar }

# JSON ditulis UTF-8 TANPA BOM. Set-Content -Encoding UTF8 di PowerShell 5.1
# menambahkan BOM, dan berkas tenant dibaca juga oleh alat lain (git diff, editor).
function Write-Utf8 {
    param([string]$Path, [string]$Isi)
    $dir = Split-Path -Parent $Path
    if ($dir -and -not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    [IO.File]::WriteAllText($Path, $Isi, (New-Object Text.UTF8Encoding($false)))
}

function Read-Json {
    param([string]$Path)
    [IO.File]::ReadAllText($Path, [Text.Encoding]::UTF8) | ConvertFrom-Json
}

function Get-Armada { Read-Json (Join-Path $script:Akar 'armada.json') }

# repo_aplikasi di armada.json relatif terhadap folder armada\ - bawaannya ".."
# karena aplikasi Laravel tinggal di root repo yang sama.
function Get-RepoAplikasi {
    $p = (Get-Armada).repo_aplikasi
    if (-not [IO.Path]::IsPathRooted($p)) { $p = Join-Path $script:Akar $p }
    [IO.Path]::GetFullPath($p)
}

function Get-PathTenant {
    param([string]$Slug)
    Join-Path $script:Akar "tenant\$Slug.json"
}

function Get-Tenant {
    param([string]$Slug)
    if ($Slug) {
        $p = Get-PathTenant $Slug
        if (-not (Test-Path $p)) { throw "Tenant '$Slug' tidak ada di tenant\." }
        return Read-Json $p
    }
    Get-ChildItem (Join-Path $script:Akar 'tenant') -Filter '*.json' |
        Sort-Object Name |
        ForEach-Object { Read-Json $_.FullName }
}

# ConvertTo-Json di PowerShell 5.1 menulis indentasi acak (": " ganda, kurung
# menjorok sejajar kunci). Dirapikan jadi 2 spasi supaya diff git tenant\*.json
# hanya memperlihatkan nilai yang benar-benar berubah.
function Format-Json {
    param([string]$Json)
    # 5.1 juga menulis ' < > & sebagai \u00xx; sah di JSON, tapi merusak diff.
    foreach ($k in '0027', '003c', '003e', '0026') {
        $Json = $Json.Replace([string][char]92 + 'u' + $k, [string][char][Convert]::ToInt32($k, 16))
    }
    $Json = [regex]::Replace($Json, '\[\s*\]', '[]')
    $Json = [regex]::Replace($Json, '\{\s*\}', '{}')
    $tingkat = 0
    $hasil = foreach ($baris in ($Json -split "`r?`n")) {
        $b = $baris.Trim()
        if (-not $b) { continue }
        if ($b -match '^[\}\]]') { $tingkat-- }
        $b = [regex]::Replace($b, '^("(?:[^"\\]|\\.)*"):\s+', '$1: ')
        ('  ' * $tingkat) + $b
        if ($b -match '[\{\[]$') { $tingkat++ }
    }
    ($hasil -join "`n") + "`n"
}

function Save-Tenant {
    param($Tenant)
    Write-Utf8 (Get-PathTenant $Tenant.slug) (Format-Json ($Tenant | ConvertTo-Json -Depth 6))
}

# Slug = nama akun cPanel = subdomain, jadi aturannya aturan username cPanel:
# huruf kecil & angka, diawali huruf, maks 16, tanpa tanda hubung,
# dan cPanel menolak username berawalan "test".
function Test-Slug {
    param([string]$Slug)
    if ($Slug -cnotmatch '^[a-z][a-z0-9]{1,15}$') {
        throw "Slug '$Slug' tidak sah: huruf kecil/angka, diawali huruf, 2-16 karakter, tanpa tanda hubung."
    }
    if ($Slug -like 'test*') { throw "cPanel menolak username berawalan 'test'." }
}

# Prefix database cPanel = potongan awal username. Di ArenHost panjangnya 8
# (database OTIN bernama otincarw_...), diatur di armada.json.
function Get-PrefixDb {
    param([string]$Slug, [int]$Panjang)
    if ($Slug.Length -le $Panjang) { return $Slug }
    $Slug.Substring(0, $Panjang)
}

function New-Rahasia {
    param([int]$Panjang = 20)
    # Tanpa karakter yang merepotkan di .env ( " ' $ # spasi ) dan tanpa
    # yang mudah tertukar saat didiktekan ke owner (0/O, 1/l/I).
    $huruf = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'
    $acak  = New-Object byte[] $Panjang
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($acak)
    -join ($acak | ForEach-Object { $huruf[$_ % $huruf.Length] })
}

function New-AppKey {
    $b = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
    'base64:' + [Convert]::ToBase64String($b)
}

function Expand-Templat {
    param([string]$Nama, [hashtable]$Nilai)
    $isi = [IO.File]::ReadAllText((Join-Path $script:Akar "templat\$Nama"), [Text.Encoding]::UTF8)
    foreach ($k in $Nilai.Keys) { $isi = $isi.Replace("{{$k}}", [string]$Nilai[$k]) }
    $sisa = [regex]::Matches($isi, '\{\{[A-Z_]+\}\}') | ForEach-Object { $_.Value } | Sort-Object -Unique
    if ($sisa) { throw "Templat $Nama masih punya isian kosong: $($sisa -join ', ')" }
    $isi
}

<#
    Panggilan WHM API 1. Token dibuat di WHM -> Manage API Tokens, disimpan di
    variabel lingkungan RAPIIN_WHM_TOKEN - tidak pernah di berkas repo.

    Untuk fungsi cPanel (UAPI) atas akun milik reseller, pakai
    -Fungsi uapi_cpanel dengan cpanel.user / cpanel.module / cpanel.function.

    BELUM DIUJI ke ArenHost: apakah reseller mereka membuka API token adalah
    verifikasi #1 di docs/ARSITEKTUR.md.
#>
function Invoke-Whm {
    param([string]$Fungsi, [hashtable]$Parameter = @{})
    $a = Get-Armada
    if (-not $a.whm.host -or -not $a.whm.reseller_user) { throw "whm.host / whm.reseller_user belum diisi di armada.json." }
    $token = $env:RAPIIN_WHM_TOKEN
    if (-not $token) { throw "Variabel lingkungan RAPIIN_WHM_TOKEN belum diisi." }

    $q = @('api.version=1') + ($Parameter.Keys | ForEach-Object {
        '{0}={1}' -f [Uri]::EscapeDataString($_), [Uri]::EscapeDataString([string]$Parameter[$_])
    })
    $url = 'https://{0}:{1}/json-api/{2}?{3}' -f $a.whm.host, $a.whm.port, $Fungsi, ($q -join '&')
    $h = @{ Authorization = "whm $($a.whm.reseller_user):$token" }
    $r = Invoke-RestMethod -Uri $url -Headers $h -Method Get

    if ($r.metadata.result -ne 1) { throw "WHM $Fungsi gagal: $($r.metadata.reason)" }
    # uapi_cpanel membungkus hasil UAPI; status 0 di dalamnya tetap kegagalan.
    if ($Fungsi -eq 'uapi_cpanel' -and $r.data.uapi.status -ne 1) {
        throw "UAPI $($Parameter['cpanel.module'])::$($Parameter['cpanel.function']) gagal: $($r.data.uapi.errors -join '; ')"
    }
    $r
}

Export-ModuleMember -Function *
