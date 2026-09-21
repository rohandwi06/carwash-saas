import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../data/models/laporan.dart';
import '../../data/models/transaksi.dart';
import '../../state/providers.dart';
import '../widgets/kartu.dart';
import '../widgets/pemuat.dart';
import 'layar_kasir.dart';

/// Pembukuan: kalender sebulan + rincian satu hari. Padanan `layarBuku`.
///
/// Kalender memakai `/api/reports/calendar`, yang mengembalikan rekap LENGKAP
/// tiap hari yang punya catatan. Jadi tanggal yang berangka bukan sekadar
/// "ada transaksi" — angkanya adalah omzet hari itu, siap dibaca sekilas.
class LayarBuku extends ConsumerStatefulWidget {
  const LayarBuku({super.key});

  @override
  ConsumerState<LayarBuku> createState() => _LayarBukuState();
}

class _LayarBukuState extends ConsumerState<LayarBuku> {
  late DateTime _bulan = DateTime(DateTime.now().year, DateTime.now().month);
  String? _tglPilih;

  void _gantiBulan(int delta) {
    setState(() {
      _bulan = DateTime(_bulan.year, _bulan.month + delta);
      _tglPilih = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final kunci = (tahun: _bulan.year, bulan: _bulan.month);
    final kalender = ref.watch(kalenderProvider(kunci));

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
      children: [
        Kartu(
          child: Row(
            children: [
              IconButton(
                icon: const Icon(Icons.chevron_left),
                onPressed: () => _gantiBulan(-1),
              ),
              Expanded(
                child: Text(
                  '${namaBulan[_bulan.month - 1]} ${_bulan.year}',
                  textAlign: TextAlign.center,
                  style: Teks.judul,
                ),
              ),
              IconButton(
                icon: const Icon(Icons.chevron_right),
                onPressed: () => _gantiBulan(1),
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        kalender.when(
          loading: () => const Padding(
            padding: EdgeInsets.all(30),
            child: Center(child: CircularProgressIndicator()),
          ),
          error: (e, _) => KotakGagal(
            pesan: '$e',
            onCoba: () => ref.invalidate(kalenderProvider(kunci)),
          ),
          data: (hari) => _Kalender(
            bulan: _bulan,
            hari: {for (final h in hari) tglSaja(h.tanggal): h},
            dipilih: _tglPilih,
            onPilih: (t) => setState(() => _tglPilih = t),
          ),
        ),
        if (_tglPilih != null) ...[
          const SizedBox(height: 14),
          _rincianHari(_tglPilih!),
        ],
      ],
    );
  }

  Widget _rincianHari(String tanggal) {
    final rekap = ref.watch(rekapProvider((tanggal: tanggal, idBuku: null)));
    final trx = ref.watch(transaksiHariProvider(tanggal));

    return Column(
      children: [
        rekap.when(
          loading: () => const SizedBox.shrink(),
          error: (_, __) => const SizedBox.shrink(),
          data: (h) => Kartu(
            judul: tglPanjang(DateTime.parse(tanggal)),
            child: Column(
              children: [
                BarisNilai(label: 'Omzet', nilai: rp(h.omzet), tebal: true),
                BarisNilai(
                  label: 'Cucian',
                  nilai: rp(h.totalCuci),
                  subLabel: '${h.jumlahKendaraan} kendaraan',
                ),
                BarisNilai(label: 'F&B', nilai: rp(h.fnbTotal)),
                BarisNilai(label: 'Tip', nilai: rp(h.tip)),
                BarisNilai(
                  label: 'Upah pekerja',
                  nilai: '-${rp(h.upah)}',
                  warna: Warna.danger,
                ),
                BarisNilai(
                  label: 'Pengeluaran',
                  nilai: '-${rp(h.pengeluaran)}',
                  warna: Warna.danger,
                ),
                const Divider(height: 18),
                BarisNilai(
                  label: 'Laba bersih',
                  nilai: rp(h.laba),
                  warna: h.laba < 0 ? Warna.danger : Warna.go,
                  tebal: true,
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 14),
        trx.when(
          loading: () => const Padding(
            padding: EdgeInsets.all(20),
            child: Center(child: CircularProgressIndicator()),
          ),
          error: (e, _) => KotakGagal(
            pesan: '$e',
            onCoba: () => ref.invalidate(transaksiHariProvider(tanggal)),
          ),
          data: (list) => Kartu(
            judul: 'Transaksi (${list.length})',
            child: list.isEmpty
                ? const Kosong(pesan: 'Tidak ada transaksi di tanggal ini.')
                : Column(
                    children: [
                      for (final t in list)
                        _BarisTransaksi(
                          transaksi: t,
                          onBatal: () => _batalkan(t, tanggal),
                        ),
                    ],
                  ),
          ),
        ),
      ],
    );
  }

  Future<void> _batalkan(Transaksi t, String tanggal) async {
    final owner = ref.read(sesiProvider).owner;
    final c = TextEditingController();

    final alasan = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(
          owner ? 'Batalkan transaksi' : 'Ajukan pembatalan',
          style: Teks.judul,
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              '#${t.noAntrian} · ${t.namaKendaraan} · ${rp(t.totalSemua)}',
              style: Teks.label,
            ),
            const SizedBox(height: 10),
            Text(
              owner
                  ? 'Transaksi ditandai batal, bukan dihapus — riwayatnya '
                      'tetap ada untuk audit.'
                  : 'Transaksi TETAP dihitung di rekap sampai owner '
                      'menyetujui, supaya angka harian tidak bisa diubah '
                      'sepihak.',
              style: Teks.subjudul,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: c,
              autofocus: true,
              maxLength: 120,
              decoration: const InputDecoration(hintText: 'Alasan (wajib)'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Batal'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Warna.danger),
            onPressed: () {
              final v = c.text.trim();
              if (v.isEmpty) return;
              Navigator.pop(ctx, v);
            },
            child: const Text('Kirim'),
          ),
        ],
      ),
    );
    c.dispose();
    if (alasan == null) return;

    try {
      await ref.read(transaksiRepoProvider).ajukanVoid(t.id, alasan);
      ref
        ..invalidate(transaksiHariProvider(tanggal))
        ..invalidate(rekapProvider((tanggal: tanggal, idBuku: null)))
        ..invalidate(kalenderProvider((tahun: _bulan.year, bulan: _bulan.month)))
        ..invalidate(antreanVoidProvider)
        ..invalidate(rekapHariIniProvider);
      if (mounted) {
        pesanSukses(
          context,
          owner ? 'Transaksi dibatalkan' : 'Pengajuan terkirim ke owner',
        );
      }
    } catch (e) {
      if (mounted) pesanGagal(context, e);
    }
  }
}

/// Grid kalender satu bulan. Hari yang punya catatan diberi angka omzetnya,
/// bukan sekadar titik — supaya owner bisa memindai sebulan sekali lihat.
class _Kalender extends StatelessWidget {
  const _Kalender({
    required this.bulan,
    required this.hari,
    required this.dipilih,
    required this.onPilih,
  });

  final DateTime bulan;

  /// 'yyyy-MM-dd' -> rekap hari itu.
  final Map<String, RekapHarian> hari;
  final String? dipilih;
  final ValueChanged<String> onPilih;

  static const _namaHari = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

  @override
  Widget build(BuildContext context) {
    final jumlahHari = DateTime(bulan.year, bulan.month + 1, 0).day;
    // weekday: 1=Senin ... 7=Minggu. Dikurangi 1 supaya jadi indeks kolom
    // dengan Senin di kiri, mengikuti kebiasaan kalender Indonesia.
    final awal = DateTime(bulan.year, bulan.month).weekday - 1;
    final hariIniIso = hariIni();

    return Kartu(
      child: Column(
        children: [
          Row(
            children: [
              for (final n in _namaHari)
                Expanded(
                  child: Text(
                    n,
                    textAlign: TextAlign.center,
                    style: Teks.kecil,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 6),
          GridView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: awal + jumlahHari,
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 7,
              crossAxisSpacing: 4,
              mainAxisSpacing: 4,
              childAspectRatio: 0.92,
            ),
            itemBuilder: (_, i) {
              if (i < awal) return const SizedBox.shrink();

              final tgl = i - awal + 1;
              final t = DateTime(bulan.year, bulan.month, tgl);
              final key = iso(t);
              final rekap = hari[key];
              final terpilih = dipilih == key;
              final iniHariIni = key == hariIniIso;

              return InkWell(
                onTap: () => onPilih(key),
                borderRadius: BorderRadius.circular(10),
                child: Container(
                  decoration: BoxDecoration(
                    color: terpilih
                        ? Warna.ink
                        : (rekap != null ? Warna.tagPudar : Warna.card),
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(
                      color: iniHariIni ? Warna.water : Warna.line,
                      width: iniHariIni ? 2.5 : 1.5,
                    ),
                  ),
                  padding: const EdgeInsets.all(3),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(
                        '$tgl',
                        style: TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w900,
                          color: terpilih ? Colors.white : Warna.ink,
                        ),
                      ),
                      if (rekap != null)
                        FittedBox(
                          child: Text(
                            angka(rekap.omzet),
                            style: TextStyle(
                              fontSize: 9,
                              fontWeight: FontWeight.w800,
                              color: terpilih ? Warna.tag : Warna.ink2,
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              );
            },
          ),
        ],
      ),
    );
  }
}

class _BarisTransaksi extends StatelessWidget {
  const _BarisTransaksi({required this.transaksi, required this.onBatal});

  final Transaksi transaksi;
  final VoidCallback onBatal;

  @override
  Widget build(BuildContext context) {
    final t = transaksi;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 34,
            child: Text(
              '#${t.noAntrian}',
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w900,
                color: Warna.ink2,
              ),
            ),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  t.namaKendaraan,
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w900,
                    decoration: t.batal ? TextDecoration.lineThrough : null,
                    color: t.batal ? Warna.redup : Warna.ink,
                  ),
                ),
                const SizedBox(height: 3),
                Wrap(
                  spacing: 6,
                  runSpacing: 4,
                  children: [
                    if (t.plat != null) Cip(teks: t.plat!),
                    Cip(teks: t.caraBayar == 'cash' ? 'CASH' : 'TF'),
                    if (t.dibuatPada != null)
                      Cip(teks: jam(t.dibuatPada!), ikon: Icons.schedule),
                    if (t.pekerja.isNotEmpty)
                      Cip(
                        teks: t.pekerja.map((p) => p.nama).join(' + '),
                        ikon: Icons.person,
                      ),
                    if (t.batal)
                      const Cip(
                        teks: 'BATAL',
                        latar: Warna.dangerPudar,
                        tinta: Warna.danger,
                      ),
                    if (t.menungguVoid)
                      const Cip(
                        teks: 'MENUNGGU BATAL',
                        latar: Warna.tagPudar,
                        tinta: Warna.tagInk,
                      ),
                  ],
                ),
                if (t.alasanVoid != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 3),
                    child: Text(
                      'Alasan: ${t.alasanVoid}',
                      style: Teks.kecil,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                rp(t.totalSemua),
                style: TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w900,
                  decoration: t.batal ? TextDecoration.lineThrough : null,
                  color: t.batal ? Warna.redup : Warna.ink,
                ),
              ),
              if (t.tip > 0)
                Text('+tip ${rp(t.tip)}', style: Teks.kecil),
            ],
          ),
          if (!t.batal && !t.menungguVoid)
            IconButton(
              icon: const Icon(Icons.cancel_outlined, color: Warna.danger),
              tooltip: 'Batalkan',
              onPressed: onBatal,
            ),
        ],
      ),
    );
  }
}
