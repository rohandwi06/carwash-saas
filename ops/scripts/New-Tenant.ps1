<#
    Menyiapkan satu cucian baru di armada rapiin.id.

        powershell -ExecutionPolicy Bypass -File scripts\New-Tenant.ps1 `
            -Slug budi -NamaBisnis "BUDI CARWASH" -Owner "Pak Budi"

    Tanpa -Terapkan: hanya menulis berkas lokal (secrets\<slug>.env dan
    tenants\<slug>.json) lalu mencetak panggilan WHM yang AKAN dilakukan.
    Tidak ada yang menyentuh hosting. Aman diulang dengan -Timpa.

    Dengan -Terapkan: juga membuat akun cPanel dan database lewat WHM API.
    Butuh config.json bagian whm terisi dan RAPIIN_WHM_TOKEN.

    Sisa langkah (unggah paket, impor database cetakan, SSL, cron) masih
    manual dan dicetak di akhir - lihat docs\ARCHITECTURE.md.
#>

param(
    [Parameter(Mandatory)] [string]$Slug,
    [Parameter(Mandatory)] [string]$NamaBisnis,
    [Parameter(Mandatory)] [string]$Owner,
    [ValidateSet('kecil', 'sedang', 'besar')] [string]$PaketHarga = 'kecil',
    [string]$ZonaWaktu,
    [string]$OwnerUsername = 'owner',
    [switch]$Terapkan,
    [switch]$Timpa
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'Common.psm1') -Force

$Slug = $Slug.ToLower()
Test-TenantSlug $Slug
$a = Get-OpsConfig
if (-not $ZonaWaktu) { $ZonaWaktu = $a.zona_waktu_bawaan }
# Hanya tiga zona waktu Indonesia yang masuk akal di sini; salah ketik di
# sini menggeser jam semua transaksi cucian itu selamanya.
if ($ZonaWaktu -notin @('Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura')) {
    throw "Zona waktu '$ZonaWaktu' bukan WIB/WITA/WIT (Asia/Jakarta, Asia/Makassar, Asia/Jayapura)."
}

# Nama masuk ke APP_NAME="..." di .env; tanda kutip atau $ di dalamnya
# memotong nilai itu atau dibaca sebagai variabel oleh dotenv.
if ($NamaBisnis -match '["$\\]') { throw 'Nama bisnis tidak boleh berisi tanda kutip ganda, $, atau backslash.' }

$pTenant  = Get-TenantPath $Slug
$pRahasia = Join-Path (Get-OpsRoot) "secrets\$Slug.env"
if ((Test-Path $pTenant) -and -not $Timpa) {
    throw "tenants\$Slug.json sudah ada. Pakai -Timpa kalau memang mau menyiapkan ulang (password lama di secrets\ ikut diganti)."
}
if ((Test-Path $pTenant) -and $Timpa -and (Get-Tenant $Slug).status -ne 'disiapkan') {
    throw "Tenant '$Slug' berstatus '$((Get-Tenant $Slug).status)' - tidak boleh ditimpa. Hanya status 'disiapkan' yang boleh."
}

$domain   = "$Slug.$($a.domain_utama)"
$prefix   = Get-DbPrefix $Slug $a.prefix_db_panjang
$dbNama   = "${prefix}_app"
$dbUser   = "${prefix}_app"
$dbPass   = New-Secret 24
$ownerPw  = New-Secret 14
$gemini   = $env:RAPIIN_GEMINI_KEY
if (-not $gemini) { $gemini = '<isi-GEMINI_API_KEY>' }

# --- 1. .env terisi -> secrets\ (di-gitignore) -----------------------------
$isiEnv = Expand-Template 'tenant.env' @{
    NAMA_BISNIS    = $NamaBisnis
    TANGGAL        = (Get-Date -Format 'yyyy-MM-dd')
    FOLDER_APP     = $a.folder_app
    APP_KEY        = (New-AppKey)
    DOMAIN         = $domain
    ZONA_WAKTU     = $ZonaWaktu
    DB_NAMA        = $dbNama
    DB_USER        = $dbUser
    DB_PASSWORD    = $dbPass
    OWNER_USERNAME = $OwnerUsername
    OWNER_PASSWORD = $ownerPw
    GEMINI_API_KEY = $gemini
    GEMINI_MODEL   = $a.gemini_model
}
Write-Utf8 $pRahasia $isiEnv

# --- 2. Catatan tenant -> tenants\ (di-commit) ------------------------------
$tenant = [ordered]@{
    slug        = $Slug
    nama_bisnis = $NamaBisnis
    status      = 'disiapkan'
    paket_harga = $PaketHarga
    domain      = $domain
    zona_waktu  = $ZonaWaktu
    hosting     = [ordered]@{
        jenis       = 'reseller'
        cpanel_user = $Slug
        folder_app  = $a.folder_app
        docroot     = 'public_html'
        prefix_db   = $prefix
    }
    owner       = [ordered]@{ nama = $Owner }
    mulai       = $null
    versi       = [ordered]@{ commit = $null; pasti = $true; migrasi_terakhir = $null }
    catatan     = @()
}
Save-Tenant ([pscustomobject]$tenant)

Write-Host "=== $NamaBisnis ($Slug) ===" -ForegroundColor Cyan
Write-Host "  domain     : https://$domain"
Write-Host "  database   : $dbNama  (user $dbUser)"
Write-Host "  zona waktu : $ZonaWaktu"
Write-Host "  tertulis   : tenants\$Slug.json, secrets\$Slug.env"
if ($gemini -like '<*') { Write-Host "  [!] RAPIIN_GEMINI_KEY kosong - GEMINI_API_KEY di secrets\$Slug.env masih harus diisi." -ForegroundColor Yellow }

# --- 3. Hosting lewat WHM API -----------------------------------------------
$cpanelPw = New-Secret 24
$langkah = @(
    @{ Fungsi = 'createacct'; Parameter = @{
        username = $Slug; domain = $domain; password = $cpanelPw
        plan = $a.whm.paket_cpanel; contactemail = $a.whm.email_kontak } },
    @{ Fungsi = 'uapi_cpanel'; Parameter = @{
        'cpanel.user' = $Slug; 'cpanel.module' = 'Mysql'; 'cpanel.function' = 'create_database'; name = $dbNama } },
    @{ Fungsi = 'uapi_cpanel'; Parameter = @{
        'cpanel.user' = $Slug; 'cpanel.module' = 'Mysql'; 'cpanel.function' = 'create_user'; name = $dbUser; password = $dbPass } },
    @{ Fungsi = 'uapi_cpanel'; Parameter = @{
        'cpanel.user' = $Slug; 'cpanel.module' = 'Mysql'; 'cpanel.function' = 'set_privileges_on_database'
        user = $dbUser; database = $dbNama; privileges = 'ALL PRIVILEGES' } }
)

Write-Host ""
if (-not $Terapkan) {
    Write-Host "Mode uji (tanpa -Terapkan). Panggilan WHM yang akan dilakukan:" -ForegroundColor Yellow
    foreach ($l in $langkah) {
        $ringkas = ($l.Parameter.GetEnumerator() | Where-Object { $_.Key -ne 'password' } |
                    Sort-Object Key | ForEach-Object { "$($_.Key)=$($_.Value)" }) -join ' '
        Write-Host "  $($l.Fungsi)  $ringkas"
    }
} else {
    foreach ($l in $langkah) {
        Write-Host "  -> $($l.Fungsi) $($l.Parameter['cpanel.function'])" -NoNewline
        Invoke-Whm $l.Fungsi $l.Parameter | Out-Null
        Write-Host "  ok" -ForegroundColor Green
    }
    # Password cPanel tidak dipakai aplikasi, tapi dibutuhkan untuk masuk
    # File Manager selama unggah masih manual.
    Add-Content -LiteralPath $pRahasia -Value "`n# cPanel $Slug (bukan untuk aplikasi)`n# CPANEL_PASSWORD=$cpanelPw" -Encoding UTF8
}

# --- 4. Sisa langkah manual -------------------------------------------------
Write-Host ""
Write-Host "Langkah berikutnya (manual sampai New-Release.ps1 & verifikasi WHM selesai):" -ForegroundColor Cyan
Write-Host "  1. Unggah app.zip ke ~/$($a.folder_app) dan public.zip ke ~/public_html, lalu ekstrak."
Write-Host "  2. Salin secrets\$Slug.env menjadi ~/$($a.folder_app)/.env, permission 600."
Write-Host "  3. phpMyAdmin -> $dbNama -> impor cetakan.sql dari rilis yang sama."
Write-Host "  4. SSL/TLS Status -> Run AutoSSL untuk $domain."
Write-Host "  5. Cron: * * * * * php ~/$($a.folder_app)/artisan schedule:run >/dev/null 2>&1"
Write-Host "  6. Serahkan login owner: $OwnerUsername / (lihat secrets\$Slug.env) - minta langsung diganti."
Write-Host "  7. Isi versi & mulai di tenants\$Slug.json, status -> percobaan, commit."
