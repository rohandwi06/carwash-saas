import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../data/models/pekerja.dart';
import '../../state/providers.dart';
import '../kerangka.dart';
import '../widgets/kartu.dart';
import '../widgets/pemuat.dart';
import 'layar_kasir.dart';

/// Pekerja & upah — khusus owner. Padanan `layarPekerja` di web.
///
/// Angka upah TIDAK pernah dihitung di sini. Aturan bagi rata, tarif per
/// kategori, upah training, potongan, dan penimpaan semuanya hidup di
/// WageService. App cuma menampilkan apa yang server bilang, karena dua
/// tempat berhitung berarti dua jawaban berbeda soal uang orang.
class LayarPekerja extends ConsumerStatefulWidget {
  const LayarPekerja({super.key});

  @override
  ConsumerState<LayarPekerja> createState() => _LayarPekerjaState();
}

typedef _Rentang = ({String dari, String sampai});

final _upahProvider =
    FutureProvider.family<List<UpahPekerja>, _Rentang>((ref, r) {
  return ref.watch(pekerjaRepoProvider).upahRentang(r.dari, r.sampai);
});

class _LayarPekerjaState extends ConsumerState<LayarPekerja> {
  late DateTime _dari = DateTime.now();
  late DateTime _sampai = DateTime.now();

  _Rentang get _rentang => (dari: iso(_dari), sampai: iso(_sampai));

