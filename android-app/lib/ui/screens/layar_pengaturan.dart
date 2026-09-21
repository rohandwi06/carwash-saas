import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../core/cetak.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../data/models/fnb.dart';
import '../../data/models/json_util.dart';
import '../../data/models/katalog.dart';
import '../../data/models/laporan.dart';
import '../../state/providers.dart';
import '../kerangka.dart';
import '../widgets/isian.dart';
import '../widgets/kartu.dart';
import '../widgets/pemuat.dart';
import 'layar_akun.dart';
import 'layar_kasir.dart';

/// Pengaturan — khusus owner. Padanan `layarMenuFnb` di web, ditambah satu tab
/// yang tidak ada padanannya di sana: Tablet (printer & alamat server), karena
/// hal-hal itu memang cuma ada di perangkat.
class LayarPengaturan extends ConsumerStatefulWidget {
  const LayarPengaturan({super.key});

  @override
  ConsumerState<LayarPengaturan> createState() => _LayarPengaturanState();
}

class _LayarPengaturanState extends ConsumerState<LayarPengaturan>
    with SingleTickerProviderStateMixin {
  late final TabController _tab = TabController(length: 6, vsync: this);

  @override
  void dispose() {
    _tab.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Material(
          color: Warna.card,
          child: TabBar(
            controller: _tab,
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            labelColor: Warna.waterDk,
            unselectedLabelColor: Warna.ink2,
            indicatorColor: Warna.water,
            labelStyle: const TextStyle(
              fontWeight: FontWeight.w900,
              fontSize: 14,
            ),
            tabs: const [
              Tab(text: 'Harga'),
              Tab(text: 'Menu F&B'),
              Tab(text: 'Upah'),
              Tab(text: 'Jam buka'),
              Tab(text: 'Akun'),
              Tab(text: 'Tablet'),
            ],
          ),
        ),
        Expanded(
          child: TabBarView(
            controller: _tab,
            children: const [
              _TabHarga(),
              _TabMenu(),
              _TabUpah(),
              _TabShift(),
              TabAkun(),
              _TabTablet(),
            ],
          ),
        ),
      ],
    );
  }
}

/* ------------------------------------------------------------------ */

/// Matriks harga: satu baris per jenis kendaraan, satu kolom per layanan.
/// Sel kosong berarti layanan itu memang tidak tersedia untuk kendaraan
/// tersebut — dibedakan dari harga 0 yang berarti gratis.
class _TabHarga extends ConsumerWidget {
  const _TabHarga();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final konfig = ref.watch(konfigProvider);

    return konfig.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => KotakGagal(
        pesan: '$e',
        onCoba: () => ref.invalidate(konfigProvider),
      ),
      data: (cfg) => ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
        children: [
          for (final kat in cfg.kategori.values)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Kartu(
                judul: kat.label,
                aksi: Text(kat.contoh, style: Teks.kecil),
                child: Column(
                  children: [
                    for (final slug in cfg.layananTersedia(kat.slug))
                      _BarisHarga(
                        label: cfg.layanan[slug]?.label ?? slug,
                        nilai: kat.harga[slug] ?? 0,
                        onSimpan: (v) async {
                          try {
                            await ref.read(katalogRepoProvider).setHarga(
                                  kategori: kat.slug,
                                  layanan: slug,
                                  total: v,
                                );
                            ref.invalidate(konfigProvider);
                            if (context.mounted) {
                              pesanSukses(context, 'Harga tersimpan');
                            }
                          } catch (e) {
                            if (context.mounted) pesanGagal(context, e);
                          }
                        },
                      ),
                  ],
                ),
              ),
            ),
          _KartuAddon(cfg: cfg),
        ],
      ),
    );
  }
}

class _KartuAddon extends ConsumerWidget {
  const _KartuAddon({required this.cfg});

