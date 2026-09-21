<?php

namespace App\Services;

use App\Models\CashBook;
use App\Models\Expense;
use App\Models\FnbSale;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Siklus hidup buku kas: dibuka -> diisi transaksi -> diajukan setoran ->
 * disetujui/ditolak owner. Menggantikan shift sebagai satuan laporan &
 * setoran — shift sendiri (jam operasional yang mengunci aplikasi kasir)
 * tetap hidup lewat ShiftService, tidak disentuh di sini.
 */
class CashBookService
{
    /**
     * Buku yang SEDANG TERBUKA untuk sebuah tanggal (hari ini bila kosong).
     * Kalau belum ada satu pun (hari baru, atau — kasus langka — semua buku
     * hari itu berakhir 'rejected' tanpa ada yang terbuka lagi), buat baru
     * secara otomatis. Sama seperti ShiftService: kasir TIDAK BOLEH pernah
     * terhalang mencatat transaksi hanya karena bukunya belum ada.
     */
    public function current(?string $date = null, ?string $by = null): CashBook
    {
        $date ??= now()->toDateString();

        return DB::transaction(function () use ($date, $by) {
            $buka = CashBook::where('date', $date)->where('status', 'open')
                ->lockForUpdate()->first();

            if ($buka) {
                return $buka;
            }

            $nomor = (int) CashBook::where('date', $date)->max('number') + 1;

            return CashBook::create([
                'date'      => $date,
                'number'    => $nomor,
                'status'    => 'open',
                'opened_at' => now(),
                'opened_by' => $by,
            ]);
        });
    }

    /**
     * Catat saldo awal (kas kecil) buku ini — biasanya diisi kasir tiap pagi
     * sebelum transaksi pertama, dari uang tunai yang sudah ada di
     * tangannya. Boleh diubah berkali-kali selama buku masih terbuka (mis.
     * salah ketik), TIDAK boleh lagi diubah setelah buku ditutup — bukunya
     * sudah jadi jejak yang dibekukan, sama seperti amount setelah setoran.
     *
     * TIDAK memengaruhi Omzet/Laba Bersih. Angka itu tetap dihitung dari
     * Total Pengeluaran (jumlah baris expenses) seperti sebelumnya — lihat
     * catatan lengkap di migrasi add_opening_balance_to_cash_books_table.
     */
    public function setOpeningBalance(CashBook $book, int $amount): CashBook
    {
        abort_unless($book->status === 'open', 422, 'Buku ini sudah tidak terbuka — saldo awal tidak bisa diubah lagi.');

        $book->update(['opening_balance' => max(0, $amount)]);

        return $book->fresh();
    }

    /**
     * Kasir menekan "Simpan & Ajukan Setoran": buku ini ditutup (status
     * pending) dengan uang cash-nya dibekukan, dan buku BARU langsung dibuka
     * untuk tanggal yang sama supaya transaksi berikutnya tetap tercatat
     * tanpa jeda — inilah "muncul buku baru" yang dimaksud.
     */
    public function requestDeposit(CashBook $book, string $by): CashBook
    {
        abort_unless($book->status === 'open', 422, 'Buku ini sudah tidak terbuka.');

        return DB::transaction(function () use ($book, $by) {
            $book->update([
                'status'       => 'pending',
                'requested_at' => now(),
                'requested_by' => $by,
                'amount'       => $this->cashAmount($book),
            ]);

            // Buku berikutnya langsung dibuka di sini, bukan menunggu
            // transaksi pertama datang lewat current() — supaya layar kasir
            // langsung menunjukkan "Buku 2 dibuka" begitu ia menekan tombol,
            // tanpa jeda menunggu cucian pertama.
            $this->current($book->date->toDateString(), $by);

            return $book->fresh();
        });
    }

    /** Owner menerima setoran: uang sudah diserahkan & dihitung cocok. */
    public function approveDeposit(CashBook $book, string $by): CashBook
    {
        abort_unless($book->status === 'pending', 422, 'Buku ini tidak sedang menunggu setoran.');

        $book->update(['status' => 'deposited', 'approved_at' => now(), 'approved_by' => $by]);

        return $book->fresh();
    }

    /**
     * Owner menolak setoran (mis. uangnya kurang, atau salah hitung).
     *
     * SENGAJA TIDAK membuka bukunya lagi secara otomatis. Buku berikutnya
     * sudah telanjur dibuka & mungkin sudah kemasukan transaksi baru sejak
     * buku ini diajukan — membuka dua buku sekaligus untuk tanggal yang sama
     * akan merusak aturan "satu buku terbuka per hari" yang dipakai di
     * seluruh sistem ini (current(), penandaan transaksi baru, dst).
     *
     * Status 'rejected' jadi status AKHIR yang butuh tindak lanjut manual:
     * owner & kasir menyelesaikannya di luar aplikasi (hitung ulang uang,
     * dsb), lalu — kalau memang ada selisih yang perlu dicatat — owner bisa
     * mencatatnya sebagai pengeluaran/penyesuaian biasa. Ini sama persis
     * dengan cara penolakan pembatalan transaksi ditangani: ditandai, tidak
     * diotak-atik lebih jauh oleh sistem.
     */
    public function rejectDeposit(CashBook $book, string $reason, string $by): CashBook
    {
        abort_unless($book->status === 'pending', 422, 'Buku ini tidak sedang menunggu setoran.');

        $book->update([
            'status' => 'rejected', 'rejected_at' => now(),
            'rejected_by' => $by, 'reject_reason' => $reason,
        ]);

        return $book->fresh();
    }

    /**
     * Uang CASH yang harus disetor dari buku ini: omzet cash (cuci + F&B)
     * dikurangi pengeluaran yang dicatat di buku ini. TF tidak dihitung —
     * uangnya sudah otomatis masuk rekening, tidak ada wujud fisik untuk
     * diserahkan ke owner.
     */
    public function cashAmount(CashBook $book): int
    {
        $cuci = Transaction::valid()->where('book_id', $book->id)
            ->where('payment_method', 'cash')->sum('total');

        // valid(), sejalan dengan baris cuci di atas. Tanpa ini penjualan F&B
        // yang sudah dibatalkan tetap dihitung sebagai uang yang wajib
        // disetor kasir — kasir diminta menyerahkan uang yang tidak pernah
        // (atau tidak jadi) ia terima.
        $fnb = FnbSale::valid()->where('book_id', $book->id)
            ->where('payment_method', 'cash')->sum('total');

        $keluar = Expense::where('book_id', $book->id)->sum('amount');

        return (int) $cuci + (int) $fnb - (int) $keluar;
    }
}
