import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../data/models/laporan.dart';
import '../../state/providers.dart';
import '../kerangka.dart';
import '../widgets/isian.dart';
import '../widgets/kartu.dart';
import '../widgets/pemuat.dart';
import 'layar_kasir.dart';

/// Catat & lihat pengeluaran. Padanan `layarPengeluaran` di web.
///
/// Tanggal aktif dipakai untuk DUA hal sekaligus: melihat rincian hari itu,
/// sekaligus menentukan tanggal pengeluaran baru yang dicatat. Digabung
/// karena memisahkannya justru bikin salah catat — kasir yang sedang melihat
/// tanggal kemarin lalu menambah pengeluaran hampir selalu memang bermaksud
/// mencatatnya untuk kemarin.
class LayarPengeluaran extends ConsumerStatefulWidget {
  const LayarPengeluaran({super.key});

  @override
  ConsumerState<LayarPengeluaran> createState() => _LayarPengeluaranState();
}

/// Provider lokal layar ini: daftar pengeluaran satu tanggal.
final _pengeluaranProvider =
    FutureProvider.family<List<Pengeluaran>, String>((ref, tanggal) {
  return ref.watch(laporanRepoProvider).pengeluaran(tanggal: tanggal);
});

class _LayarPengeluaranState extends ConsumerState<LayarPengeluaran> {
  late DateTime _tanggal = DateTime.now();
  final _ket = TextEditingController();
  final _jumlah = TextEditingController();
  bool _menyimpan = false;

  String get _iso => iso(_tanggal);

  @override
  void dispose() {
    _ket.dispose();
    _jumlah.dispose();
    super.dispose();
  }

  Future<void> _pilihTanggal() async {
    final t = await showDatePicker(
      context: context,
      initialDate: _tanggal,
      firstDate: DateTime(2020),
      // Pengeluaran bertanggal masa depan tidak masuk akal untuk pembukuan
      // kas, dan hampir selalu salah ketik tahun.
      lastDate: DateTime.now(),
    );
    if (t != null) setState(() => _tanggal = t);
  }

  Future<void> _tambah() async {
    final ket = _ket.text.trim();
    final jumlah = bacaUang(_jumlah);
    if (ket.isEmpty || jumlah <= 0) {
      pesanGagal(context, 'Isi keterangan dan jumlah dulu.');
      return;
    }

    setState(() => _menyimpan = true);
    try {
      await ref.read(laporanRepoProvider).tambahPengeluaran(
            keterangan: ket,
            jumlah: jumlah,
            tanggal: _iso,
          );
      if (!mounted) return;
      _ket.clear();
      _jumlah.clear();
      ref
        ..invalidate(_pengeluaranProvider(_iso))
        ..invalidate(rekapHariIniProvider);
      setState(() => _menyimpan = false);
      pesanSukses(context, 'Pengeluaran tercatat');
    } catch (e) {
      if (!mounted) return;
      setState(() => _menyimpan = false);
      pesanGagal(context, e);
    }
  }

  Future<void> _hapus(Pengeluaran e) async {
    final yakin = await konfirmasi(
      context,
      judul: 'Hapus pengeluaran?',
      pesan: '${e.keterangan} · ${rp(e.jumlah)}',
      tombolYa: 'Hapus',
    );
    if (!yakin) return;
    try {
      await ref.read(laporanRepoProvider).hapusPengeluaran(e.id);
      ref
        ..invalidate(_pengeluaranProvider(_iso))
        ..invalidate(rekapHariIniProvider);
    } catch (err) {
      if (mounted) pesanGagal(context, err);
    }
  }

  @override
  Widget build(BuildContext context) {
    final daftar = ref.watch(_pengeluaranProvider(_iso));
    final owner = ref.watch(sesiProvider).owner;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
      children: [
        Kartu(
          judul: 'Tanggal',
          aksi: TextButton.icon(
            onPressed: _pilihTanggal,
            icon: const Icon(Icons.calendar_month, size: 18),
            label: const Text('Ganti'),
          ),
          child: Text(tglPanjang(_tanggal), style: Teks.angkaBesar),
        ),
        const SizedBox(height: 14),
        Kartu(
          judul: 'Catat pengeluaran',
          child: Column(
            children: [
              TextField(
                controller: _ket,
                textCapitalization: TextCapitalization.sentences,
                decoration: const InputDecoration(
                  hintText: 'Untuk apa? (mis. beli sabun)',
                  prefixIcon: Icon(Icons.notes),
                ),
              ),
              const SizedBox(height: 10),
              IsianUang(controller: _jumlah, label: 'Jumlah'),
              const SizedBox(height: 12),
              FilledButton.icon(
                onPressed: _menyimpan ? null : _tambah,
                icon: const Icon(Icons.add),
                label: Text(_menyimpan ? 'Menyimpan...' : 'Catat'),
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        daftar.when(
          loading: () => const Padding(
            padding: EdgeInsets.all(24),
            child: Center(child: CircularProgressIndicator()),
          ),
          error: (e, _) => KotakGagal(
            pesan: '$e',
            onCoba: () => ref.invalidate(_pengeluaranProvider(_iso)),
          ),
          data: (list) {
            final total = list.fold(0, (t, e) => t + e.jumlah);
            return Kartu(
              judul: 'Pengeluaran ${tglPendek(_tanggal)}',
              child: Column(
                children: [
                  if (list.isEmpty)
                    const Kosong(pesan: 'Belum ada pengeluaran di tanggal ini.')
                  else
                    for (final e in list)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 4),
                        child: Row(
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(e.keterangan, style: Teks.label),
                                  if (e.dicatatOleh != null)
                                    Text(
                                      'oleh ${e.dicatatOleh}',
                                      style: Teks.kecil,
                                    ),
                                ],
                              ),
                            ),
                            Text(
                              '-${rp(e.jumlah)}',
                              style: const TextStyle(
                                color: Warna.danger,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                            // Koreksi & hapus pengeluaran hanya untuk owner —
                            // server juga menolaknya untuk kasir, jadi
                            // tombolnya memang tidak perlu ada.
                            if (owner)
                              IconButton(
                                icon: const Icon(
                                  Icons.delete_outline,
                                  color: Warna.danger,
                                ),
                                onPressed: () => _hapus(e),
                              ),
                          ],
                        ),
                      ),
                  const Divider(height: 18),
                  BarisNilai(
                    label: 'Total',
                    nilai: total > 0 ? '-${rp(total)}' : rp(0),
                    warna: Warna.danger,
                    tebal: true,
                  ),
                ],
              ),
            );
          },
        ),
      ],
    );
  }
}
