<?php

namespace App\Http\Controllers;

use App\Models\FnbSale;
use App\Models\Transaction;
use App\Services\ReceiptPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * Resi PDF untuk pelanggan. Kasir meminta tautannya (wajib login), lalu
 * tautan itu dikirim ke WhatsApp pelanggan dan dibuka TANPA login.
 *
 * Yang menjaga tautan publik itu adalah tanda tangannya: tanpa APP_KEY tidak
 * ada yang bisa membuat tautan untuk nomor nota lain, jadi resi orang lain
 * tidak bisa ditebak dengan mengganti angka di alamatnya.
 */
class ReceiptController extends Controller
{
    /** Cukup lama untuk dibuka ulang saat mengambil kendaraan atau komplain. */
    private const MASA_TAUTAN_HARI = 30;

    public function __construct(private ReceiptPdfService $resi) {}

    /** GET /api/receipts/{jenis}/{id}/link — jenis: c (cucian) atau f (F&B) */
    public function link(string $jenis, int $id): JsonResponse
    {
        $this->cari($jenis, $id);

        // Tanda tangan dihitung dari alamat TANPA nama situs, supaya tetap
        // sah walau situsnya dibuka lewat http/https atau nama yang berbeda.
        $jalur = URL::temporarySignedRoute('resi.pdf', now()->addDays(self::MASA_TAUTAN_HARI),
            ['jenis' => $jenis, 'id' => $id], absolute: false);

        return response()->json(['data' => [
            'url'   => url($jalur),
            'nomor' => $this->resi->nomorNota($jenis, $id),
        ]]);
    }

    /** GET /resi/{jenis}/{id}?expires=…&signature=… — publik, dijaga tanda tangan */
    public function pdf(string $jenis, int $id): Response
    {
        $catatan = $this->cari($jenis, $id);
        $pdf = $catatan instanceof Transaction
            ? $this->resi->untukCucian($catatan)
            : $this->resi->untukFnb($catatan);

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="resi-'.$this->resi->nomorNota($jenis, $id).'.pdf"',
            // Resi bisa berubah (dikoreksi, dibatalkan) — jangan disimpan perantara.
            'Cache-Control'       => 'private, no-store',
            'X-Robots-Tag'        => 'noindex',
        ]);
    }

    private function cari(string $jenis, int $id): Transaction|FnbSale
    {
        return match ($jenis) {
            'c'     => Transaction::findOrFail($id),
            'f'     => FnbSale::findOrFail($id),
            default => abort(404),
        };
    }
}
