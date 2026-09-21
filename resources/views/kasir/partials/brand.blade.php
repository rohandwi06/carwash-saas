{{-- Nama usaha dengan kata terakhir berwarna kuning ("OTIN CARWASH" ->
     OTIN <kuning>CARWASH</kuning>). Satu kata saja tampil polos.
     Diisi ulang oleh JS (terapkanProfilUsaha) begitu owner mengganti nama. --}}
@php
    $kata  = preg_split('/\s+/', trim($usaha['name']));
    $akhir = count($kata) > 1 ? array_pop($kata) : null;
@endphp
<span class="nama-usaha">{{ implode(' ', $kata) }}@if($akhir) <span class="kuning">{{ $akhir }}</span>@endif</span>
