<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkerRequest;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerController extends Controller
{
    /**
     * GET /api/workers
     *
     * Kasir tetap butuh daftar ini untuk memilih siapa yang mengerjakan
     * cucian — tapi ia hanya menerima id/nama/kehadiran. NIK, alamat,
     * tanggal lahir, dan nomor telepon adalah data pribadi pekerja; tidak ada
     * alasan data itu ikut terkirim ke setiap tablet kasir yang sedang login.
     */
    public function index(Request $request): JsonResponse
    {
        $owner = $request->attributes->get('auth_role') === 'owner';

        $workers = Worker::orderBy('name')->get();

        return response()->json([
            'data' => $owner
                ? $workers
                : $workers->map->only('id', 'name', 'is_present'),
        ]);
    }

    /** POST /api/workers */
    public function store(StoreWorkerRequest $request): JsonResponse
    {
        return response()->json(['data' => Worker::create($request->validated())], 201);
    }

    /**
     * PATCH /api/workers/{worker} — ganti nama & biodata.
     *
     * Aturan validasinya dipinjam dari StoreWorkerRequest supaya batas yang
     * berlaku saat menambah pekerja tetap berlaku saat mengubahnya; id-nya
     * dikecualikan dari cek unik agar menyimpan tanpa mengubah nama/NIK
     * tidak ditolak oleh datanya sendiri.
     */
    public function update(Request $request, Worker $worker): JsonResponse
    {
        $data = $request->validate(
            StoreWorkerRequest::aturan($worker->id),
            StoreWorkerRequest::pesan(),
        );

        $worker->update($data);

        return response()->json(['data' => $worker->fresh()]);
    }

    /** DELETE /api/workers/{worker} */
    public function destroy(Worker $worker): JsonResponse
    {
        $worker->delete();

        return response()->json(['data' => null]);
    }
}
