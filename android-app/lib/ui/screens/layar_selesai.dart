import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/cetak.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../data/models/transaksi.dart';
import '../widgets/kartu.dart';
import 'layar_kasir.dart';

/// Layar setelah transaksi tersimpan: struk di layar + tombol cetak.
/// Padanan `layarDone` + `isiResi()` di kasir.js.
///
/// Seluruh angka di sini datang dari JAWABAN server, bukan dari keranjang yang
/// tadi disusun kasir. Kalau server menghitung berbeda (mis. harga baru saja
/// diubah owner), yang tercetak adalah yang benar-benar tersimpan — bukan yang
/// sempat tampil di layar konfirmasi.
class LayarSelesai extends ConsumerStatefulWidget {
  const LayarSelesai({super.key, required this.transaksi});

  final Transaksi transaksi;

  @override
  ConsumerState<LayarSelesai> createState() => _LayarSelesaiState();
}

class _LayarSelesaiState extends ConsumerState<LayarSelesai> {
  bool _mencetak = false;

  Future<void> _cetak() async {
    setState(() => _mencetak = true);
    final hasil = await Pencetak.cetakStruk(widget.transaksi);
    if (!mounted) return;
    setState(() => _mencetak = false);

    if (hasil.berhasil) {
      pesanSukses(context, 'Struk tercetak');
    } else {
      pesanGagal(context, hasil.pesan ?? 'Gagal mencetak');
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = widget.transaksi;

    return PopScope(
      // Tombol kembali tidak boleh mengantar kasir balik ke layar konfirmasi:
      // transaksinya sudah tersimpan, dan menekan Bayar lagi di sana akan
      // membuat transaksi kedua untuk mobil yang sama.
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) Navigator.pop(context);
      },
      child: Scaffold(
        appBar: AppBar(
          backgroundColor: Warna.go,
          foregroundColor: Colors.white,
          automaticallyImplyLeading: false,
          title: const Text(
            'Transaksi tersimpan',
            style: TextStyle(fontWeight: FontWeight.w900),
          ),
        ),
        body: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 520),
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 20, 16, 20),
              children: [
                const Icon(
                  Icons.check_circle,
                  size: 64,
                  color: Warna.go,
                ),
                const SizedBox(height: 12),
                Text(
                  'Antrian #${t.noAntrian}',
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    fontSize: 26,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 20),
                _struk(t),
              ],
            ),
          ),
        ),
        bottomNavigationBar: Container(
          decoration: const BoxDecoration(
            color: Warna.card,
            border: Border(top: BorderSide(color: Warna.line, width: 2)),
          ),
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
          child: SafeArea(
            top: false,
            child: Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _mencetak ? null : _cetak,
                    icon: _mencetak
                        ? const SizedBox(
                            height: 18,
                            width: 18,
                            child: CircularProgressIndicator(strokeWidth: 2.5),
                          )
                        : const Icon(Icons.print_outlined),
                    label: Text(_mencetak ? 'Mencetak...' : 'Cetak struk'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: FilledButton.icon(
                    onPressed: () => Navigator.pop(context),
                    icon: const Icon(Icons.add),
                    label: const Text('Cucian berikutnya'),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _struk(Transaksi t) {
    final waktu = t.dibuatPada ?? DateTime.now();
    final fnb = t.itemFnb;

    return Kartu(
      child: Column(
        children: [
          const Text(
            'OTIN CARWASH',
            style: TextStyle(
              fontSize: 20,
              fontWeight: FontWeight.w900,
              letterSpacing: 1,
            ),
          ),
          const Text('Cuci Mobil & Motor', style: Teks.kecil),
          const _Garis(),
          BarisNilai(label: tglPanjang(waktu), nilai: jam(waktu)),
          const _Garis(),
          BarisNilai(label: 'Kendaraan', nilai: t.namaKendaraan),
          BarisNilai(label: 'Plat', nilai: t.plat ?? '-'),
          BarisNilai(
            label: 'Bayar',
            nilai: t.caraBayar == 'cash' ? 'CASH' : 'TRANSFER',
          ),
          if (t.pekerja.isNotEmpty)
            BarisNilai(
              label: 'Pekerja',
              nilai: t.pekerja.map((p) => p.nama).join(' + '),
            ),
          if (t.addons.isNotEmpty) ...[
            const _Garis(),
            for (final a in t.addons)
              BarisNilai(label: '+ ${a.nama}', nilai: rp(a.harga)),
          ],
          if (fnb.isNotEmpty) ...[
            const _Garis(),
            for (final i in fnb)
              BarisNilai(
                label: '${i.namaProduk} x${i.qty}',
                nilai: rp(i.subtotal),
              ),
          ],
          const _Garis(),
          BarisNilai(label: 'TOTAL', nilai: rp(t.totalSemua), tebal: true),
          if (t.tip > 0)
            BarisNilai(label: 'Tip', nilai: rp(t.tip), warna: Warna.go),
          const _Garis(),
          const SizedBox(height: 4),
          const Text('Terima kasih!', style: Teks.label),
          const Text(
            'Simpan resi untuk ambil kendaraan',
            textAlign: TextAlign.center,
            style: Teks.kecil,
          ),
        ],
      ),
    );
  }
}

class _Garis extends StatelessWidget {
  const _Garis();

  @override
  Widget build(BuildContext context) => const Padding(
        padding: EdgeInsets.symmetric(vertical: 6),
        child: Divider(height: 1),
      );
}
