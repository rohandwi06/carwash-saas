<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\VehiclePhoto;
use App\Services\FotoKendaraanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Foto kondisi kendaraan. Kasir mengunggah setelah transaksinya tersimpan;
 * kasir & owner membukanya dari detail transaksi di Rekap dan Pembukuan;
 * hanya owner yang menghapus.
 */
class FotoKendaraanController extends Controller
{
    public function __construct(private FotoKendaraanService $foto) {}

    /** POST /api/transactions/{transaction}/photos {image: data URL} */
    public function store(Request $request, Transaction $transaction): JsonResponse
    {
        $data = $request->validate(['image' => ['required', 'string', 'max:6000000']]);

        $foto = $this->foto->simpan($transaction, $data['image'], $request->attributes->get('auth_name'));

        return response()->json(['data' => ['id' => $foto->id, 'transaction_id' => $transaction->id]], 201);
    }

    /** GET /api/photos/{vehiclePhoto} — gambarnya sendiri */
    public function show(VehiclePhoto $vehiclePhoto): Response
    {
        $isi = $this->foto->isi($vehiclePhoto);
        abort_if($isi === null, 404, 'Berkas foto sudah tidak ada.');

        return response($isi['byte'], 200, [
            'Content-Type'  => $isi['mime'],
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** DELETE /api/photos/{vehiclePhoto} — owner */
    public function destroy(VehiclePhoto $vehiclePhoto): JsonResponse
    {
        $this->foto->hapus($vehiclePhoto);

        return response()->json(['data' => true]);
    }
}
