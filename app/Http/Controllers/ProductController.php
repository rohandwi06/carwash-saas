<?php

namespace App\Http\Controllers;

use App\Models\Consignor;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    /** GET /api/products (semua, untuk owner) | ?active=1 (untuk kasir) */
    public function index(Request $request): JsonResponse
    {
        // Penitipnya ikut dikirim: layar jual mewarnai tombol barang titipan
        // beda, dan layar Pengaturan menampilkan nama pemiliknya.
        $query = Product::with('consignor:id,name,share_mode,share_percent')
            ->orderBy('type')->orderBy('name');

        if ($request->boolean('active')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->get()]);
    }

    /** POST /api/products */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:80'],
            'type'         => ['required', Rule::in(['makanan', 'minuman'])],
            'price'        => ['required', 'integer', 'min:100', 'max:10000000'],
            'stock'        => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'consignor_id' => ['nullable', 'integer', 'exists:consignors,id'],
            'payout_price' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ]);

        $this->periksaTitipan($data);

        // Barang titipan SELALU lahir dengan stok 0: jumlahnya masuk lewat
        // "barang masuk" supaya ada riwayat yang bisa dicocokkan dengan
        // penitipnya. Tanpa aturan ini, rekap masuk/laku/retur/sisa bocor
        // sejak baris pertama.
        $data['stock'] = empty($data['consignor_id']) ? ($data['stock'] ?? 0) : 0;

        return response()->json(['data' => Product::create($data)], 201);
    }

    /** PATCH /api/products/{product} — ubah nama / kategori / harga / stok / toggle aktif */
    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['sometimes', 'string', 'max:80'],
            'type'         => ['sometimes', Rule::in(['makanan', 'minuman'])],
            'price'        => ['sometimes', 'integer', 'min:100', 'max:10000000'],
            'stock'        => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'is_active'    => ['sometimes', 'boolean'],
            'consignor_id' => ['sometimes', 'nullable', 'integer', 'exists:consignors,id'],
            'payout_price' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
        ]);

        $gabungan = $data + [
            'consignor_id' => $product->consignor_id,
            'payout_price' => $product->payout_price,
            'price'        => $product->price,
        ];
        $this->periksaTitipan($gabungan);

        // Stok barang titipan hanya boleh bergerak lewat barang masuk / retur,
        // supaya "masuk - laku - retur = sisa" selalu bisa dipertanggungjawabkan
        // ke penitipnya. Mengetik ulang angka stok memutus rantai itu diam-diam.
        if (array_key_exists('stock', $data) && $gabungan['consignor_id'] !== null) {
            throw ValidationException::withMessages(['stock' =>
                'Stok barang titipan diubah lewat "Barang masuk" atau "Retur", bukan diketik langsung.']);
        }

        $product->update($data);

        return response()->json(['data' => $product]);
    }

    /**
     * Aturan yang berlaku di dua jalur (tambah & ubah), jadi hidup di satu
     * tempat: barang titipan mode 'setor' WAJIB punya harga setor, dan harga
     * setor tidak boleh melebihi harga jual — kalau tidak, tiap barang laku
     * justru membuat cucian nombok.
     */
    private function periksaTitipan(array $data): void
    {
        if (empty($data['consignor_id'])) {
            return;
        }

        $penitip = Consignor::find($data['consignor_id']);

        if ($penitip === null || $penitip->share_mode !== 'setor') {
            return;   // mode persen: hak penitip dihitung dari harga jual
        }

        if (($data['payout_price'] ?? null) === null) {
            throw ValidationException::withMessages(['payout_price' =>
                'Isi harga setor: berapa yang jadi hak '.$penitip->name.' tiap barang laku.']);
        }

        if ((int) $data['payout_price'] > (int) $data['price']) {
            throw ValidationException::withMessages(['payout_price' =>
                'Harga setor (Rp '.number_format($data['payout_price'], 0, ',', '.').') lebih tinggi '
                .'daripada harga jual (Rp '.number_format($data['price'], 0, ',', '.').'). '
                .'Tiap barang laku, cucian malah rugi.']);
        }
    }

    /** DELETE /api/products/{product} */
    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['data' => null]);
    }
}
