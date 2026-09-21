<?php

namespace App\Services;

use App\Models\Consignor;
use App\Models\ConsignmentMovement;
use App\Models\ConsignmentPayout;
use App\Models\Expense;
use App\Models\FnbSale;
use App\Models\FnbSaleItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Titip jual — satu-satunya tempat hak penitip dihitung.
 *
 * Aturan uangnya, supaya tidak pernah ditebak-tebak lagi:
 *
 *   Barang laku Rp 7.000, hak penitip Rp 5.000
 *     -> KAS   bertambah 7.000  (pelanggan memang membayar segitu)
 *     -> LABA  bertambah 2.000  (5.000 itu uang orang, bukan pendapatan)
 *     -> UTANG ke penitip bertambah 5.000
 *
 *   Setoran ke penitip Rp 5.000
 *     -> KAS   berkurang 5.000  (lewat baris pengeluaran, supaya saldo ketemu)
 *     -> LABA  TIDAK berubah    (tidak pernah diakui sebagai laba sejak awal)
 *     -> UTANG lunas
 *
 * Utang tidak disimpan sebagai angka di kolom mana pun: ia selalu dihitung
 * ulang dari (hak penitip yang sudah terjadi - yang sudah disetor). Saldo yang
 * disimpan akan menyimpang diam-diam begitu ada penjualan yang dibatalkan.
 */
class ConsignmentService
{
    public function __construct(private CashBookService $books) {}

    /** Barang titipan datang: masuk rak & stok bertambah. */
    public function terima(Product $product, int $qty, ?string $note = null, ?string $by = null): ConsignmentMovement
    {
        return $this->gerak($product, 'masuk', $qty, $note, $by);
    }

    /** Penitip mengambil kembali barang yang belum laku. */
    public function retur(Product $product, int $qty, ?string $note = null, ?string $by = null): ConsignmentMovement
    {
        if ($qty > $product->stock) {
            throw new InvalidArgumentException(
                "Sisa {$product->name} cuma {$product->stock}, tidak bisa diretur {$qty}."
            );
        }

        return $this->gerak($product, 'retur', $qty, $note, $by);
    }

    private function gerak(Product $product, string $type, int $qty, ?string $note, ?string $by): ConsignmentMovement
    {
        if (! $product->isTitipan()) {
            throw new InvalidArgumentException("{$product->name} bukan barang titipan.");
        }

        if ($qty < 1) {
            throw new InvalidArgumentException('Jumlah barang minimal 1.');
        }

        return DB::transaction(function () use ($product, $type, $qty, $note, $by) {
            $gerakan = ConsignmentMovement::create([
                'consignor_id' => $product->consignor_id,
                'product_id'   => $product->id,
                'type'         => $type,
                'qty'          => $qty,
                'date'         => now()->toDateString(),
                'note'         => $note,
                'created_by'   => $by,
            ]);

            $type === 'masuk'
                ? $product->increment('stock', $qty)
                : $product->decrement('stock', $qty);

            return $gerakan;
        });
    }

    /**
     * Hak penitip dari seluruh penjualan yang SAH (belum dibatalkan).
     * Dipakai dua-duanya: saldo utang & pemotong laba harian.
     */
    public function hakTerjual(?int $consignorId = null, ?string $from = null, ?string $to = null): int
    {
        return (int) FnbSaleItem::query()
            ->whereNotNull('fnb_sale_items.consignor_id')
            ->when($consignorId, fn ($q) => $q->where('fnb_sale_items.consignor_id', $consignorId))
            ->whereIn('fnb_sale_id', FnbSale::valid()
                ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
                ->select('id'))
            ->sum('consignor_share');
    }

