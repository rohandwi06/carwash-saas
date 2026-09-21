<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OTIN CARWASH — Kasir Cuci Mobil & Motor</title>
<link rel="stylesheet" href="{{ asset('css/kasir.css') }}?v={{ filemtime(public_path('css/kasir.css')) }}">
</head>
<body>
<div class="app">

  @include('kasir.partials.header')

  <main class="body">

    <!-- ============ HOME ============ -->
    @include('kasir.partials.layar-home')

    <!-- ============ CONFIRM ============ -->
    @include('kasir.partials.layar-confirm')

    <!-- ============ DONE ============ -->
    @include('kasir.partials.layar-done')

    <!-- ============ RESI (hanya muncul saat print) ============ -->
    <div id="resi"></div>

    <!-- ============ JUAL F&B ============ -->
    @include('kasir.partials.layar-fnb')

    <!-- ============ KELOLA MENU F&B ============ -->
    @include('kasir.partials.layar-menu-fnb')

    <!-- ============ PEKERJA ============ -->
    @include('kasir.partials.layar-pekerja')

    <!-- ============ REKAP ============ -->
    @include('kasir.partials.layar-rekap')

    <!-- ============ PENGELUARAN ============ -->
    @include('kasir.partials.layar-pengeluaran')

    <!-- ============ PEMBUKUAN HARIAN ============ -->
    @include('kasir.partials.layar-buku')

    <!-- ============ DASHBOARD ============ -->
    @include('kasir.partials.layar-dashboard')

    <!-- ============ PANDUAN ============ -->
    @include('kasir.partials.layar-panduan')

  </main>

  <!-- ============ GEMINI ============ -->
  @include('kasir.partials.modal-ai')

  <!-- ============ PRATINJAU RESI ============ -->
  @include('kasir.partials.modal-resi')

  <!-- ============ PILIH F&B (dari layar konfirmasi) ============ -->
  @include('kasir.partials.modal-fnb')
</div>

{{-- Sidebar di LUAR .app: waktu dibuka, .app yang digeser ke kanan.
     Kalau sidebar ikut di dalam .app, dia akan ikut tergeser juga. --}}
@include('kasir.partials.drawer')

{{-- Layar "shift tutup": menutupi seluruh aplikasi saat kasir memakai
     aplikasi di luar jam operasional. Owner tidak pernah melihat ini. --}}
@include('kasir.partials.layar-terkunci')

@include('kasir.partials.login')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
{{-- Chart.js disimpan lokal, bukan dari CDN: aplikasi ini dipakai di jaringan
     toko yang bisa saja tanpa internet. --}}
<script src="{{ asset('js/chart.min.js') }}"></script>
<script src="{{ asset('js/kasir.js') }}?v={{ filemtime(public_path('js/kasir.js')) }}"></script>
</body>
</html>