  final Konfig cfg;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Kartu(
      judul: 'Layanan tambahan',
      aksi: TextButton.icon(
        onPressed: () async {
          final hasil = await _dialogNamaHarga(context, judul: 'Add-on baru');
          if (hasil == null) return;
          try {
            await ref
                .read(katalogRepoProvider)
                .tambahAddon(hasil.nama, hasil.harga);
            ref.invalidate(konfigProvider);
          } catch (e) {
            if (context.mounted) pesanGagal(context, e);
          }
        },
        icon: const Icon(Icons.add, size: 18),
        label: const Text('Tambah'),
      ),
      child: cfg.addons.isEmpty
          ? const Kosong(pesan: 'Belum ada layanan tambahan.')
          : Column(
              children: [
                for (final a in cfg.addons)
                  _BarisHarga(
                    label: a.nama,
                    nilai: a.harga,
                    onHapus: () async {
                      final yakin = await konfirmasi(
                        context,
                        judul: 'Hapus ${a.nama}?',
                        tombolYa: 'Hapus',
                      );
                      if (!yakin) return;
                      try {
                        await ref.read(katalogRepoProvider).hapusAddon(a.id);
                        ref.invalidate(konfigProvider);
                      } catch (e) {
                        if (context.mounted) pesanGagal(context, e);
                      }
                    },
                    onSimpan: (v) async {
                      try {
                        await ref
                            .read(katalogRepoProvider)
                            .ubahAddon(a.id, {'price': v});
                        ref.invalidate(konfigProvider);
                        if (context.mounted) {
                          pesanSukses(context, 'Harga tersimpan');
                        }
                      } catch (e) {
                        if (context.mounted) pesanGagal(context, e);
                      }
                    },
                  ),
              ],
            ),
    );
  }
}

/// Satu baris angka yang bisa diedit di tempat. Perubahan baru dikirim saat
/// kolom kehilangan fokus atau tombol simpan ditekan — bukan tiap ketukan,
/// yang akan mengirim puluhan permintaan untuk satu kali ubah harga.
class _BarisHarga extends StatefulWidget {
  const _BarisHarga({
    required this.label,
    required this.nilai,
    required this.onSimpan,
    this.onHapus,
  });

  final String label;
  final int nilai;
  final Future<void> Function(int) onSimpan;
  final VoidCallback? onHapus;

  @override
  State<_BarisHarga> createState() => _BarisHargaState();
}

class _BarisHargaState extends State<_BarisHarga> {
  late final _c = TextEditingController(text: '${widget.nilai}');
  final _fokus = FocusNode();

  @override
  void initState() {
    super.initState();
    _fokus.addListener(() {
      if (!_fokus.hasFocus) _simpan();
    });
  }

  @override
  void dispose() {
    _c.dispose();
    _fokus.dispose();
    super.dispose();
  }

  void _simpan() {
    final v = bacaUang(_c);
    if (v != widget.nilai) widget.onSimpan(v);
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        children: [
          Expanded(child: Text(widget.label, style: Teks.label)),
          SizedBox(
            width: 150,
            child: TextField(
              controller: _c,
              focusNode: _fokus,
              keyboardType: TextInputType.number,
              textAlign: TextAlign.right,
              onSubmitted: (_) => _simpan(),
              decoration: const InputDecoration(
                prefixText: 'Rp ',
                isDense: true,
                contentPadding:
                    EdgeInsets.symmetric(horizontal: 10, vertical: 12),
              ),
            ),
          ),
          if (widget.onHapus != null)
            IconButton(
              icon: const Icon(Icons.delete_outline, color: Warna.danger),
              onPressed: widget.onHapus,
            ),
        ],
      ),
    );
  }
}

/* ------------------------------------------------------------------ */

class _TabMenu extends ConsumerWidget {
  const _TabMenu();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final produk = ref.watch(semuaProdukProvider);

