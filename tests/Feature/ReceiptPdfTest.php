<?php

namespace Tests\Feature;

use App\Models\FnbSale;
use App\Models\Transaction;
use App\Models\WashCategory;
use App\Models\WashService;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resi PDF lewat tautan — permintaan owner 2026-10-12: resi diteruskan ke
 * WhatsApp pelanggan dalam bentuk PDF.
 *
 * Yang dijaga: tautannya hanya bisa DIBUAT oleh yang login, tetapi DIBUKA
 * tanpa login; alamat yang diubah-ubah ditolak; dan isinya resi yang benar.
 */
class ReceiptPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WashCategory::query()->delete();
        WashService::query()->delete();
        WashCategory::create(['slug' => 'ujimobil', 'label' => 'Mobil Uji', 'sort_order' => 1]);
        WashService::create(['slug' => 'reguler', 'label' => 'Cuci Reguler', 'sort_order' => 1]);
    }

    private function header(string $role = 'kasir'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function cucian(array $ubah = []): Transaction
    {
        return Transaction::create($ubah + [
            'queue_no' => 1, 'vehicle_name' => 'Avanza (hitam)', 'category' => 'ujimobil', 'service' => 'reguler',
            'payment_method' => 'qris', 'plate' => 'S 1234 AB', 'customer_name' => 'Pak Budi',
            'customer_phone' => '0812-3456-7890', 'tip' => 5000, 'total' => 35000,
            'date' => now()->toDateString(), 'created_by' => 'Andre',
        ]);
    }

    /** Alamat tanpa nama situs, seperti yang dipakai tes HTTP. */
    private function jalur(string $url): string
    {
        return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    public function test_tautan_hanya_bisa_dibuat_setelah_login(): void
    {
        $trx = $this->cucian();

        $this->getJson("/api/receipts/c/{$trx->id}/link")->assertUnauthorized();
        $data = $this->getJson("/api/receipts/c/{$trx->id}/link", $this->header())->assertOk()->json('data');

        $this->assertSame('C-'.str_pad((string) $trx->id, 5, '0', STR_PAD_LEFT), $data['nomor']);
        $this->assertStringContainsString("/resi/c/{$trx->id}?", $data['url']);
        $this->assertStringContainsString('signature=', $data['url']);
    }

    public function test_tautan_dibuka_tanpa_login_dan_berisi_resi(): void
    {
        $trx = $this->cucian();
        $url = $this->getJson("/api/receipts/c/{$trx->id}/link", $this->header())->json('data.url');

        $res = $this->get($this->jalur($url))->assertOk();
        $res->assertHeader('Content-Type', 'application/pdf');
        $pdf = $res->getContent();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        foreach (['C-'.str_pad((string) $trx->id, 5, '0', STR_PAD_LEFT), 'S 1234 AB', 'Pak Budi', '0812-3456-7890',
            'Cuci Reguler', 'Rp 35.000', 'Rp 5.000', 'Rp 40.000', 'QRIS', 'Andre', 'Mobil Uji'] as $teks) {
            $this->assertStringContainsString('('.$teks.')', $pdf, "Resi tidak memuat: $teks");
        }
        // Kurung di dalam teks harus di-escape, kalau tidak berkasnya rusak.
        $this->assertStringContainsString('(Avanza \\(hitam\\))', $pdf);
        $this->assertStringNotContainsString('DIBATALKAN', $pdf);

        // Letak tiap objek di tabel xref harus tepat — pembaca PDF yang
        // ketat menolak berkas yang tabelnya meleset.
        preg_match('/startxref\n(\d+)\n/', $pdf, $m);
        $this->assertSame('xref', substr($pdf, (int) $m[1], 4));
        preg_match_all('/^(\d{10}) 00000 n /m', $pdf, $letak);
        foreach ($letak[1] as $i => $l) {
            $this->assertStringStartsWith(($i + 1).' 0 obj', substr($pdf, (int) $l, 12));
        }
    }

    public function test_alamat_yang_diubah_ditolak(): void
    {
        $trx = $this->cucian();
        $lain = $this->cucian(['queue_no' => 2]);
        $jalur = $this->jalur($this->getJson("/api/receipts/c/{$trx->id}/link", $this->header())->json('data.url'));

        // Tanpa tanda tangan, dan tanda tangan milik nota lain.
        $this->get("/resi/c/{$trx->id}")->assertForbidden();
        $this->get(str_replace("/resi/c/{$trx->id}?", "/resi/c/{$lain->id}?", $jalur))->assertForbidden();
        $this->get(str_replace('/resi/c/', '/resi/f/', $jalur))->assertForbidden();
    }

    public function test_tautan_kedaluwarsa_ditolak(): void
    {
        $trx = $this->cucian();
        $jalur = $this->jalur($this->getJson("/api/receipts/c/{$trx->id}/link", $this->header())->json('data.url'));

        $this->travel(31)->days();
        $this->get($jalur)->assertForbidden();
    }

    public function test_resi_transaksi_batal_diberi_tanda(): void
    {
        $trx = $this->cucian(['voided_at' => now(), 'void_reason' => 'salah input']);
        $jalur = $this->jalur($this->getJson("/api/receipts/c/{$trx->id}/link", $this->header())->json('data.url'));

        $this->assertStringContainsString('DIBATALKAN', $this->get($jalur)->assertOk()->getContent());
    }

    public function test_resi_fnb_dan_nota_yang_tidak_ada(): void
    {
        $jual = FnbSale::create(['payment_method' => 'cash', 'total' => 10000, 'tip' => 0,
            'date' => now()->toDateString(), 'created_by' => 'Andre']);
        $jual->items()->create(['product_name' => 'Kopi', 'price' => 5000, 'qty' => 2, 'subtotal' => 10000]);

        $jalur = $this->jalur($this->getJson("/api/receipts/f/{$jual->id}/link", $this->header())->json('data.url'));
        $pdf = $this->get($jalur)->assertOk()->getContent();
        $this->assertStringContainsString('(Kopi x2)', $pdf);
        $this->assertStringContainsString('(F-'.str_pad((string) $jual->id, 5, '0', STR_PAD_LEFT).')', $pdf);

        $this->getJson('/api/receipts/c/99999/link', $this->header())->assertNotFound();
        $this->getJson('/api/receipts/x/1/link', $this->header())->assertNotFound();
    }
}
