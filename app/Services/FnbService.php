<?php

namespace App\Services;

use App\Models\FnbDraft;
use App\Models\FnbSale;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Penjualan makanan & minuman.
 * Harga SELALU diambil dari tabel products di server —
 * client hanya mengirim product_id dan qty.
 */
class FnbService
{
    public function __construct(private CashBookService $books) {}

    public function create(array $data): FnbSale
    {
        return DB::transaction(function () use ($data) {
            $products = Product::whereIn('id', collect($data['items'])->pluck('product_id'))
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Penitipnya ikut dimuat karena hak penitip dibekukan di baris
            // penjualan (lihat di bawah). lockForUpdate tidak bisa disertai
            // with(), jadi relasinya dimuat setelah barisnya terkunci.
            $products->loadMissing('consignor');

            $items = [];
            $total = 0;

            foreach ($data['items'] as $row) {
                $product = $products->get($row['product_id'])
                    ?? throw new InvalidArgumentException('Produk tidak ditemukan atau nonaktif.');

                $qty      = max(1, (int) $row['qty']);
                if ($product->stock < $qty) {
                    throw new InvalidArgumentException(
                        "Stok {$product->name} tidak cukup. Sisa stok: {$product->stock}."
                    );
                }

                $subtotal = $product->price * $qty;
                $total   += $subtotal;

                $items[] = [
                    // product_id dipakai untuk memulihkan stok kalau transaksi
                    // dibatalkan; product_name tetap snapshot untuk resi/laporan.
                    'product_id'   => $product->id,
                    'product_name' => $product->name,
                    'price'        => $product->price,
                    'qty'          => $qty,
                    'subtotal'     => $subtotal,
                    // Barang titipan: hak penitip DIBEKUKAN di sini. Harga
                    // setor boleh berubah besok, persentase boleh dinegosiasi
                    // ulang, penitipnya boleh dinonaktifkan — utang yang
                    // terlanjur lahir tidak ikut bergerak. Barang milik
                    // cucian sendiri mengisi null/0 dan tidak terpengaruh.
                    'consignor_id'    => $product->consignor_id,
                    'consignor_share' => $product->hakPenitip($qty),
                ];
            }

            // Kalau pesanan ini menumpang cucian, pakai buku yang SAMA dengan
            // transaksinya (dikirim TransactionService::create) — bukan
            // menurunkan buku sendiri, supaya keduanya tidak pernah tercatat
            // di buku berbeda walau kejadiannya nyaris bersamaan.
            $bookId = $data['book_id'] ?? $this->books->current(by: $data['created_by'] ?? null)->id;

            $sale = FnbSale::create([
                // Terisi bila pesanan datang dari kasir cuci (satu resi dengan cucian).
                'transaction_id' => $data['transaction_id'] ?? null,
                'payment_method' => $data['payment_method'],
                'total'          => $total,
                // Tip DI LUAR total: total adalah harga menu, itu yang jadi
                // omzet F&B. Tip punya barisnya sendiri di rekap, sama seperti
                // tip pada transaksi cuci.
                'tip'            => max(0, (int) ($data['tip'] ?? 0)),
                'date'           => now()->toDateString(),
                'book_id'        => $bookId,
                'created_by'     => $data['created_by'] ?? null,
            ]);

            $sale->items()->createMany($items);

            foreach ($data['items'] as $row) {
                $product = $products->get($row['product_id']);
                $product->decrement('stock', max(1, (int) $row['qty']));
            }

            // Draft sudah "naik kelas" jadi penjualan — hapus dari daftar tunggu.
            if (! empty($data['draft_id'])) {
                FnbDraft::whereKey($data['draft_id'])->delete();
            }

            return $sale->load('items');
        });
    }