    return produk.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => KotakGagal(
        pesan: '$e',
        onCoba: () => ref.invalidate(semuaProdukProvider),
      ),
      data: (list) => ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
        children: [
          Kartu(
            judul: 'Menu makanan & minuman',
            aksi: TextButton.icon(
              onPressed: () => _tambah(context, ref),
              icon: const Icon(Icons.add, size: 18),
              label: const Text('Tambah'),
            ),
            child: list.isEmpty
                ? const Kosong(pesan: 'Belum ada menu.')
                : Column(
                    children: [
                      for (final p in list) _BarisProduk(produk: p),
                    ],
                  ),
          ),
        ],
      ),
    );
  }

  Future<void> _tambah(BuildContext context, WidgetRef ref) async {
    final hasil = await _dialogNamaHarga(
      context,
      judul: 'Menu baru',
      pakaiJenis: true,
    );
    if (hasil == null) return;
    try {
      await ref.read(fnbRepoProvider).tambahProduk({
        'name': hasil.nama,
        'price': hasil.harga,
        'type': hasil.jenis ?? 'minuman',
        'stock': hasil.stok ?? 0,
      });
      ref
        ..invalidate(semuaProdukProvider)
        ..invalidate(produkProvider);
    } catch (e) {
      if (context.mounted) pesanGagal(context, e);
    }
  }
}

class _BarisProduk extends ConsumerWidget {
  const _BarisProduk({required this.produk});

