<?php

namespace Tests\Feature;

use App\Models\TransactionDraft;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Foto kondisi di draft cucian (laporan owner 21/10: foto yang sudah diambil
 * hilang saat transaksi disimpan sebagai draft).
 *
 * Yang dijaga: foto bisa disimpan ke draft dan dibaca lagi saat draft
 * dilanjutkan, bisa dikosongkan (layar mengunggah ulang yang masih dipakai),
 * dibatasi 6, dan foldernya terhapus saat draft dihapus maupun dibayar.
 */
class DraftPhotoTest extends TestCase
{
    use RefreshDatabase;

    /** PNG 1x1 piksel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uji-foto-draft-'.uniqid();
        config(['carwash.foto_dir' => $this->folder]);
        WashCategory::query()->delete();
        WashService::query()->delete();
        WashPrice::query()->delete();
        WageRate::query()->delete();
        WashCategory::create(['slug' => 'kecil', 'label' => 'Mobil Kecil', 'sort_order' => 1]);
        WashService::create(['slug' => 'reguler', 'label' => 'Reguler', 'sort_order' => 1]);
        WashPrice::create(['category_slug' => 'kecil', 'service_slug' => 'reguler', 'price' => 30000]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->folder)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->folder, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->folder);
        }
        parent::tearDown();
    }

    private function kasir(): array
    {
        $t = app(AuthTokenService::class)->issue('kasir', 'Kasir Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function draft(): int
    {
        return $this->postJson('/api/drafts', ['vehicle_name' => 'Avanza', 'category' => 'kecil'], $this->kasir())
            ->assertCreated()->json('data.id');
    }

    private function folderDraft(int $id): string
    {
        return $this->folder.DIRECTORY_SEPARATOR.'photos'.DIRECTORY_SEPARATOR.'draft'.DIRECTORY_SEPARATOR.$id;
    }

    public function test_foto_tersimpan_di_draft_dan_bisa_dibaca_lagi(): void
    {
        $id = $this->draft();
        $nama = $this->postJson("/api/drafts/{$id}/photos", ['image' => 'data:image/png;base64,'.self::PNG], $this->kasir())
            ->assertCreated()->json('data.name');

        $this->getJson('/api/drafts', $this->kasir())->assertOk()->assertJsonPath('data.0.photos', [$nama]);

        $res = $this->get("/api/drafts/{$id}/photos/{$nama}", $this->kasir());
        $res->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertSame(base64_decode(self::PNG), $res->getContent());

        $this->get("/api/drafts/{$id}/photos/..%2F..%2F.env", $this->kasir())->assertNotFound();
        $this->get("/api/drafts/{$id}/photos/{$nama}")->assertUnauthorized();
    }

    public function test_dikosongkan_dan_dibatasi_enam(): void
    {
        $id = $this->draft();
        for ($i = 0; $i < 6; $i++) {
            $this->postJson("/api/drafts/{$id}/photos", ['image' => self::PNG], $this->kasir())->assertCreated();
        }
        $this->postJson("/api/drafts/{$id}/photos", ['image' => self::PNG], $this->kasir())
            ->assertStatus(422)->assertJsonPath('message', 'Satu cucian paling banyak 6 foto.');

        $this->deleteJson("/api/drafts/{$id}/photos", [], $this->kasir())->assertOk();
        $this->assertSame([], TransactionDraft::find($id)->photos);
    }

    public function test_folder_terhapus_saat_draft_dihapus_atau_dibayar(): void
    {
        $a = $this->draft();
        $this->postJson("/api/drafts/{$a}/photos", ['image' => self::PNG], $this->kasir())->assertCreated();
        $this->deleteJson("/api/drafts/{$a}", [], $this->kasir())->assertOk();
        $this->assertDirectoryDoesNotExist($this->folderDraft($a));

        $b = $this->draft();
        $this->postJson("/api/drafts/{$b}/photos", ['image' => self::PNG], $this->kasir())->assertCreated();
        $this->postJson('/api/transactions', [
            'vehicle_name' => 'Avanza', 'category' => 'kecil', 'payment_method' => 'cash', 'draft_id' => $b,
        ], $this->kasir())->assertCreated();
        $this->assertNull(TransactionDraft::find($b));
        $this->assertDirectoryDoesNotExist($this->folderDraft($b));
    }
}