  Future<void> _pilihRentang() async {
    final r = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2020),
      lastDate: DateTime.now(),
      initialDateRange: DateTimeRange(start: _dari, end: _sampai),
    );
    if (r != null) {
      setState(() {
        _dari = r.start;
        _sampai = r.end;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final pekerja = ref.watch(pekerjaProvider);

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
      children: [
        _daftarPekerja(pekerja),
        const SizedBox(height: 14),
        _kartuUpah(),
      ],
    );
  }

  Widget _daftarPekerja(AsyncValue<List<Pekerja>> pekerja) {
    return Kartu(
      judul: 'Pekerja',
      aksi: TextButton.icon(
        onPressed: () => _formPekerja(),
        icon: const Icon(Icons.add, size: 18),
        label: const Text('Tambah'),
      ),
      child: pekerja.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(20),
          child: Center(child: CircularProgressIndicator()),
        ),
        error: (e, _) => KotakGagal(
          pesan: '$e',
          onCoba: () => ref.invalidate(pekerjaProvider),
        ),
        data: (list) => list.isEmpty
            ? const Kosong(
                pesan: 'Belum ada pekerja.',
                ikon: Icons.groups_outlined,
              )
            : Column(
                children: [
                  for (final p in list)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4),
                      child: Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Row(
                                  children: [
                                    Flexible(
                                      child: Text(
                                        p.nama,
                                        overflow: TextOverflow.ellipsis,
                                        style: Teks.label,
                                      ),
                                    ),
                                    if (p.training) ...[
                                      const SizedBox(width: 6),
                                      const Cip(
                                        teks: 'TRAINING',
                                        latar: Warna.waterPudar,
                                        tinta: Warna.waterDk,
                                      ),
                                    ],
                                  ],
                                ),
                                Text(
                                  [
                                    if (p.umur != null) '${p.umur} th',
                                    if (p.telepon != null) p.telepon!,
                                    if (p.alamat != null) p.alamat!,
                                  ].join(' · '),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: Teks.kecil,
                                ),
                              ],
                            ),
                          ),
                          // Hadir/libur menentukan siapa yang muncul di layar
                          // kasir, bukan siapa yang dapat upah — upah tetap
                          // mengikuti transaksi yang benar-benar dikerjakan.
                          Switch(
                            value: p.hadir,
                            onChanged: (v) => _setHadir(p, v),
                          ),
                          IconButton(
                            icon: const Icon(Icons.edit_outlined),
                            onPressed: () => _formPekerja(lama: p),
                          ),
                          IconButton(
                            icon: const Icon(
                              Icons.delete_outline,
                              color: Warna.danger,
                            ),
                            onPressed: () => _hapus(p),
                          ),
                        ],
                      ),
                    ),
                ],
              ),
      ),
    );
  }

  Widget _kartuUpah() {
    final upah = ref.watch(_upahProvider(_rentang));

    return Kartu(
      judul: 'Upah',
      aksi: TextButton.icon(
        onPressed: _pilihRentang,
        icon: const Icon(Icons.date_range, size: 18),
        label: Text(
          iso(_dari) == iso(_sampai)
              ? tglPendek(_dari)
              : '${tglPendek(_dari)} - ${tglPendek(_sampai)}',
        ),
      ),
      child: upah.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(20),
          child: Center(child: CircularProgressIndicator()),
        ),
        error: (e, _) => KotakGagal(
          pesan: '$e',
          onCoba: () => ref.invalidate(_upahProvider(_rentang)),
        ),
        data: (list) {
          final aktif = list.where((u) => u.jumlahKendaraan > 0 || u.upah != 0);
          if (aktif.isEmpty) {
            return const Kosong(
              pesan: 'Belum ada cucian yang dikerjakan di rentang ini.',
            );
          }
          final total = aktif.fold(0, (t, u) => t + u.upah);

          return Column(
            children: [
              for (final u in aktif)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 5),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(u.nama, style: Teks.label),
                            Text(
                              [
                                '${u.jumlahKendaraan} kendaraan',
                                if (u.potongan > 0)
                                  'potongan ${rp(u.potongan)}',
                                if (u.timpa != null) 'ditimpa owner',
                              ].join(' · '),
                              style: Teks.kecil,
                            ),
                          ],
                        ),
                      ),
                      Text(
                        rp(u.upah),
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ],
                  ),
                ),
              const Divider(height: 18),
              BarisNilai(
                label: 'Total upah',
                nilai: rp(total),
                tebal: true,
                warna: Warna.danger,
              ),
            ],
          );
        },
      ),
    );
  }

  Future<void> _setHadir(Pekerja p, bool hadir) async {
    try {
      await ref
          .read(pekerjaRepoProvider)
          .ubah(p.id, {'is_present': hadir});
      ref.invalidate(pekerjaProvider);
    } catch (e) {
      if (mounted) pesanGagal(context, e);
    }
  }

  Future<void> _hapus(Pekerja p) async {
    final yakin = await konfirmasi(
      context,
      judul: 'Hapus ${p.nama}?',
      pesan: 'Riwayat upah yang sudah tercatat tidak ikut terhapus.',
      tombolYa: 'Hapus',
    );
    if (!yakin) return;
    try {
      await ref.read(pekerjaRepoProvider).hapus(p.id);
      ref.invalidate(pekerjaProvider);
    } catch (e) {
      if (mounted) pesanGagal(context, e);
    }
  }

  Future<void> _formPekerja({Pekerja? lama}) async {
    final nama = TextEditingController(text: lama?.nama ?? '');
    final nik = TextEditingController(text: lama?.nik ?? '');
    final telepon = TextEditingController(text: lama?.telepon ?? '');
    final alamat = TextEditingController(text: lama?.alamat ?? '');
    final tempatLahir = TextEditingController(text: lama?.tempatLahir ?? '');
    final tglLahir = TextEditingController(text: lama?.tanggalLahir ?? '');
    var training = lama?.training ?? false;

    final simpan = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSt) => AlertDialog(
          title: Text(
            lama == null ? 'Pekerja baru' : 'Ubah ${lama.nama}',
            style: Teks.judul,
          ),
          content: SizedBox(
            width: 420,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                    controller: nama,
                    autofocus: true,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(labelText: 'Nama'),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: nik,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'NIK (boleh kosong)',
                    ),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: telepon,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(
                      labelText: 'No. HP (boleh kosong)',
                    ),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: tempatLahir,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(
                      labelText: 'Tempat lahir (boleh kosong)',
                    ),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: tglLahir,
                    decoration: const InputDecoration(
                      labelText: 'Tanggal lahir',
                      hintText: 'yyyy-mm-dd',
                    ),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: alamat,
                    maxLines: 2,
                    textCapitalization: TextCapitalization.sentences,
                    decoration: const InputDecoration(
                      labelText: 'Alamat (boleh kosong)',
                    ),
                  ),
                  const SizedBox(height: 6),
                  SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    title: const Text('Karyawan training', style: Teks.label),
                    subtitle: const Text(
                      'Dibayar nominal tetap per cucian, bukan bagi rata.',
                      style: Teks.kecil,
                    ),
                    value: training,
                    onChanged: (v) => setSt(() => training = v),
                  ),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('Batal'),
            ),
            FilledButton(
              onPressed: () {
                if (nama.text.trim().isEmpty) return;
                Navigator.pop(ctx, true);
              },
              child: const Text('Simpan'),
            ),
          ],
        ),
      ),
    );

    final body = <String, dynamic>{
      'name': nama.text.trim(),
      'is_trainee': training,
      'nik': _kosongJadiNull(nik.text),
      'phone': _kosongJadiNull(telepon.text),
      'birth_place': _kosongJadiNull(tempatLahir.text),
      'birth_date': _kosongJadiNull(tglLahir.text),
      'address': _kosongJadiNull(alamat.text),
    };

    for (final c in [nama, nik, telepon, alamat, tempatLahir, tglLahir]) {
      c.dispose();
    }

    if (simpan != true) return;

    try {
      final repo = ref.read(pekerjaRepoProvider);
      if (lama == null) {
        await repo.tambah(body);
      } else {
        await repo.ubah(lama.id, body);
      }
      ref.invalidate(pekerjaProvider);
      if (mounted) pesanSukses(context, 'Tersimpan');
    } catch (e) {
      if (mounted) pesanGagal(context, e);
    }
  }
}

/// Kolom biodata yang dikosongkan owner dikirim sebagai null, bukan string
/// kosong — supaya di database benar-benar NULL dan tidak terhitung "terisi".
String? _kosongJadiNull(String s) {
  final t = s.trim();
  return t.isEmpty ? null : t;
}