  final Produk produk;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    Future<void> ubah(Map<String, dynamic> body) async {
      try {
        await ref.read(fnbRepoProvider).ubahProduk(produk.id, body);
        ref
          ..invalidate(semuaProdukProvider)
          ..invalidate(produkProvider);
      } catch (e) {
        if (context.mounted) pesanGagal(context, e);
      }
    }

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Row(
                  children: [
                    Flexible(
                      child: Text(
                        produk.nama,
                        overflow: TextOverflow.ellipsis,
                        style: Teks.label,
                      ),
                    ),
                    const SizedBox(width: 6),
                    Cip(teks: produk.jenis),
                    if (produk.stok == 0) ...[
                      const SizedBox(width: 4),
                      const Cip(
                        teks: 'STOK HABIS',
                        latar: Warna.dangerPudar,
                        tinta: Warna.danger,
                      ),
                    ],
                  ],
                ),
              ),
              Switch(
                value: produk.aktif,
                onChanged: (v) => ubah({'is_active': v}),
              ),
              IconButton(
                icon: const Icon(Icons.delete_outline, color: Warna.danger),
                onPressed: () async {
                  final yakin = await konfirmasi(
                    context,
                    judul: 'Hapus ${produk.nama}?',
                    pesan: 'Riwayat penjualan yang sudah ada tidak berubah.',
                    tombolYa: 'Hapus',
                  );
                  if (!yakin) return;
                  try {
                    await ref.read(fnbRepoProvider).hapusProduk(produk.id);
                    ref
                      ..invalidate(semuaProdukProvider)
                      ..invalidate(produkProvider);
                  } catch (e) {
                    if (context.mounted) pesanGagal(context, e);
                  }
                },
              ),
            ],
          ),
          Row(
            children: [
              Expanded(
                child: _MiniAngka(
                  label: 'Harga',
                  nilai: produk.harga,
                  awalan: 'Rp ',
                  onSimpan: (v) => ubah({'price': v}),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _MiniAngka(
                  label: 'Stok',
                  nilai: produk.stok,
                  onSimpan: (v) => ubah({'stock': v}),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _MiniAngka extends StatefulWidget {
  const _MiniAngka({
    required this.label,
    required this.nilai,
    required this.onSimpan,
    this.awalan,
  });

  final String label;
  final int nilai;
  final String? awalan;
  final Future<void> Function(int) onSimpan;

  @override
  State<_MiniAngka> createState() => _MiniAngkaState();
}

class _MiniAngkaState extends State<_MiniAngka> {
  late final _c = TextEditingController(text: '${widget.nilai}');
  final _fokus = FocusNode();

  @override
  void initState() {
    super.initState();
    _fokus.addListener(() {
      if (!_fokus.hasFocus && bacaUang(_c) != widget.nilai) {
        widget.onSimpan(bacaUang(_c));
      }
    });
  }

  @override
  void dispose() {
    _c.dispose();
    _fokus.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: _c,
      focusNode: _fokus,
      keyboardType: TextInputType.number,
      decoration: InputDecoration(
        labelText: widget.label,
        prefixText: widget.awalan,
        isDense: true,
      ),
    );
  }
}

/* ------------------------------------------------------------------ */

class _TabUpah extends ConsumerStatefulWidget {
  const _TabUpah();

  @override
  ConsumerState<_TabUpah> createState() => _TabUpahState();
}

final _tarifProvider = FutureProvider<List<Map<String, dynamic>>>(
  (ref) => ref.watch(katalogRepoProvider).tarifUpah(),
);

class _TabUpahState extends ConsumerState<_TabUpah> {
  @override
  Widget build(BuildContext context) {
    final tarif = ref.watch(_tarifProvider);
    final cfg = ref.watch(konfigProvider).valueOrNull;

    return tarif.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => KotakGagal(
        pesan: '$e',
        onCoba: () => ref.invalidate(_tarifProvider),
      ),
      data: (rows) => ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
        children: [
          Kartu(
            judul: 'Tarif upah per cucian',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Ini jatah upah untuk SATU cucian, dibagi rata ke pekerja '
                  'yang mengerjakannya. Bukan upah per orang.',
                  style: Teks.kecil,
                ),
                const SizedBox(height: 10),
                for (final r in rows)
                  _BarisHarga(
                    label: _labelTarif(cfg, r),
                    nilai: asInt(r['amount']),
                    onSimpan: (v) async {
                      try {
                        await ref.read(katalogRepoProvider).setTarifUpah(
                              kategori: asStr(r['category']),
                              layanan: asStr(r['service']),
                              jumlah: v,
                            );
                        ref
                          ..invalidate(_tarifProvider)
                          ..invalidate(konfigProvider);
                        if (context.mounted) {
                          pesanSukses(context, 'Tarif tersimpan');
                        }
                      } catch (e) {
                        if (context.mounted) pesanGagal(context, e);
                      }
                    },
                  ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          const _KartuTraining(),
        ],
      ),
    );
  }

  /// "Mobil Kecil · Cuci Reguler". Kalau katalognya belum termuat, slug mentah
  /// tetap ditampilkan supaya barisnya tidak kosong tanpa penjelasan.
  String _labelTarif(Konfig? cfg, Map<String, dynamic> r) {
    final kat = asStr(r['category']);
    final svc = asStr(r['service']);
    return '${cfg?.kategori[kat]?.label ?? kat} · '
        '${cfg?.layanan[svc]?.label ?? svc}';
  }
}

final _trainingProvider = FutureProvider<List<Map<String, dynamic>>>(
  (ref) => ref.watch(katalogRepoProvider).upahTraining(),
);

class _KartuTraining extends ConsumerWidget {
  const _KartuTraining();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final data = ref.watch(_trainingProvider);
    final cfg = ref.watch(konfigProvider).valueOrNull;

    return data.when(
      loading: () => const SizedBox.shrink(),
      error: (e, _) => KotakGagal(
        pesan: '$e',
        onCoba: () => ref.invalidate(_trainingProvider),
      ),
      data: (rows) => Kartu(
        judul: 'Jatah karyawan training',
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Jatah BERSAMA semua anak training pada satu cucian, bukan per '
              'orang. Angka upah senior ditampilkan sebagai pembanding.',
              style: Teks.kecil,
            ),
            const SizedBox(height: 10),
            for (final r in rows)
              _BarisHarga(
                label: '${cfg?.kategori[asStr(r['category'])]?.label ?? asStr(r['category'])}'
                    ' · ${cfg?.layanan[asStr(r['service'])]?.label ?? asStr(r['service'])}'
                    '  (senior ${rp(asInt(r['amount']))})',
                nilai: asInt(r['trainee_amount']),
                onSimpan: (v) async {
                  try {
                    await ref.read(katalogRepoProvider).setUpahTraining(
                          kategori: asStr(r['category']),
                          layanan: asStr(r['service']),
                          jumlah: v,
                        );
                    ref.invalidate(_trainingProvider);
                    if (context.mounted) {
                      pesanSukses(context, 'Jatah training tersimpan');
                    }
                  } catch (e) {
                    if (context.mounted) pesanGagal(context, e);
                  }
                },
              ),
          ],
        ),
      ),
    );
  }
}

/* ------------------------------------------------------------------ */

class _TabShift extends ConsumerWidget {
  const _TabShift();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final shift = ref.watch(shiftProvider);

