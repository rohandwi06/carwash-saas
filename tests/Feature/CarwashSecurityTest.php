<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Penjaga aturan keamanan & uang yang tidak boleh longgar.
 *
 * Sengaja login lewat /api/login yang sungguhan (bukan menerbitkan token
 * langsung lewat AuthTokenService seperti test lain): yang diuji di sini
 * justru pintu masuknya — kalau login bisa ditembus, seluruh pembatasan role
 * di bawahnya ikut tidak berarti.
 *
 * Catatan: berkas ini dulu memakai login PIN 4 digit. Loginnya sudah lama
 * diganti username/password (commit 2fc9853), tapi testnya tidak ikut
 * diperbarui sehingga 7 dari 9 test gagal dan berhenti menjaga apa pun.
 */
class CarwashSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_USER = 'owner';
    private const OWNER_PASS = 'rahasia-owner';
    private const KASIR_USER = 'kasir1';
    private const KASIR_PASS = 'rahasia-kasir';

    protected function setUp(): void
    {
        parent::setUp();

        // Password owner boleh teks polos di config — lihat
        // OwnerAccountService::passwordCocok().
        config([
            'carwash.owner.username' => self::OWNER_USER,
            'carwash.owner.password' => self::OWNER_PASS,
        ]);

        User::create([
            'name'     => 'Kasir Uji',
            'username' => self::KASIR_USER,
            'password' => Hash::make(self::KASIR_PASS),
            'role'     => 'kasir',
        ]);
    }

    private function tokenFor(string $username, string $password): string
    {
        return $this->postJson('/api/login', compact('username', 'password'))
            ->assertOk()
            ->json('data.token');
    }

    private function asKasir(): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenFor(self::KASIR_USER, self::KASIR_PASS)];
    }

    private function asOwner(): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenFor(self::OWNER_USER, self::OWNER_PASS)];
    }

    /** Katalog + harga + tarif upah, supaya angka uang di bawah punya dasar. */
    private function siapkanHarga(): void
    {
        WashCategory::firstOrCreate(['slug' => 'kecil'], ['label' => 'Mobil Kecil', 'sort_order' => 1]);
        WashCategory::firstOrCreate(['slug' => 'motor'], ['label' => 'Motor', 'sort_order' => 2]);
        WashService::firstOrCreate(['slug' => 'reguler'], ['label' => 'Cuci Reguler', 'sort_order' => 1]);

        WashPrice::updateOrCreate(
            ['category_slug' => 'kecil', 'service_slug' => 'reguler'], ['price' => 35000],
        );
        WashPrice::updateOrCreate(
            ['category_slug' => 'motor', 'service_slug' => 'reguler'], ['price' => 15000],
        );

        foreach ([['kecil', 20000], ['motor', 5000]] as [$kat, $upah]) {
            $r = WageRate::firstOrNew(['category' => $kat, 'service' => 'reguler']);
            $r->amount = $upah;
            $r->trainee_amount ??= 0;
            $r->save();
        }
    }

    /* ---------- VIEW ---------- */

    public function test_halaman_kasir_dirender_dari_blade(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('layarLogin', false)   // layar login ada
            ->assertSee('layarHome', false)    // partial layar ikut ter-include
            ->assertSee('js/kasir.js', false); // asset JS terpasang
    }

    /* ---------- AUTENTIKASI ---------- */

    public function test_endpoint_ditolak_tanpa_token(): void
    {
        $this->getJson('/api/transactions')->assertStatus(401);
        $this->postJson('/api/transactions', [])->assertStatus(401);
        $this->getJson('/api/reports/daily')->assertStatus(401);
    }

    public function test_password_salah_ditolak(): void
    {
        $this->postJson('/api/login', [
            'username' => self::KASIR_USER, 'password' => 'salah',
        ])->assertStatus(401);

        $this->postJson('/api/login', [
            'username' => self::OWNER_USER, 'password' => 'salah',
        ])->assertStatus(401);
    }

    public function test_username_tidak_dikenal_ditolak(): void
    {
        $this->postJson('/api/login', [
            'username' => 'bukan-siapa-siapa', 'password' => self::KASIR_PASS,
        ])->assertStatus(401);
    }

    public function test_login_owner_ditolak_jika_akun_belum_diatur(): void
    {
        config(['carwash.owner.username' => '', 'carwash.owner.password' => '']);

        $this->postJson('/api/login', [
            'username' => self::OWNER_USER, 'password' => self::OWNER_PASS,
        ])
            ->assertStatus(401)
            ->assertJsonFragment([
                'message' => 'Username atau password salah. Akun owner belum diatur — '
                    .'isi OWNER_USERNAME dan OWNER_PASSWORD di file .env server.',
            ]);
    }

    public function test_kasir_tidak_bisa_aksi_owner(): void
    {
        $worker = Worker::create(['name' => 'Budi', 'is_present' => true]);

        // Hapus pekerja & ubah tarif upah: dua aksi yang mengubah uang orang.
        $this->deleteJson('/api/workers/'.$worker->id, [], $this->asKasir())->assertStatus(403);
        $this->getJson('/api/users', $this->asKasir())->assertStatus(403);
        $this->getJson('/api/reports/stats', $this->asKasir())->assertStatus(403);

        $this->deleteJson('/api/workers/'.$worker->id, [], $this->asOwner())->assertOk();
        $this->getJson('/api/users', $this->asOwner())->assertOk();
        $this->getJson('/api/reports/stats', $this->asOwner())->assertOk();
    }

    /* ---------- LOGIKA UANG ---------- */

    public function test_total_dihitung_server_dan_client_tidak_bisa_menyuntik_harga(): void
    {
        $this->siapkanHarga();

        $res = $this->postJson('/api/transactions', [
            'vehicle_name'   => 'Brio',
            'category'       => 'kecil',
            'service'        => 'reguler',
            'payment_method' => 'cash',
            'total'          => 1, // dicoba disuntik dari client — harus diabaikan
        ], $this->asKasir())->assertCreated();

        $this->assertSame(35000, $res->json('data.total'));
    }

    public function test_upah_dibagi_rata_antar_pekerja(): void
    {
        $this->siapkanHarga();

        $a = Worker::create(['name' => 'A', 'is_present' => true]);
        $b = Worker::create(['name' => 'B', 'is_present' => true]);

        $this->postJson('/api/transactions', [
            'vehicle_name'   => 'Vario',
            'category'       => 'motor',
            'service'        => 'reguler',
            'payment_method' => 'cash',
            'worker_ids'     => [$a->id, $b->id],
        ], $this->asKasir())->assertCreated();

        $recap = $this->getJson('/api/reports/daily', $this->asKasir())->json('data');
        $this->assertSame(5000, $recap['wages']); // 2.500 + 2.500
    }

    /* ---------- PEMBATALAN ---------- */

    /**
     * Kasir MENGAJUKAN, tidak membatalkan. Ini yang menjaga angka harian
     * tidak bisa diubah sepihak oleh yang memegang kasir.
     */
    public function test_kasir_hanya_mengajukan_pembatalan(): void
    {
        $this->siapkanHarga();

        $trx = $this->postJson('/api/transactions', [
            'vehicle_name'   => 'Brio',
            'category'       => 'kecil',
            'service'        => 'reguler',
            'payment_method' => 'cash',
        ], $this->asKasir())->json('data');

        $this->postJson('/api/transactions/'.$trx['id'].'/void',
            ['reason' => 'salah kategori'], $this->asKasir())->assertOk();

        // Uangnya TETAP dihitung sampai owner memutuskan.
        $recap = $this->getJson('/api/reports/daily', $this->asOwner())->json('data');
        $this->assertSame(35000, $recap['total']);
        $this->assertNull(Transaction::find($trx['id'])->voided_at);

        // Menyetujui pun bukan haknya kasir.
        $this->postJson('/api/transactions/'.$trx['id'].'/void/approve', [],
            $this->asKasir())->assertStatus(403);
    }

    public function test_void_owner_mengeluarkan_transaksi_dari_rekap_dan_upah(): void
    {
        $this->siapkanHarga();

        $w = Worker::create(['name' => 'C', 'is_present' => true]);

        $trx = $this->postJson('/api/transactions', [
            'vehicle_name'   => 'Brio',
            'category'       => 'kecil',
            'service'        => 'reguler',
            'payment_method' => 'cash',
            'worker_ids'     => [$w->id],
        ], $this->asKasir())->json('data');

        $sebelum = $this->getJson('/api/reports/daily', $this->asOwner())->json('data');
        $this->assertSame(35000, $sebelum['total']);

        $this->postJson('/api/transactions/'.$trx['id'].'/void',
            ['reason' => 'salah kategori'], $this->asOwner())->assertOk();

        $sesudah = $this->getJson('/api/reports/daily', $this->asOwner())->json('data');
        $this->assertSame(0, $sesudah['total']);
        $this->assertSame(0, $sesudah['wages']);

        // Jejak audit tetap ada — barisnya ditandai, bukan dihapus.
        $this->assertDatabaseHas('transactions', [
            'id'          => $trx['id'],
            'void_reason' => 'salah kategori',
        ]);
        $this->assertNotNull(Transaction::find($trx['id'])->voided_at);
    }

    public function test_void_wajib_ada_alasan(): void
    {
        $this->siapkanHarga();

        $trx = $this->postJson('/api/transactions', [
            'vehicle_name' => 'Agya', 'category' => 'kecil',
            'service' => 'reguler', 'payment_method' => 'cash',
        ], $this->asKasir())->json('data');

        $this->postJson('/api/transactions/'.$trx['id'].'/void', [], $this->asOwner())
            ->assertStatus(422);
    }
}
