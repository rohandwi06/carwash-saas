<?php

namespace App\Http\Controllers;

use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PricingController extends Controller
{
    public function __construct(private PricingService $pricing)
    {
    }

    /** PUT /api/pricing — owner ubah harga TOTAL satu sel (kategori x layanan) */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::exists('wash_categories', 'slug')],
            'service'  => ['required', Rule::exists('wash_services', 'slug')],
            'total'    => ['required', 'integer', 'min:0', 'max:5000000'],
        ]);

        try {
            $this->pricing->setCellPrice($data['category'], $data['service'], $data['total']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['total' => $e->getMessage()]);
        }

        return response()->json([
            'data' => [
                'categories' => $this->pricing->categories(),
                'services'   => $this->pricing->services(),
            ],
        ]);
    }
}