    return shift.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => KotakGagal(
        pesan: '$e',
        onCoba: () => ref.invalidate(shiftProvider),
      ),
      data: (s) => ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
        children: [
          Kartu(
            judul: 'Kunci di luar jam buka',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Aktifkan penguncian', style: Teks.label),
                  subtitle: const Text(
                    'Kasir tidak bisa mencatat transaksi di luar jam shift. '
                    'Owner tidak pernah ikut terkunci.',
                    style: Teks.kecil,
                  ),
                  value: s.aktif,
                  onChanged: (v) async {
                    try {
                      await ref
                          .read(shiftRepoProvider)
                          .setSakelar(aktif: v, pesan: s.pesan);
                      ref.invalidate(shiftProvider);
                    } catch (e) {
                      if (context.mounted) pesanGagal(context, e);
                    }
                  },
                ),
                if (s.aktif && s.tanpaShift)
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Warna.tagPudar,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: Warna.tag),
                    ),
                    child: const Text(
                      'Penguncian menyala tapi belum ada shift aktif. '
                      'Aplikasi memilih TIDAK mengunci supaya kasir tidak '
                      'terjebak — tambahkan shift di bawah.',
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: Warna.tagInk,
                      ),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          Kartu(
            judul: 'Jadwal shift',
            aksi: TextButton.icon(
              onPressed: () => _formShift(context, ref),
              icon: const Icon(Icons.add, size: 18),
              label: const Text('Tambah'),
            ),
            child: s.semuaShift.isEmpty
                ? const Kosong(pesan: 'Belum ada shift.')
                : Column(
                    children: [
                      for (final x in s.semuaShift)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          title: Text(x.nama, style: Teks.label),
                          subtitle: Text(x.rentang, style: Teks.kecil),
                          trailing: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Switch(
                                value: x.aktif,
                                onChanged: (v) async {
                                  try {
                                    await ref.read(shiftRepoProvider).ubah(
                                          x.id,
                                          nama: x.nama,
                                          mulai: x.mulai,
                                          selesai: x.selesai,
                                          aktif: v,
                                        );
                                    ref.invalidate(shiftProvider);
                                  } catch (e) {
                                    if (context.mounted) {
                                      pesanGagal(context, e);
                                    }
                                  }
                                },
                              ),
                              IconButton(
                                icon: const Icon(Icons.edit_outlined),
                                onPressed: () =>
                                    _formShift(context, ref, lama: x),
                              ),
                              IconButton(
                                icon: const Icon(
                                  Icons.delete_outline,
                                  color: Warna.danger,
                                ),
                                onPressed: () async {
                                  final yakin = await konfirmasi(
                                    context,
                                    judul: 'Hapus shift ${x.nama}?',
                                    tombolYa: 'Hapus',
                                  );
                                  if (!yakin) return;
                                  try {
                                    await ref
                                        .read(shiftRepoProvider)
                                        .hapus(x.id);
                                    ref.invalidate(shiftProvider);
                                  } catch (e) {
                                    if (context.mounted) {
                                      pesanGagal(context, e);
                                    }
                                  }
                                },
                              ),
                            ],
                          ),
                        ),
                    ],
                  ),
          ),
        ],
      ),
    );
  }

  Future<void> _formShift(
    BuildContext context,
    WidgetRef ref, {
    Shift? lama,
  }) async {
    final nama = TextEditingController(text: lama?.nama ?? '');
    var mulai = lama?.mulai ?? '07:00';
    var selesai = lama?.selesai ?? '17:00';

    final simpan = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSt) => AlertDialog(
          title: Text(lama == null ? 'Shift baru' : 'Ubah shift',
              style: Teks.judul,),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: nama,
                autofocus: true,
                textCapitalization: TextCapitalization.words,
                decoration: const InputDecoration(
                  labelText: 'Nama shift',
                  hintText: 'mis. Pagi',
                ),
              ),
              const SizedBox(height: 14),
              Row(
                children: [
                  Expanded(
                    child: _PilihJam(
                      label: 'Mulai',
                      jam: mulai,
                      onPilih: (v) => setSt(() => mulai = v),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _PilihJam(
                      label: 'Selesai',
                      jam: selesai,
                      onPilih: (v) => setSt(() => selesai = v),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              const Text(
                'Shift yang melewati tengah malam (mis. 20:00-02:00) boleh — '
                'jamnya dianggap membungkus ke hari berikutnya.',
                style: Teks.kecil,
              ),
            ],
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

    final teksNama = nama.text.trim();
    nama.dispose();
    if (simpan != true) return;

    try {
      final repo = ref.read(shiftRepoProvider);
      if (lama == null) {
        await repo.tambah(nama: teksNama, mulai: mulai, selesai: selesai);
      } else {
        await repo.ubah(
          lama.id,
          nama: teksNama,
          mulai: mulai,
          selesai: selesai,
          aktif: lama.aktif,
        );
      }
      ref.invalidate(shiftProvider);
    } catch (e) {
      if (context.mounted) pesanGagal(context, e);
    }
  }
}

class _PilihJam extends StatelessWidget {
  const _PilihJam({
    required this.label,
    required this.jam,
    required this.onPilih,
  });

  final String label;
  final String jam;
  final ValueChanged<String> onPilih;

  @override
  Widget build(BuildContext context) {
    return OutlinedButton(
      onPressed: () async {
        final bagian = jam.split(':');
        final t = await showTimePicker(
          context: context,
          initialTime: TimeOfDay(
            hour: int.tryParse(bagian.first) ?? 7,
            minute: int.tryParse(bagian.last) ?? 0,
          ),
        );
        if (t == null) return;
        onPilih(
          '${t.hour.toString().padLeft(2, '0')}:'
          '${t.minute.toString().padLeft(2, '0')}',
        );
      },
      child: Column(
        children: [
          Text(label, style: Teks.kecil),
          Text(jam, style: Teks.judul),
        ],
      ),
    );
  }
}

/* ------------------------------------------------------------------ */

/// Pengaturan yang menempel di PERANGKAT, bukan di data toko: printer struk,
/// alamat server, dan versi aplikasi. Tidak ada padanannya di web karena di
/// sana ketiganya memang tidak relevan.
class _TabTablet extends ConsumerStatefulWidget {
  const _TabTablet();

  @override
  ConsumerState<_TabTablet> createState() => _TabTabletState();
}

class _TabTabletState extends ConsumerState<_TabTablet> {
  List<PrinterInfo>? _printer;
  String? _terpilih;
  bool _memuat = false;
  String _versi = '';

  @override
  void initState() {
    super.initState();
    _muatVersi();
    _muatPrinterTersimpan();
  }

  Future<void> _muatVersi() async {
    final info = await PackageInfo.fromPlatform();
    if (mounted) {
      setState(() => _versi = '${info.version} (build ${info.buildNumber})');
    }
  }

  Future<void> _muatPrinterTersimpan() async {
    final mac = await Pencetak.printerTersimpan();
    if (mounted) setState(() => _terpilih = mac);
  }

  Future<void> _cariPrinter() async {
    setState(() => _memuat = true);
    final daftar = await Pencetak.daftarPrinter();
    if (!mounted) return;
    setState(() {
      _printer = daftar;
      _memuat = false;
    });
    if (daftar.isEmpty && mounted) {
      pesanGagal(
        context,
        'Tidak ada printer terpasang. Pasangkan dulu lewat '
        'Pengaturan Bluetooth Android, lalu cari lagi.',
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final sesi = ref.watch(sesiProvider);

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
      children: [
        Kartu(
          judul: 'Printer struk',
          aksi: TextButton.icon(
            onPressed: _memuat ? null : _cariPrinter,
            icon: const Icon(Icons.bluetooth_searching, size: 18),
            label: Text(_memuat ? 'Mencari...' : 'Cari'),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Printer thermal Bluetooth 58mm. Pasangkan dulu di Pengaturan '
                'Bluetooth Android, baru pilih di sini.',
                style: Teks.kecil,
              ),
              const SizedBox(height: 10),
              if (_terpilih != null)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(12),
                  decoration: kotakKartu(garis: Warna.go),
                  child: Row(
                    children: [
                      const Icon(Icons.print, color: Warna.go),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          'Terpilih: $_terpilih',
                          style: Teks.label,
                        ),
                      ),
                      TextButton(
                        onPressed: () async {
                          await Pencetak.lupakanPrinter();
                          if (mounted) setState(() => _terpilih = null);
                        },
                        child: const Text('Lupakan'),
                      ),
                    ],
                  ),
                ),
              if (_printer != null) ...[
                const SizedBox(height: 10),
                for (final p in _printer!)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.print_outlined),
                    title: Text(p.nama, style: Teks.label),
                    subtitle: Text(p.mac, style: Teks.kecil),
                    trailing: _terpilih == p.mac
                        ? const Icon(Icons.check_circle, color: Warna.go)
                        : null,
                    onTap: () async {
                      await Pencetak.simpanPrinter(p.mac);
                      if (mounted) setState(() => _terpilih = p.mac);
                    },
                  ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 12),
        Kartu(
          judul: 'Server',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(sesi.baseUrl, style: Teks.label),
              const SizedBox(height: 6),
              const Text(
                'Alamat server cucian ini. Ganti hanya kalau tablet dipindah '
                'ke cabang lain — semua data ikut pindah ke server baru.',
                style: Teks.kecil,
              ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: () async {
                  final yakin = await konfirmasi(
                    context,
                    judul: 'Ganti server?',
                    pesan: 'Kamu akan keluar dari akun dan harus mengisi '
                        'alamat server lagi.',
                    tombolYa: 'Ganti',
                  );
                  if (!yakin) return;
                  await ref.read(sesiProvider.notifier).keluar();
                  await ref.read(sesiProvider.notifier).setServer('');
                },
                icon: const Icon(Icons.dns_outlined),
                label: const Text('Ganti server'),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Kartu(
          judul: 'Tentang aplikasi',
          child: Column(
            children: [
              BarisNilai(
                label: 'Versi',
                nilai: _versi.isEmpty ? '...' : _versi,
              ),
              BarisNilai(label: 'Masuk sebagai', nilai: sesi.nama),
              BarisNilai(label: 'Hak akses', nilai: sesi.role.toUpperCase()),
            ],
          ),
        ),
      ],
    );
  }
}

/* ------------------------------------------------------------------ */

typedef _IsianBaru = ({String nama, int harga, String? jenis, int? stok});

/// Dialog "nama + harga" yang dipakai add-on dan menu F&B. Digabung karena
/// bentuknya persis sama; [pakaiJenis] menambah pilihan makanan/minuman &
/// stok awal untuk menu F&B.
Future<_IsianBaru?> _dialogNamaHarga(
  BuildContext context, {
  required String judul,
  bool pakaiJenis = false,
}) async {
  final nama = TextEditingController();
  final harga = TextEditingController();
  final stok = TextEditingController(text: '0');
  var jenis = 'minuman';

  final ok = await showDialog<bool>(
    context: context,
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, setSt) => AlertDialog(
        title: Text(judul, style: Teks.judul),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: nama,
              autofocus: true,
              textCapitalization: TextCapitalization.words,
              decoration: const InputDecoration(labelText: 'Nama'),
            ),
            const SizedBox(height: 10),
            IsianUang(controller: harga, label: 'Harga'),
            if (pakaiJenis) ...[
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: TombolPilih(
                      lebarPenuh: true,
                      teks: 'Minuman',
                      aktif: jenis == 'minuman',
                      onTap: () => setSt(() => jenis = 'minuman'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: TombolPilih(
                      lebarPenuh: true,
                      teks: 'Makanan',
                      aktif: jenis == 'makanan',
                      onTap: () => setSt(() => jenis = 'makanan'),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              TextField(
                controller: stok,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'Stok awal'),
              ),
            ],
          ],
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

  final hasil = ok == true
      ? (
          nama: nama.text.trim(),
          harga: bacaUang(harga),
          jenis: pakaiJenis ? jenis : null,
          stok: pakaiJenis ? bacaUang(stok) : null,
        )
      : null;

  nama.dispose();
  harga.dispose();
  stok.dispose();
  return hasil;
}
