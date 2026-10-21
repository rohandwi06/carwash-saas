<?php

use App\Http\Controllers\AddonController;
use App\Http\Controllers\AiVehicleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessProfileController;
use App\Http\Controllers\CashBookController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\ConsignorController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FnbDraftController;
use App\Http\Controllers\FnbSaleController;
use App\Http\Controllers\FotoKendaraanController;
use App\Http\Controllers\LanggananController;
use App\Http\Controllers\OwnerAccountController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\TraineeWageController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionDraftController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\WageAdjustmentController;
use App\Http\Controllers\WageRateController;
use App\Http\Controllers\WageSharingController;
use App\Http\Controllers\WashCategoryController;
use App\Http\Controllers\SupplyController;
use App\Http\Controllers\WashServiceController;
use App\Http\Controllers\WorkerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Publik: hanya login (dibatasi 10 percobaan/menit anti tebak PIN)
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Situs demo saja: masuk tanpa password sebagai owner/kasir contoh. Di luar
// APP_ENV=demo controllernya membalas 404 (lihat AuthController::demoLogin).
Route::post('/demo-login', [AuthController::class, 'demoLogin'])->middleware('throttle:30,1');
// Buku alamat email -> server (byrapiin.my.id) memastikan email owner (19/10).
Route::get('/direktori/cek', [OwnerAccountController::class, 'cekDirektori'])->middleware('throttle:30,1');
// Pusat pendaftaran mengaktifkan langganan setelah bayar (ditandatangani HMAC).
Route::post('/langganan/perpanjang', [LanggananController::class, 'perpanjang'])->middleware('throttle:30,1');

// Nama, keterangan, alamat, telepon usaha. Publik karena layar login (web
// maupun tablet Android) sudah harus menampilkan nama cucian sebelum ada
// token - dan isinya memang dicetak di setiap resi.
Route::get('/business-profile', [BusinessProfileController::class, 'show'])->middleware('throttle:60,1');

