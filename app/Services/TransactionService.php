<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionDraft;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orkestrasi pembuatan transaksi:
 * nomor antrian -> harga (PricingService) -> simpan -> upah (WageService)
 * -> makanan/minuman yang dipesan bareng (FnbService).
 */
class TransactionService
{
    public function __construct(
        private PricingService $pricing,
        private WageService $wages,
        private FnbService $fnb,
        private CashBookService $books,
    ) {}

    public function create(array $data): Transaction
    {
        return DB::transaction(function () use ($data) {
            $date = now()->toDateString();
            // Buku kas yang sedang terbuka dibubuhkan SEKARANG dan disimpan;
            // laporan membaca dari sini, bukan menebak ulang buku mana yang
            // "seharusnya" berlaku (lihat CashBookService::current()).
            $book = $this->books->current($date, $data['created_by'] ?? null);

            // Add-on: harga & nama diambil dari server, lalu disalin ke pivot.
            $addons     = $this->pricing->resolveAddons($data['addon_ids'] ?? []);
            $totalCuci  = $this->pricing->total($data['category'], $data['service'] ?? 'reguler');
            $totalAddon = (int) $addons->sum('price');

            $transaction = Transaction::create([
                'queue_no'       => $this->nextQueueNo($date),
                'vehicle_name'   => $data['vehicle_name'],
                'category'       => $data['category'],
                'service'        => $data['service'] ?? 'reguler',
                'payment_method' => $data['payment_method'],
                'plate'          => $data['plate'] ?? null,
                'tip'            => $data['tip'] ?? 0,
                'total'          => $totalCuci + $totalAddon,
                'date'           => $date,
                'book_id'        => $book->id,
                'created_by'     => $data['created_by'] ?? null,
            ]);

            if ($addons->isNotEmpty()) {
                $transaction->addons()->attach(
                    $addons->mapWithKeys(fn ($a) => [
                        $a['id'] => ['name' => $a['name'], 'price' => $a['price']],
                    ])->all()
                );
            }

            $this->wages->attachWorkers($transaction, $data['worker_ids'] ?? []);

            // Makanan/minuman yang dipesan bersamaan: tetap disimpan sebagai
            // penjualan F&B (stok & laporan F&B ikut aturan yang sama),
            // hanya ditautkan ke transaksi cuci ini.
            if (! empty($data['fnb_items'])) {
                $this->fnb->create([
                    'transaction_id' => $transaction->id,
                    'payment_method' => $data['payment_method'],
                    'items'          => $data['fnb_items'],
                    'book_id'        => $book->id,
                    'created_by'     => $data['created_by'] ?? null,
                ]);
            }

            // Draft sudah "naik kelas" jadi transaksi — hapus dari daftar tunggu.
            if (! empty($data['draft_id'])) {
                TransactionDraft::whereKey($data['draft_id'])->delete();
            }

            return $transaction->load('workers', 'addons', 'fnbSales.items');
        });
    }

    /**
     * Koreksi isi transaksi yang salah input — owner saja (lihat routes/api.php).
     *
     * Yang boleh diubah hanyalah apa yang membentuk harga & upah: jenis
     * kendaraan, layanan, add-on, pekerja, tip, plat, nama, cara bayar.
     * Yang TIDAK boleh, dan alasannya:
     *
     *   - `date` & `queue_no`: nomor antrian unik per hari dan dipakai
     *     mengurutkan riwayat serta CSV. Memindahkan transaksi ke tanggal lain
     *     berarti menomori ulang hari asal DAN hari tujuan; resi lama yang
     *     sudah dicetak jadi menunjuk ke transaksi yang berbeda.
     *   - `total`: dihitung ulang di sini dari katalog harga, persis seperti
     *     create(). Tidak pernah datang dari layar — lihat StoreTransactionRequest.
     *   - `book_id`: transaksi tetap milik buku kas tempat ia dicatat. Kalau
     *     ikut pindah, uang yang sudah disetor kasir jadi tidak cocok lagi
     *     dengan buku yang menaunginya.
     *
     * Penjualan F&B yang menempel juga tidak disentuh: mengubah itemnya
     * menyentuh stok produk (harus dikembalikan lalu dipotong ulang) dan punya
     * layarnya sendiri.
     */
    public function update(Transaction $transaction, array $data, ?string $by = null): Transaction
    {
        return DB::transaction(function () use ($transaction, $data, $by) {
            // Dikunci sepanjang koreksi: tanpa ini dua owner yang mengedit
            // transaksi yang sama bersamaan sama-sama membaca total lama, dan
            // angka buku kas di bawah dihitung dari keadaan yang sudah basi.
            $trx = Transaction::whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            if ($trx->voided_at !== null) {
                throw new RuntimeException('Transaksi yang sudah dibatalkan tidak bisa dikoreksi.');
            }
            // Mengoreksi transaksi yang sedang diajukan batal membuat owner
            // memutuskan pengajuan atas angka yang bukan lagi angka yang
            // dilihat kasir waktu mengajukan. Diputuskan dulu, baru dikoreksi.
            if ($trx->void_status === 'menunggu') {
                throw new RuntimeException(
                    'Transaksi ini sedang menunggu keputusan pembatalan. Setujui atau tolak dulu pengajuannya.'
                );
            }

            $kategori = $data['category'] ?? $trx->category;
            $layanan  = $data['service']  ?? $trx->service;

            $addonIds = array_key_exists('addon_ids', $data)
                ? $data['addon_ids']
                : $trx->addons->pluck('id')->all();
            $addons = $this->pricing->resolveAddons($addonIds);

            $trx->update([
                'vehicle_name'   => $data['vehicle_name']   ?? $trx->vehicle_name,
                'category'       => $kategori,
                'service'        => $layanan,
                'payment_method' => $data['payment_method'] ?? $trx->payment_method,
                'plate'          => array_key_exists('plate', $data) ? $data['plate'] : $trx->plate,
                'tip'            => $data['tip'] ?? $trx->tip,
                'total'          => $this->pricing->total($kategori, $layanan) + (int) $addons->sum('price'),
                'edited_at'      => now(),
                'edited_by'      => $by,
                'edit_reason'    => $data['edit_reason'] ?? null,
                'edit_count'     => $trx->edit_count + 1,
            ]);

            $trx->addons()->sync(
                $addons->mapWithKeys(fn ($a) => [
                    $a['id'] => ['name' => $a['name'], 'price' => $a['price']],
                ])->all()
            );

            if (array_key_exists('worker_ids', $data)) {
                // attachWorkers() PULANG LEBIH AWAL kalau daftarnya kosong —
                // masuk akal saat membuat (belum ada yang menempel), tapi saat
                // mengoreksi itu berarti pekerja lama diam-diam tetap menempel
                // dan tetap dibayar padahal owner baru saja mengosongkannya.
                if (count($data['worker_ids']) === 0) {
                    $trx->workers()->detach();
                } else {
                    $this->wages->attachWorkers($trx, $data['worker_ids']);
                }
            }

            // Buku kas yang sudah ditutup memakai angka BEKU (lihat
            // BookkeepingService::rekapBuku). Koreksi ini sengaja ikut
            // memperbaruinya — kalau tidak, laporan harian menunjukkan angka
            // yang sudah dibetulkan sementara barisnya masih angka lama, dan
            // selisihnya tidak akan pernah bisa dijelaskan. Buku yang masih
            // terbuka tidak perlu disentuh: angkanya memang dihitung hidup.
            $buku = $trx->book;
            if ($buku !== null && $buku->status !== 'open') {
                $buku->update(['amount' => $this->books->cashAmount($buku)]);
            }

            return $trx->load('workers', 'addons', 'fnbSales.items');
        });
    }

