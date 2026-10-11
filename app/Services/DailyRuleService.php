<?php

namespace App\Services;

use App\Models\DailyRule;
use App\Models\DailyRuleSkip;
use App\Models\Transaction;
use App\Models\WageAdjustment;
use App\Models\WorkerCompensation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Aturan otomatis harian — potongan upah & kompensasi yang dicatat sendiri
 * tiap hari untuk pekerja yang mengerjakan cucian hari itu (keputusan owner
 * 2026-10-11: yang tidak masuk tidak kena, dan aturan jalan sampai dimatikan).
 *
 * Tidak ada cron: hosting klien belum tentu punya. Catatannya dibuat lewat
 * sinkron(), yang dipanggil TransactionService setiap kali cucian dicatat,
 * dikoreksi, atau dibatalkan — satu-satunya saat "siapa yang kerja hari itu"
 * bisa berubah.
 *
 * Hasilnya baris BIASA di wage_adjustments / worker_compensations (ditandai
 * rule_id), jadi semua laporan membacanya tanpa perlu tahu soal aturan.
 */
class DailyRuleService
{
    /** Nama pencatat pada baris yang dibuat aturan — tampil di kartu upah. */
    public const PENCATAT = 'Otomatis';

    /**
     * Samakan catatan otomatis tanggal $date dengan keadaan sebenarnya:
     * pekerja yang punya cucian SAH hari itu mendapat catatan dari tiap aturan
     * aktif yang menyasar dirinya; pekerja yang tidak (lagi) punya cucian
     * kehilangan catatan otomatisnya.
     *
     * Aman dipanggil berulang: yang sudah ada tidak dibuat dua kali, dan yang
     * dihapus owner (daily_rule_skips) tidak dibuat lagi. Catatan MANUAL
     * (rule_id kosong) tidak pernah disentuh.
     */
    public function sinkron(string $date): void
    {
        DB::transaction(function () use ($date) {
            $kerja = $this->pekerjaKerja($date);

            // Cucian satu-satunya dibatalkan, atau pekerjanya dicopot lewat
            // koreksi: ia tidak kerja hari itu, jadi catatan otomatisnya ditarik.
            foreach ([WageAdjustment::class, WorkerCompensation::class] as $model) {
                $model::whereNotNull('rule_id')->whereDate('date', $date)
                    ->whereNotIn('worker_id', array_keys($kerja))
                    ->delete();
            }

            if ($kerja === []) {
                return;
            }

            $aturan = DailyRule::where('is_active', true)
                ->whereDate('start_date', '<=', $date)
                ->orderBy('id')->get();

            if ($aturan->isEmpty()) {
                return;
            }

            $dilewati = DailyRuleSkip::whereIn('rule_id', $aturan->pluck('id'))
                ->whereDate('date', $date)->get()
                ->mapWithKeys(fn (DailyRuleSkip $s) => [$s->rule_id.':'.$s->worker_id => true]);

            foreach ($aturan as $a) {
                $sudah = $this->modelUntuk($a)::where('rule_id', $a->id)
                    ->whereDate('date', $date)->pluck('worker_id')->flip();

                $sasaran = $a->worker_id === null
                    ? array_keys($kerja)
                    : (isset($kerja[$a->worker_id]) ? [$a->worker_id] : []);

                foreach ($sasaran as $id) {
                    if ($sudah->has($id) || $dilewati->has($a->id.':'.$id)) {
                        continue;
                    }
                    $this->catat($a, $id, $kerja[$id], $date);
                }
            }
        });
    }

    /** Aturan baru: berlaku mulai hari ini, termasuk untuk yang sudah kerja hari ini. */
    public function buat(array $data, ?string $oleh): DailyRule
    {
        $aturan = DailyRule::create([
            'type'       => $data['type'],
            'worker_id'  => $data['worker_id'] ?? null,
            'amount'     => $data['amount'],
            'reason'     => $data['reason'],
            'is_active'  => true,
            'start_date' => now()->toDateString(),
            'created_by' => $oleh,
        ]);

        $this->sinkron(now()->toDateString());

        return $aturan->load('worker:id,name');
    }

