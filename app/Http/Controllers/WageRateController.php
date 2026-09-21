<?php

namespace App\Http\Controllers;

use App\Models\WageRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WageRateController extends Controller
{
    /** GET /api/wage-rates */
    public function index(): JsonResponse
    {
        return response()->json(['data' => WageRate::all()]);
    }

    /** PUT /api/wage-rates — set tarif upah per kategori + layanan */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::exists('wash_categories', 'slug')],
            'service'  => ['required', Rule::exists('wash_services', 'slug')],
            'amount'   => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $rate = WageRate::updateOrCreate(
            ['category' => $data['category'], 'service' => $data['service']],
            ['amount' => $data['amount']],
        );

        return response()->json(['data' => $rate]);
    }
}