    /**
     * Hak penitip DIKELOMPOKKAN per tanggal — dipakai laporan rentang &
     * kalender, supaya tidak memanggil hakTerjual() sekali per hari.
     *
     * @return array<string,int>  ['2026-09-18' => 15000, ...]
     */
    public function hakTerjualPerTanggal(string $from, string $to): array
    {
        return FnbSaleItem::query()
            ->join('fnb_sales', 'fnb_sales.id', '=', 'fnb_sale_items.fnb_sale_id')
            ->whereNotNull('fnb_sale_items.consignor_id')
            ->whereNull('fnb_sales.voided_at')
            ->whereBetween('fnb_sales.date', [$from, $to])
            ->selectRaw('fnb_sales.date as tgl, SUM(fnb_sale_items.consignor_share) as hak')
            ->groupBy('fnb_sales.date')
            ->pluck('hak', 'tgl')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** Sudah disetorkan ke penitip. */
    public function sudahDisetor(?int $consignorId = null, ?string $from = null, ?string $to = null): int
    {
        return (int) ConsignmentPayout::query()
            ->when($consignorId, fn ($q) => $q->where('consignor_id', $consignorId))
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->sum('amount');
    }

    /** Utang cucian ke satu penitip: yang laku, dikurangi yang sudah dibayar. */
    public function utang(Consignor $consignor): int
    {
        return $this->hakTerjual($consignor->id) - $this->sudahDisetor($consignor->id);
    }

    /**
     * Setor uang ke penitip. Sekaligus melahirkan baris pengeluaran supaya
     * saldo buku kas tetap ketemu dengan uang fisik di laci — baris itu
     * ditandai is_consignment agar tidak ikut memotong laba dua kali.
     */
    public function bayar(Consignor $consignor, int $amount, ?string $note = null, ?string $by = null): ConsignmentPayout
    {
        $utang = $this->utang($consignor);

        if ($amount < 1) {
            throw new InvalidArgumentException('Jumlah setoran minimal Rp 1.');
        }

        if ($amount > $utang) {
            throw new InvalidArgumentException(
                'Setoran melebihi utang. Utang ke '.$consignor->name.' sekarang Rp '.number_format($utang, 0, ',', '.').'.'
            );
        }

        return DB::transaction(function () use ($consignor, $amount, $note, $by) {
            $expense = Expense::create([
                'description'    => 'Setoran titipan — '.$consignor->name,
                'amount'         => $amount,
                'is_consignment' => true,
                'date'           => now()->toDateString(),
                'book_id'        => $this->books->current(by: $by)->id,
                'created_by'     => $by,
            ]);

            return ConsignmentPayout::create([
                'consignor_id' => $consignor->id,
                'amount'       => $amount,
                'date'         => now()->toDateString(),
                'note'         => $note,
                'created_by'   => $by,
                'expense_id'   => $expense->id,
            ]);
        });
    }

    /**
     * Rekap satu penitip untuk dicocokkan bersama waktu setoran: per barang,
     * berapa yang dititipkan, berapa laku, berapa diambil lagi, sisa berapa.
     *
     * "Sisa seharusnya" dihitung dari riwayat (masuk - laku - retur), bukan
     * dibaca dari products.stock. Keduanya memang harus sama — dan kalau
     * berbeda, itu justru yang ingin dilihat owner, bukan yang disembunyikan.
     */
    public function rekap(Consignor $consignor): array
    {
        $produk = Product::where('consignor_id', $consignor->id)->orderBy('name')->get();

        $masuk = ConsignmentMovement::where('consignor_id', $consignor->id)
            ->selectRaw('product_id, type, SUM(qty) as n')
            ->groupBy('product_id', 'type')
            ->get();

        $laku = FnbSaleItem::query()
            ->where('fnb_sale_items.consignor_id', $consignor->id)
            ->whereIn('fnb_sale_id', FnbSale::valid()->select('id'))
            ->selectRaw('product_id, SUM(qty) as n, SUM(consignor_share) as hak')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $baris = $produk->map(function (Product $p) use ($masuk, $laku) {
            $m = (int) $masuk->where('product_id', $p->id)->where('type', 'masuk')->sum('n');
            $r = (int) $masuk->where('product_id', $p->id)->where('type', 'retur')->sum('n');
            $t = $laku->get($p->id);

            $terjual = (int) ($t->n ?? 0);

            return [
                'product_id'     => $p->id,
                'name'           => $p->name,
                'price'          => (int) $p->price,
                'payout_price'   => $p->payout_price === null ? null : (int) $p->payout_price,
                'masuk'          => $m,
                'terjual'        => $terjual,
                'retur'          => $r,
                'sisa_seharusnya' => $m - $terjual - $r,
                'sisa_di_rak'    => (int) $p->stock,
                'hak_penitip'    => (int) ($t->hak ?? 0),
            ];
        })->values()->all();

        $hak    = $this->hakTerjual($consignor->id);
        $setor  = $this->sudahDisetor($consignor->id);

        return [
            'consignor'    => $consignor,
            'items'        => $baris,
            'hak_penitip'  => $hak,
            'sudah_setor'  => $setor,
            'utang'        => $hak - $setor,
            // Jatah cucian dari barang yang sudah laku — angka yang benar-benar
            // masuk laba, bukan omzet kotornya.
            'bagian_cucian' => $this->omzetTitipan($consignor->id) - $hak,
        ];
    }

    /** Harga jual (kotor) seluruh titipan yang laku — pembanding hak penitip. */
    public function omzetTitipan(?int $consignorId = null, ?string $from = null, ?string $to = null): int
    {
        return (int) FnbSaleItem::query()
            ->whereNotNull('fnb_sale_items.consignor_id')
            ->when($consignorId, fn ($q) => $q->where('fnb_sale_items.consignor_id', $consignorId))
            ->whereIn('fnb_sale_id', FnbSale::valid()
                ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
                ->select('id'))
            ->sum('subtotal');
    }
}