    /**
     * Ubah / nyalakan / matikan. Berlaku MULAI HARI INI: catatan otomatis hari
     * ini milik aturan ini ditarik lalu dibuat ulang menurut isi barunya;
     * hari-hari sebelumnya tidak disentuh.
     *
     * Menyalakan kembali menggeser start_date ke hari ini, supaya hari-hari
     * selama aturan mati tidak ikut terisi kalau cucian lamanya dikoreksi.
     */
    public function ubah(DailyRule $aturan, array $data): DailyRule
    {
        return DB::transaction(function () use ($aturan, $data) {
            $dinyalakan = array_key_exists('is_active', $data) && $data['is_active'] && ! $aturan->is_active;

            $aturan->fill(array_intersect_key($data, array_flip(['worker_id', 'amount', 'reason', 'is_active'])));
            if ($dinyalakan) {
                $aturan->start_date = now()->toDateString();
            }
            $aturan->save();

            $this->tarikHariIni($aturan);
            $this->sinkron(now()->toDateString());

            return $aturan->load('worker:id,name');
        });
    }

    /**
     * Hapus aturan. Catatan hari ini ditarik; catatan hari-hari sebelumnya
     * TETAP ada (uangnya memang sudah dipotong / diberikan), hanya penanda
     * asalnya yang dilepas.
     */
    public function hapus(DailyRule $aturan): void
    {
        DB::transaction(function () use ($aturan) {
            $this->tarikHariIni($aturan);

            $this->modelUntuk($aturan)::where('rule_id', $aturan->id)->update(['rule_id' => null]);
            DailyRuleSkip::where('rule_id', $aturan->id)->delete();

            $aturan->delete();
        });
    }

    /**
     * Owner menghapus sebuah catatan otomatis: catat supaya cucian berikutnya
     * pada tanggal itu tidak membuatnya lagi. Dipanggil controller SEBELUM
     * barisnya dihapus; baris manual (tanpa rule_id) dilewati.
     */
    public function tandaiDilewati(WageAdjustment|WorkerCompensation $entri): void
    {
        if ($entri->rule_id === null || $entri->worker_id === null) {
            return;
        }

        DailyRuleSkip::create([
            'rule_id'   => $entri->rule_id,
            'worker_id' => $entri->worker_id,
            'date'      => $entri->date->toDateString(),
        ]);
    }

    /**
     * Pekerja yang punya cucian sah pada tanggal itu.
     * book_id diambil dari cucian PERTAMA-nya hari itu: potongan otomatis
     * ditempelkan ke buku kas yang sama, supaya upah per buku dan upah sehari
     * tetap berjumlah sama (lihat WageService::penyesuaianRentang).
     *
     * @return array<int,array{name:string,book_id:?int}>
     */
    private function pekerjaKerja(string $date): array
    {
        $kerja = [];

        Transaction::valid()->whereDate('date', $date)->with('workers')
            ->orderBy('created_at')->orderBy('id')->get()
            ->each(function (Transaction $t) use (&$kerja) {
                foreach ($t->workers as $w) {
                    $kerja[$w->id] ??= ['name' => $w->name, 'book_id' => $t->book_id];
                }
            });

        return $kerja;
    }

    /** @param array{name:string,book_id:?int} $pekerja */
    private function catat(DailyRule $aturan, int $workerId, array $pekerja, string $date): void
    {
        $isi = [
            'worker_id'   => $workerId,
            'worker_name' => $pekerja['name'],
            'amount'      => $aturan->amount,
            'reason'      => $aturan->reason,
            'date'        => $date,
            'created_by'  => self::PENCATAT,
            'rule_id'     => $aturan->id,
        ];

        try {
            $aturan->type === DailyRule::KOMPENSASI
                ? WorkerCompensation::create($isi)
                : WageAdjustment::create($isi + [
                    'type'    => WageAdjustment::POTONGAN,
                    'book_id' => $pekerja['book_id'],
                ]);
        } catch (UniqueConstraintViolationException) {
            // Dua cucian disimpan bersamaan dan yang satunya sudah lebih dulu
            // mencatat — memang itu gunanya indeks uniknya.
        }
    }

    /** Catatan otomatis HARI INI milik satu aturan ditarik (bukan dilewati). */
    private function tarikHariIni(DailyRule $aturan): void
    {
        $this->modelUntuk($aturan)::where('rule_id', $aturan->id)
            ->whereDate('date', now()->toDateString())->delete();
    }

    /** @return class-string<Model> */
    private function modelUntuk(DailyRule $aturan): string
    {
        return $aturan->type === DailyRule::KOMPENSASI ? WorkerCompensation::class : WageAdjustment::class;
    }
}
