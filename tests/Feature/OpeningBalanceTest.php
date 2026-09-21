<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Services\AuthTokenService;
use App\Services\BookkeepingService;
use App\Services\CashBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saldo kas kecil (opening_balance) per buku kas.
 *
 * Yang paling penting dijaga di sini: fitur ini MURNI informasi tambahan
 * untuk kasir mengecek uang di tangannya — Omzet/Laba Bersih tidak boleh
 * berubah SAMA SEKALI oleh saldo ini, berapa pun nilainya. Kalau sampai
 * ikut mengubah laba, berarti pengeluaran terhitung dua kali (sekali dari
 * daftar expenses seperti biasa, sekali lagi lewat saldo).
 */
class OpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $role = 'kasir'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function rekap(): array
    {
        return app(BookkeepingService::class)->dailyRecap(now()->toDateString());
    }

    public function test_saldo_awal_tersimpan_dan_terbaca_di_rekap(): void
    {
        $book = app(CashBookService::class)->current();

        $this->putJson('/api/cash-books/'.$book->id.'/opening-balance',
            ['amount' => 100000], $this->header())->assertOk();

        $baris = collect($this->rekap()['books'])->firstWhere('id', $book->id);
        $this->assertSame(100000, $baris['opening_balance']);
        $this->assertSame(100000, $baris['remaining_balance'], 'Belum ada pengeluaran, sisa = saldo awal.');
    }

    public function test_sisa_saldo_berkurang_sesuai_pengeluaran(): void
    {
        $book = app(CashBookService::class)->current();
        app(CashBookService::class)->setOpeningBalance($book, 100000);

        Expense::create([
            'description' => 'Beli sabun', 'amount' => 30000,
            'date' => now()->toDateString(), 'book_id' => $book->id,
        ]);

        $baris = collect($this->rekap()['books'])->firstWhere('id', $book->id);
        $this->assertSame(70000, $baris['remaining_balance']);
    }

    /**
     * Inti dari fitur ini: berapa pun saldo awal diisi, Omzet & Laba Bersih
     * TIDAK BERUBAH. Yang memotong laba tetap Total Pengeluaran (jumlah
     * baris expenses) seperti sebelum fitur ini ada.
     */
    public function test_saldo_awal_tidak_mengubah_omzet_dan_laba(): void
    {
        $book = app(CashBookService::class)->current();

        Expense::create([
            'description' => 'Beli sabun', 'amount' => 20000,
            'date' => now()->toDateString(), 'book_id' => $book->id,
        ]);

        $sebelum = $this->rekap();

        app(CashBookService::class)->setOpeningBalance($book, 500000);

        $sesudah = $this->rekap();

        $this->assertSame($sebelum['expenses'], $sesudah['expenses']);
        $this->assertSame($sebelum['profit'], $sesudah['profit']);
        $baris = collect($sesudah['books'])->firstWhere('id', $book->id);
        $this->assertSame($sebelum['books'][0]['omzet'], $baris['omzet']);
    }

    public function test_saldo_awal_null_sebelum_diisi(): void
    {
        $book = app(CashBookService::class)->current();

        $baris = collect($this->rekap()['books'])->firstWhere('id', $book->id);
        $this->assertNull($baris['opening_balance'], 'Belum diisi harus null, bukan 0.');
        $this->assertNull($baris['remaining_balance']);
    }

    public function test_boleh_diubah_berkali_kali_selama_masih_terbuka(): void
    {
        $book = app(CashBookService::class)->current();
        $h = $this->header();

        $this->putJson('/api/cash-books/'.$book->id.'/opening-balance', ['amount' => 100000], $h)->assertOk();
        $this->putJson('/api/cash-books/'.$book->id.'/opening-balance', ['amount' => 150000], $h)->assertOk();

        $this->assertSame(150000, $book->fresh()->opening_balance);
    }

    public function test_tidak_bisa_diubah_setelah_buku_ditutup(): void
    {
        $book = app(CashBookService::class)->current();
        app(CashBookService::class)->requestDeposit($book, 'Kasir Uji');

        $this->putJson('/api/cash-books/'.$book->id.'/opening-balance',
            ['amount' => 100000], $this->header())->assertStatus(422);
    }

    public function test_nominal_negatif_ditolak(): void
    {
        $book = app(CashBookService::class)->current();

        $this->putJson('/api/cash-books/'.$book->id.'/opening-balance',
            ['amount' => -5000], $this->header())->assertStatus(422);
    }

    public function test_kasir_boleh_mengisi_bukan_hanya_owner(): void
    {
        $book = app(CashBookService::class)->current();

        $this->putJson('/api/cash-books/'.$book->id.'/opening-balance',
            ['amount' => 50000], $this->header('kasir'))->assertOk();
    }
}