/*
|--------------------------------------------------------------------------
| Wajib login (PIN kasir ATAU owner)
|--------------------------------------------------------------------------
*/
Route::middleware('pin.auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Status jam operasional — sengaja DI LUAR 'shift' supaya kasir yang
    // terkunci masih bisa tahu jam berapa ia boleh masuk lagi.
    Route::get('/shift', [ShiftController::class, 'show']);
    // Status langganan (pita "belum aktif" di aplikasi & web).
    Route::get('/langganan', [LanggananController::class, 'show']);

    /*
    |----------------------------------------------------------------------
    | Hanya saat toko buka (kasir). Owner selalu lolos — lihat ShiftGuard.
    |----------------------------------------------------------------------
    */
    Route::middleware('shift')->group(function () {
        // Konfigurasi (harga, layanan, tarif upah)
        Route::get('/config', [ConfigController::class, 'index']);

        // Kendaraan
        Route::get('/vehicles/search', [VehicleController::class, 'search']);
        Route::post('/vehicles/failed-search', [VehicleController::class, 'logFailedSearch']);

        // Transaksi kasir
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::post('/transactions', [TransactionController::class, 'store'])->middleware('langganan');
        Route::post('/transactions/{transaction}/void', [TransactionController::class, 'void']);

        // Buku kas — kasir menutup buku yang sedang berjalan & mengajukan
        // setoran. Persetujuan/penolakannya ada di grup owner di bawah.
        Route::post('/cash-books/{cashBook}/request-deposit', [CashBookController::class, 'requestDeposit'])->middleware('langganan');
        // Saldo kas kecil: dicatat manual tiap buku baru dibuka (biasanya
        // pagi). Murni informasi kasir, tidak menyentuh omzet/laba.
        Route::put('/cash-books/{cashBook}/opening-balance', [CashBookController::class, 'setOpeningBalance'])->middleware('langganan');

        // Draft cucian (catat kendaraan dulu, bayar belakangan)
        Route::get('/drafts', [TransactionDraftController::class, 'index']);
        Route::post('/drafts', [TransactionDraftController::class, 'store'])->middleware('langganan');
        Route::patch('/drafts/{draft}', [TransactionDraftController::class, 'update'])->middleware('langganan');
        Route::delete('/drafts/{draft}', [TransactionDraftController::class, 'destroy']);

        // Pekerja & upah. Kasir hanya MELIHAT daftarnya (untuk memilih siapa
        // yang mengerjakan cucian) dan versi yang ia terima sudah dipangkas
        // tanpa data pribadi — lihat WorkerController@index. Menambah/mengubah
        // pekerja ada di grup owner di bawah.
        Route::get('/workers', [WorkerController::class, 'index']);
        Route::get('/wage-rates', [WageRateController::class, 'index']);

        // Layanan tambahan (add-on) — kasir hanya boleh melihat
        Route::get('/addons', [AddonController::class, 'index']);

        // Makanan & minuman
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/fnb-sales', [FnbSaleController::class, 'index']);
        Route::post('/fnb-sales', [FnbSaleController::class, 'store'])->middleware('langganan');
        // Kasir mengajukan pembatalan, owner membatalkan langsung — sama
        // seperti /transactions/{id}/void. Persetujuannya di grup owner.
        Route::post('/fnb-sales/{fnbSale}/void', [FnbSaleController::class, 'void']);

        // Tautan resi PDF untuk dikirim ke WhatsApp pelanggan. Tautannya sendiri
        // dibuka tanpa login (routes/web.php) dan dijaga tanda tangan.
        Route::get('/receipts/{jenis}/{id}/link', [ReceiptController::class, 'link'])->whereNumber('id');

        // Promo pelanggan setia: status satu pelanggan (plat/telepon) di layar kasir.
        Route::get('/promo/check', [PromoController::class, 'check']);

        // Draft F&B: catat pesanan dulu, penjualannya disimpan belakangan
        Route::get('/fnb-drafts', [FnbDraftController::class, 'index']);
        Route::post('/fnb-drafts', [FnbDraftController::class, 'store'])->middleware('langganan');
        Route::patch('/fnb-drafts/{fnbDraft}', [FnbDraftController::class, 'update'])->middleware('langganan');
        Route::delete('/fnb-drafts/{fnbDraft}', [FnbDraftController::class, 'destroy']);

        // Pengeluaran — kasir boleh mencatat (belanja sabun dll), hapus/ubah owner
        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store'])->middleware('langganan');

        // Barang perlengkapan (sabun, semir, lap) — kasir boleh mencatat
        // pakai, beli, dan hitung sisa; mengatur jenis barang owner.
        Route::get('/supplies', [SupplyController::class, 'index']);
        Route::get('/supplies/{supply}/movements', [SupplyController::class, 'movements']);
        Route::post('/supplies/{supply}/use', [SupplyController::class, 'use'])->middleware('langganan');
        Route::post('/supplies/{supply}/purchase', [SupplyController::class, 'purchase'])->middleware('langganan');
        Route::post('/supplies/{supply}/count', [SupplyController::class, 'count'])->middleware('langganan');

        // AI (Gemini) — hanya untuk kendaraan yang tidak ditemukan search lokal.
        //
        // Sejak AI menyatu dengan kolom pencarian, permintaan ke sini tidak lagi
        // lahir dari kasir menekan tombol, melainkan dari kasir MENGETIK. Penahan
        // utamanya ada di kasir.js (jeda 1,2 detik, minimal 4 huruf, satu kata
        // sekali per sesi), tapi penahan di browser bisa dilewati siapa pun yang
        // memegang token. Throttle ini jaring terakhirnya: kuota Gemini pernah
        // habis dalam sehari (30 Agustus 2026), dan yang terbakar adalah uang
        // sungguhan kalau kuotanya berbayar. 20/menit masih jauh di atas
        // pemakaian wajar — kasir tercepat pun tidak mengetik 20 nama mobil
        // BARU dalam satu menit.
        Route::post('/ai/classify-vehicle', [AiVehicleController::class, 'classify'])
            ->middleware('throttle:20,1');
        // Kenali dari foto: tanpa cache (tiap foto berbeda), jadi batasnya lebih ketat.
        Route::post('/ai/classify-photo', [AiVehicleController::class, 'classifyPhoto'])
            ->middleware('throttle:10,1');

        // Foto kondisi kendaraan: diunggah sesudah transaksinya tersimpan.
        Route::post('/transactions/{transaction}/photos', [FotoKendaraanController::class, 'store'])
            ->middleware('throttle:60,1');
        Route::get('/photos/{vehiclePhoto}', [FotoKendaraanController::class, 'show']);
        Route::post('/ai/save-vehicle', [AiVehicleController::class, 'save']);

        // Laporan / pembukuan
        Route::get('/reports/daily', [ReportController::class, 'daily']);
        Route::get('/reports/calendar', [ReportController::class, 'calendar']);
        Route::get('/reports/date-range', [ReportController::class, 'dateRange']);
        Route::get('/reports/wages', [ReportController::class, 'wages']);
        Route::get('/reports/daily/csv', [ReportController::class, 'dailyCsv']);
    });

    /*
    |----------------------------------------------------------------------
    | Khusus OWNER: aksi destruktif / pengaturan bisnis
    |----------------------------------------------------------------------
    */
    Route::middleware('owner')->group(function () {
        // Statistik Dashboard: laba bersih & total upah pekerja. Layarnya
        // sudah disembunyikan dari kasir di sisi tampilan; ini penjaga
        // sebenarnya, yang tetap menolak walau menunya dipaksa muncul.
        Route::get('/reports/stats', [ReportController::class, 'stats']);

        // Sakelar utama + pesan layar terkunci
        Route::put('/shift', [ShiftController::class, 'update']);
        // Daftar shift: satu hari boleh punya berapa pun shift
        Route::post('/shifts', [ShiftController::class, 'store']);
        Route::patch('/shifts/{shift}', [ShiftController::class, 'updateShift']);
        Route::delete('/shifts/{shift}', [ShiftController::class, 'destroy']);
        // Upah karyawan training (nominal tetap per cucian)
        Route::get('/trainee-wage', [TraineeWageController::class, 'index']);
        Route::put('/trainee-wage', [TraineeWageController::class, 'update']);
        // Cara hitung upah: bagi hasil persenan atau upah per mobil
        Route::get('/wage-sharing', [WageSharingController::class, 'index']);
        Route::put('/wage-sharing', [WageSharingController::class, 'update']);

        // Pengaturan promo pelanggan setia
        Route::get('/promo', [PromoController::class, 'index']);
        Route::put('/promo', [PromoController::class, 'update']);

        // Pekerja: tambah/ubah/hapus + biodata (NIK, alamat, tanggal lahir)
        Route::post('/workers', [WorkerController::class, 'store']);
        Route::patch('/workers/{worker}', [WorkerController::class, 'update']);
        Route::delete('/workers/{worker}', [WorkerController::class, 'destroy']);
        Route::put('/wage-rates', [WageRateController::class, 'update']);
        Route::put('/pricing', [PricingController::class, 'update']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::patch('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::post('/supplies', [SupplyController::class, 'store']);
        Route::patch('/supplies/{supply}', [SupplyController::class, 'update']);
        Route::delete('/supplies/{supply}', [SupplyController::class, 'destroy']);

        // Koreksi isi transaksi yang salah input (menu Pembukuan). Owner-only
        // karena mengubah omzet & upah pekerja hari yang sudah lewat — sama
        // alasannya dengan wage-adjustments di bawah.
        Route::patch('/transactions/{transaction}', [TransactionController::class, 'update']);
        Route::get('/transactions/{transaction}/supplies', [SupplyController::class, 'forTransaction']);
        // Cucian tanggal lampau (isi banyak baris dari Pembukuan)
        Route::post('/transactions/backdated', [TransactionController::class, 'storeBackdated'])->middleware('langganan');

        // Persetujuan pembatalan transaksi yang diajukan kasir
        Route::get('/transactions/void-requests', [TransactionController::class, 'voidRequests']);
        Route::post('/transactions/{transaction}/void/approve', [TransactionController::class, 'approveVoid']);
        Route::post('/transactions/{transaction}/void/reject', [TransactionController::class, 'rejectVoid']);

        // Persetujuan pembatalan penjualan F&B yang diajukan kasir
        Route::get('/fnb-sales/void-requests', [FnbSaleController::class, 'voidRequests']);
        Route::post('/fnb-sales/{fnbSale}/void/approve', [FnbSaleController::class, 'approveVoid']);
        Route::post('/fnb-sales/{fnbSale}/void/reject', [FnbSaleController::class, 'rejectVoid']);

        // Persetujuan setoran buku kas yang diajukan kasir
        Route::get('/cash-books/pending', [CashBookController::class, 'pending']);
        Route::post('/cash-books/{cashBook}/approve', [CashBookController::class, 'approve']);
        Route::post('/cash-books/{cashBook}/reject', [CashBookController::class, 'reject']);

        // Pengeluaran: koreksi & hapus
        Route::patch('/expenses/{expense}', [ExpenseController::class, 'update']);
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);

        // Katalog cuci: jenis kendaraan & jenis layanan (tambah/ubah/hapus)
        Route::get('/wash-categories', [WashCategoryController::class, 'index']);
        Route::post('/wash-categories', [WashCategoryController::class, 'store']);
        Route::put('/wash-categories/urutan', [WashCategoryController::class, 'urutkan']);
        Route::patch('/wash-categories/{washCategory}', [WashCategoryController::class, 'update']);
        Route::delete('/wash-categories/{washCategory}', [WashCategoryController::class, 'destroy']);

        // Titip jual. Seluruhnya owner-only: kasir cuma menjual barangnya
        // lewat menu F&B biasa, tidak pernah menyentuh bagi hasil, barang
        // masuk, retur, atau setoran.
        Route::get('/consignors', [ConsignorController::class, 'index']);
        Route::get('/consignors/{consignor}', [ConsignorController::class, 'show']);
        Route::post('/consignors', [ConsignorController::class, 'store']);
        Route::patch('/consignors/{consignor}', [ConsignorController::class, 'update']);
        Route::delete('/consignors/{consignor}', [ConsignorController::class, 'destroy']);
        Route::post('/consignors/{consignor}/payouts', [ConsignorController::class, 'bayar'])->middleware('langganan');
        Route::post('/consignment-movements', [ConsignorController::class, 'gerakBarang'])->middleware('langganan');

        // Katalog KENDARAAN: mobil apa masuk jenis yang mana. Ini yang
        // dipakai pencarian kasir untuk menentukan harga, jadi hanya owner
        // yang boleh mengubahnya — kasir cuma memakai hasilnya lewat
        // /vehicles/search, dan tebakan AI yang ia simpan masuk sebagai
        // "perlu dicek" sampai dibenarkan di sini.
        Route::get('/vehicles', [VehicleController::class, 'index']);
        Route::post('/vehicles', [VehicleController::class, 'store']);
        Route::post('/vehicles/massal', [VehicleController::class, 'massal']);
        // Owner membetulkan jenis satu mobil -> AI usulkan mobil sejenis (17/10).
        Route::post('/vehicles/pindah-sejenis', [VehicleController::class, 'pindahSejenis']);
        Route::post('/vehicles/{vehicle}/sejenis', [VehicleController::class, 'sejenis']);
        Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update']);
        Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy']);
        // Ejaan kasir yang dipelajari aplikasi (VehicleMemoryService)
        Route::get('/vehicle-aliases', [VehicleController::class, 'aliases']);
        Route::delete('/photos/{vehiclePhoto}', [FotoKendaraanController::class, 'destroy']);
        Route::delete('/vehicle-aliases/{vehicleAlias}', [VehicleController::class, 'destroyAlias']);

        Route::get('/wash-services', [WashServiceController::class, 'index']);
        Route::post('/wash-services', [WashServiceController::class, 'store']);
        Route::patch('/wash-services/{washService}', [WashServiceController::class, 'update']);
        Route::delete('/wash-services/{washService}', [WashServiceController::class, 'destroy']);

        // Layanan tambahan (add-on)
        Route::post('/addons', [AddonController::class, 'store']);
        Route::patch('/addons/{addon}', [AddonController::class, 'update']);
        Route::delete('/addons/{addon}', [AddonController::class, 'destroy']);

        // Akun kasir (username/password) — hanya owner yang boleh kelola
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        // Situs demo: akun dipakai bersama semua pengunjung - lihat BukanDemo.
        Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('bukan.demo');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('bukan.demo');

        // Akun owner sendiri (ganti username/password — wajib password lama)
        Route::get('/owner-account', [OwnerAccountController::class, 'show']);
        Route::put('/owner-account', [OwnerAccountController::class, 'update'])->middleware('bukan.demo');

        // Profil usaha: nama di header/login/resi/CSV, alamat & telepon di resi
        // Situs demo: nama/alamat/telepon dilihat semua pengunjung (dan
        // peninjau Play Store) - tidak boleh diganti pengunjung, lihat BukanDemo.
        Route::put('/business-profile', [BusinessProfileController::class, 'update'])->middleware('bukan.demo');

        // Penyesuaian upah: potongan (hukuman) & timpa angka upah. Owner-only
        // karena menentukan uang yang diterima orang lain sekaligus mengubah
        // laba bersih yang dilaporkan.
        Route::get('/wage-adjustments', [WageAdjustmentController::class, 'index']);
        Route::post('/wage-adjustments', [WageAdjustmentController::class, 'store']);
        Route::post('/wage-adjustments/bulk', [WageAdjustmentController::class, 'storeMany']);
        Route::post('/wage-adjustments/bulk-delete', [WageAdjustmentController::class, 'destroyMany']);
        Route::delete('/wage-adjustments/{wageAdjustment}', [WageAdjustmentController::class, 'destroy']);

        // Kompensasi karyawan ("sangu" dari owner: uang makan, uang bensin).
        // Owner-only dengan alasan yang sama: mengubah laba bersih.
        Route::get('/worker-compensations', [\App\Http\Controllers\WorkerCompensationController::class, 'index']);
        Route::post('/worker-compensations', [\App\Http\Controllers\WorkerCompensationController::class, 'store']);
        Route::delete('/worker-compensations/{workerCompensation}', [\App\Http\Controllers\WorkerCompensationController::class, 'destroy']);

        // Aturan otomatis harian (potongan & kompensasi untuk yang kerja hari
        // itu). Owner-only: menggerakkan uang pekerja & laba tiap hari.
        Route::get('/daily-rules', [\App\Http\Controllers\DailyRuleController::class, 'index']);
        Route::post('/daily-rules', [\App\Http\Controllers\DailyRuleController::class, 'store']);
        Route::patch('/daily-rules/{dailyRule}', [\App\Http\Controllers\DailyRuleController::class, 'update']);
        Route::delete('/daily-rules/{dailyRule}', [\App\Http\Controllers\DailyRuleController::class, 'destroy']);

        // Gaji bulanan tiap pekerja (cara upah "per bulan"). Owner-only.
        Route::get('/worker-salaries', [\App\Http\Controllers\WorkerSalaryController::class, 'index']);
        Route::put('/worker-salaries', [\App\Http\Controllers\WorkerSalaryController::class, 'update']);

    });
});