    /**
     * Nomor urut transaksi dalam satu hari.
     * Fitur antrean sudah dilepas dari tampilan, tapi nomornya tetap diisi:
     * dipakai untuk mengurutkan riwayat & laporan CSV, dan supaya resi lama
     * tidak kehilangan artinya.
     */
    public function nextQueueNo(string $date): int
    {
        return (int) Transaction::whereDate('date', $date)->max('queue_no') + 1;
    }

    /**
     * Kasir mengajukan pembatalan. Transaksi TETAP dihitung di semua rekap
     * sampai owner memutuskan — jadi kasir tidak bisa mengubah angka sendiri.
     */
    public function requestVoid(Transaction $transaction, string $reason, ?string $by = null): Transaction
    {
        if ($transaction->voided_at !== null) {
            throw new RuntimeException('Transaksi ini sudah dibatalkan.');
        }
        if ($transaction->void_status === 'menunggu') {
            throw new RuntimeException('Pembatalan transaksi ini sudah diajukan dan menunggu owner.');
        }

        $transaction->update([
            'void_requested_at'   => now(),
            'void_requested_by'   => $by,
            'void_request_reason' => $reason,
            // Pengajuan ulang setelah ditolak: jejak penolakan lama dibersihkan.
            'void_rejected_at'    => null,
            'void_rejected_by'    => null,
            'void_reject_reason'  => null,
        ]);

        return $transaction->load('workers', 'addons');
    }

    /**
     * Batalkan (void) transaksi: baris TIDAK dihapus, hanya ditandai,
     * sehingga rekap uang & upah otomatis mengabaikannya tapi jejaknya tetap ada.
     * Dipakai owner — langsung, tanpa perlu approval siapa pun.
     */
    public function void(Transaction $transaction, string $reason, ?string $voidedBy = null): Transaction
    {
        DB::transaction(function () use ($transaction, $reason, $voidedBy) {
            // Barisnya dikunci: tanpa ini dua permintaan void yang datang
            // bersamaan bisa sama-sama lolos pengecekan dan stok F&B
            // dikembalikan dua kali.
            $terkunci = Transaction::whereKey($transaction->getKey())->lockForUpdate()->first();

            if ($terkunci === null || $terkunci->voided_at !== null) {
                return;
            }

            $terkunci->update([
                'voided_at'   => now(),
                'void_reason' => $reason,
                'voided_by'   => $voidedBy,
            ]);

            // Makanan/minuman yang ikut dibatalkan: stoknya dikembalikan.
            // Uangnya sendiri otomatis keluar dari rekap lewat FnbSale::valid().
            $this->fnb->restoreStock($terkunci->fnbSales()->with('items')->get());
        });

        return $transaction->refresh()->load('workers', 'addons');
    }

    /** Owner menyetujui pengajuan kasir — mulai detik ini transaksi keluar dari rekap. */
    public function approveVoid(Transaction $transaction, ?string $by = null): Transaction
    {
        if ($transaction->void_status !== 'menunggu') {
            throw new RuntimeException('Tidak ada pengajuan pembatalan yang menunggu untuk transaksi ini.');
        }

        return $this->void($transaction, $transaction->void_request_reason ?? 'tanpa alasan', $by);
    }

    /** Owner menolak: transaksi tetap sah, alasan penolakan disimpan sebagai jejak. */
    public function rejectVoid(Transaction $transaction, string $reason, ?string $by = null): Transaction
    {
        if ($transaction->void_status !== 'menunggu') {
            throw new RuntimeException('Tidak ada pengajuan pembatalan yang menunggu untuk transaksi ini.');
        }

        $transaction->update([
            'void_rejected_at'   => now(),
            'void_rejected_by'   => $by,
            'void_reject_reason' => $reason,
        ]);

        return $transaction->load('workers', 'addons');
    }
}