    /**
     * Kembalikan stok dari penjualan yang dibatalkan, supaya menu yang batal
     * terjual tidak ikut hangus di layar kasir.
     *
     * Item lama yang dibuat sebelum kolom product_id ada (dan namanya tidak
     * bisa dicocokkan saat migrasi) tidak punya rujukan produk — dilewati
     * daripada menebak dan menambah stok ke produk yang salah.
     *
     * @param  iterable<FnbSale>  $sales
     */
    public function restoreStock(iterable $sales): void
    {
        foreach ($sales as $sale) {
            foreach ($sale->items as $item) {
                if ($item->product_id === null) {
                    continue;
                }

                Product::whereKey($item->product_id)->increment('stock', $item->qty);
            }
        }
    }

    /**
     * Kasir MENGAJUKAN pembatalan. Penjualan tetap dihitung di semua rekap
     * sampai owner memutuskan — jadi kasir tidak bisa mengubah angka uang
     * sendiri. Cerminan TransactionService::requestVoid().
     */
    public function requestVoid(FnbSale $sale, string $reason, ?string $by = null): FnbSale
    {
        if ($sale->voided_at !== null) {
            throw new RuntimeException('Penjualan ini sudah dibatalkan.');
        }
        if ($sale->void_status === 'menunggu') {
            throw new RuntimeException('Pembatalan penjualan ini sudah diajukan dan menunggu owner.');
        }

        $sale->update([
            'void_requested_at'   => now(),
            'void_requested_by'   => $by,
            'void_request_reason' => $reason,
            // Pengajuan ulang setelah ditolak: jejak penolakan lama dibersihkan.
            'void_rejected_at'    => null,
            'void_rejected_by'    => null,
            'void_reject_reason'  => null,
        ]);

        return $sale->load('items', 'transaction');
    }

    /**
     * Batalkan penjualan F&B: barisnya TIDAK dihapus, hanya ditandai, jadi
     * rekap uang otomatis mengabaikannya lewat FnbSale::valid() tapi jejaknya
     * tetap ada. Stok menu dikembalikan. Dipakai owner — langsung.
     */
    public function void(FnbSale $sale, string $reason, ?string $voidedBy = null): FnbSale
    {
        DB::transaction(function () use ($sale, $reason, $voidedBy) {
            // Barisnya dikunci: tanpa ini dua permintaan void yang datang
            // bersamaan bisa sama-sama lolos pengecekan dan stok dikembalikan
            // dua kali. Alasan yang sama dengan TransactionService::void().
            $terkunci = FnbSale::whereKey($sale->getKey())->lockForUpdate()->first();

            if ($terkunci === null || $terkunci->voided_at !== null) {
                return;
            }

            $terkunci->update([
                'voided_at'   => now(),
                'void_reason' => $reason,
                'voided_by'   => $voidedBy,
            ]);

            $this->restoreStock([$terkunci->load('items')]);
        });

        return $sale->refresh()->load('items', 'transaction');
    }

    /** Owner menyetujui pengajuan kasir — mulai detik ini penjualan keluar dari rekap. */
    public function approveVoid(FnbSale $sale, ?string $by = null): FnbSale
    {
        if ($sale->void_status !== 'menunggu') {
            throw new RuntimeException('Tidak ada pengajuan pembatalan yang menunggu untuk penjualan ini.');
        }

        return $this->void($sale, $sale->void_request_reason ?? 'tanpa alasan', $by);
    }

    /** Owner menolak: penjualan tetap sah, alasan penolakan disimpan sebagai jejak. */
    public function rejectVoid(FnbSale $sale, string $reason, ?string $by = null): FnbSale
    {
        if ($sale->void_status !== 'menunggu') {
            throw new RuntimeException('Tidak ada pengajuan pembatalan yang menunggu untuk penjualan ini.');
        }

        // void_requested_at sengaja TIDAK dikosongkan: jejak siapa yang
        // mengajukan dan kapan tetap berguna. Barisnya sudah keluar dari
        // antrean owner lewat scopePendingVoid (void_rejected_at terisi).
        $sale->update([
            'void_rejected_at'   => now(),
            'void_rejected_by'   => $by,
            'void_reject_reason' => $reason,
        ]);

        return $sale->load('items', 'transaction');
    }
}
