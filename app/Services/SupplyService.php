<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Supply;
use App\Models\SupplyMovement;
use App\Models\SupplyUsageRule;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Gerak stok barang perlengkapan (sabun, semir, lap).
 *
 * Stok BOLEH minus. Barang dipotong otomatis tiap cucian yang sudah dibayar,
 * dan cucian tidak boleh gagal hanya karena angka sabun di sistem habis —
 * angka minus justru tanda bahwa sudah waktunya hitung sisa (opname).
 */
class SupplyService
{
    /** Penanda baris 'cuci' yang jumlahnya diubah kasir, bukan dari takaran. */
    public const DIATUR_KASIR = 'diatur kasir';

    public function __construct(private CashBookService $books) {}

    /** Kasir/owner mencatat pemakaian manual ("ambil 2 lap"). */
    public function pakai(Supply $supply, float $qty, ?string $note, ?string $by): SupplyMovement
    {
        return $this->gerak($supply, 'pakai', -$qty, ['note' => $note, 'created_by' => $by]);
    }

    /**
     * Stok masuk. Kalau harganya diisi, sekalian dicatat sebagai pengeluaran
     * hari ini di buku kas yang sedang terbuka — satu langkah, bukan dua.
     */
    public function beli(Supply $supply, float $qty, ?int $price, ?string $note, ?string $by): SupplyMovement
    {
        return DB::transaction(function () use ($supply, $qty, $price, $note, $by) {
            $expenseId = null;
            if ($price !== null && $price > 0) {
                $expenseId = Expense::create([
                    'description' => mb_substr('Beli '.$supply->name.' '.self::angka($qty).' '.$supply->unit
                        .($note ? ' ('.$note.')' : ''), 0, 160),
                    'amount'      => $price,
                    'date'        => now()->toDateString(),
                    'book_id'     => $this->books->current(by: $by)->id,
                    'created_by'  => $by,
                ])->id;
            }

            return $this->gerak($supply, 'beli', $qty, [
                'price' => $price ?: null, 'expense_id' => $expenseId,
                'note' => $note, 'created_by' => $by,
            ]);
        });
    }

    /** Opname: sisa sebenarnya diketik, selisihnya tercatat sebagai pemakaian. */
    public function hitung(Supply $supply, float $sisa, ?string $note, ?string $by): SupplyMovement
    {
        return DB::transaction(function () use ($supply, $sisa, $note, $by) {
            $s = Supply::whereKey($supply->getKey())->lockForUpdate()->firstOrFail();

            return $this->gerak($s, 'hitung', round($sisa - $s->stock, 3), ['note' => $note, 'created_by' => $by]);
        });
    }

    /**
     * Menyamakan potongan otomatis sebuah cucian dengan takarannya sekarang.
     * Dipanggil sesudah cucian dibuat, dikoreksi (jenis/layanan/add-on bisa
     * berubah), dan dibatalkan (takarannya jadi nol).
     *
     * Satu baris 'cuci' per barang per cucian, jadi koreksi cukup mengubah
     * angkanya. Saat dibatalkan, barangnya dikembalikan — KECUALI sesudah
     * cucian itu sudah ada hitung sisa: opname itu sudah mencatat sisa yang
     * sebenarnya, jadi mengembalikan lagi berarti menghitung dua kali.
     */
    public function sesuaikanCucian(Transaction $trx, ?array $diaturKasir = null): void
    {
        DB::transaction(function () use ($trx, $diaturKasir) {
            $lama = SupplyMovement::where('transaction_id', $trx->id)->where('kind', 'cuci')
                ->get()->keyBy('supply_id');

            // Jumlah yang diubah kasir di layar Kasir menang atas takaran, dan
            // tetap dipakai saat cuciannya dikoreksi owner — hanya pembatalan
            // yang mengembalikannya.
            $diatur = $lama->contains('note', self::DIATUR_KASIR);
            if ($trx->voided_at !== null) {
                $butuh = [];
            } elseif ($diaturKasir !== null) {
                $butuh = [];
                foreach ($diaturKasir as $r) {
                    if ((float) $r['qty'] > 0) {
                        $butuh[(int) $r['supply_id']] = round((float) $r['qty'], 3);
                    }
                }
                $diatur = $butuh != $this->takaran($trx);
            } elseif ($diatur) {
                return;
            } else {
                $butuh = $this->takaran($trx);
            }
            $catatan = $diatur ? self::DIATUR_KASIR : null;

            foreach (array_unique(array_merge(array_keys($butuh), $lama->keys()->all())) as $supplyId) {
                $s = Supply::whereKey($supplyId)->lockForUpdate()->first();
                if ($s === null) {
                    continue;
                }
                $baris = $lama->get($supplyId);
                $terpakai = $baris ? -$baris->qty : 0.0;
                $perlu = $butuh[$supplyId] ?? 0.0;

                if ($perlu <= 0) {
                    $sudahDihitung = $baris && SupplyMovement::where('supply_id', $supplyId)
                        ->where('kind', 'hitung')->where('id', '>', $baris->id)->exists();
                    if (! $sudahDihitung) {
                        $s->update(['stock' => round($s->stock + $terpakai, 3)]);
                    }
                    $baris?->delete();

                    continue;
                }

                $selisih = round($perlu - $terpakai, 3);
                if ($selisih == 0) {
                    if ($baris && $baris->note !== $catatan) {
                        $baris->update(['note' => $catatan]);
                    }

                    continue;
                }
                $s->update(['stock' => round($s->stock - $selisih, 3)]);

                if ($baris) {
                    $baris->update(['qty' => -$perlu, 'stock_after' => $s->stock, 'note' => $catatan]);
                } else {
                    SupplyMovement::create([
                        'supply_id'      => $s->id,
                        'kind'           => 'cuci',
                        'qty'            => -$perlu,
                        'stock_after'    => $s->stock,
                        'transaction_id' => $trx->id,
                        'note'           => $catatan,
                        'date'           => $trx->date,
                        'created_by'     => $trx->created_by,
                    ]);
                }
            }
        });
    }

    /** @return array<int, float> supply_id => jumlah terpakai untuk cucian ini */
    public function takaran(Transaction $trx): array
    {
        $addonIds = $trx->addons()->pluck('addons.id')->map(fn ($id) => (int) $id)->all();
        $hasil = [];
        foreach (SupplyUsageRule::all() as $aturan) {
            if ($aturan->cocok($trx, $addonIds)) {
                $hasil[$aturan->supply_id] = round(($hasil[$aturan->supply_id] ?? 0) + $aturan->qty, 3);
            }
        }

        return $hasil;
    }

    private function gerak(Supply $supply, string $kind, float $qty, array $isi): SupplyMovement
    {
        return DB::transaction(function () use ($supply, $kind, $qty, $isi) {
            $s = Supply::whereKey($supply->getKey())->lockForUpdate()->firstOrFail();
            $s->update(['stock' => round($s->stock + $qty, 3)]);

            return SupplyMovement::create($isi + [
                'supply_id'   => $s->id,
                'kind'        => $kind,
                'qty'         => round($qty, 3),
                'stock_after' => $s->stock,
                'date'        => now()->toDateString(),
            ]);
        });
    }

    /** 3.5 -> "3,5"; 20.0 -> "20" — untuk keterangan pengeluaran. */
    public static function angka(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, ',', '.'), '0'), ',');
    }
}
