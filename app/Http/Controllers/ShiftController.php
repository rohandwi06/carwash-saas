<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function __construct(private ShiftService $shift) {}

    /**
     * GET /api/shift — status jam operasional.
     * Semua role boleh baca: kasir yang terkunci butuh tahu kapan boleh masuk.
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->shift->status()]);
    }

    /**
     * PUT /api/shift — sakelar utama + pesan layar terkunci (khusus owner).
     * Jam-jamnya sendiri diatur lewat CRUD shift di bawah.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json([
            'data' => $this->shift->updateSettings($data['enabled'], $data['message'] ?? ''),
        ]);
    }

    /** POST /api/shifts — tambah shift baru (khusus owner). */
    public function store(Request $request): JsonResponse
    {
        Shift::create($this->validated($request));

        return response()->json(['data' => $this->shift->status()], 201);
    }

    /** PATCH /api/shifts/{shift} — ubah shift (khusus owner). */
    public function updateShift(Request $request, Shift $shift): JsonResponse
    {
        $shift->update($this->validated($request, sometimes: true));

        return response()->json(['data' => $this->shift->status()]);
    }

    /** DELETE /api/shifts/{shift} — hapus shift (khusus owner). */
    public function destroy(Shift $shift): JsonResponse
    {
        $shift->delete();

        return response()->json(['data' => $this->shift->status()]);
    }

    private function validated(Request $request, bool $sometimes = false): array
    {
        $wajib = $sometimes ? 'sometimes' : 'required';

        return $request->validate([
            'name'       => [$wajib, 'string', 'max:40'],
            'start_time' => [$wajib, 'date_format:H:i'],
            // Boleh sama/lebih kecil dari jam mulai: itu shift yang
            // melewati tengah malam (mis. 20:00–02:00).
            'end_time'   => [$wajib, 'date_format:H:i', 'different:start_time'],
            'is_active'  => ['sometimes', 'boolean'],
        ]);
    }
}
