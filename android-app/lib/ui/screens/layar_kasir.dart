import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../data/models/katalog.dart';
import '../../data/models/transaksi.dart';
import '../../data/repositories/repo.dart';
import '../../state/providers.dart';
import '../kerangka.dart';
import '../widgets/kartu.dart';
import '../widgets/pemuat.dart';
import '../widgets/siluet.dart';
import 'layar_ai.dart';
import 'layar_konfirmasi.dart';

/// Layar kerja utama kasir: cari kendaraan, atau pilih jenisnya langsung,
/// lalu lanjut ke layar konfirmasi. Padanan `layarHome` di web.
class LayarKasir extends ConsumerStatefulWidget {
  const LayarKasir({super.key});

  @override
  ConsumerState<LayarKasir> createState() => _LayarKasirState();
}

class _LayarKasirState extends ConsumerState<LayarKasir> {
  final _cari = TextEditingController();
  Timer? _tunda;
  List<Kendaraan> _hasil = const [];
  bool _mencari = false;

  /// Pencarian terakhir yang benar-benar dikirim ke server. Dipakai untuk
  /// membuang jawaban yang datang terlambat: kasir mengetik cepat, dan
  /// jawaban untuk "ava" bisa tiba SETELAH jawaban untuk "avanza" —
  /// tanpa penjaga ini, layar menampilkan hasil kata yang sudah basi.
  String _terakhirDikirim = '';

  @override
  void dispose() {
    _tunda?.cancel();
    _cari.dispose();
    super.dispose();
  }

  /// Menunda pencarian sampai kasir berhenti mengetik. 250 ms cukup untuk
  /// tidak membanjiri server per huruf, tapi belum terasa seperti jeda.
  void _jadwalkanCari(String q) {
    _tunda?.cancel();
    if (q.trim().isEmpty) {
      setState(() {
        _hasil = const [];
        _mencari = false;
      });
      return;
    }
    setState(() => _mencari = true);
    _tunda = Timer(const Duration(milliseconds: 250), () => _cariSekarang(q));
  }

  Future<void> _cariSekarang(String q) async {
    _terakhirDikirim = q;
    try {
      final r = await ref.read(katalogRepoProvider).cariKendaraan(q.trim());
      if (!mounted || _terakhirDikirim != q) return;
      setState(() {
        _hasil = r;
        _mencari = false;
      });
    } catch (e) {
      if (!mounted || _terakhirDikirim != q) return;
      setState(() {
        _hasil = const [];
        _mencari = false;
      });
      pesanGagal(context, e);
    }
  }

  void _pilih({String? nama, required String kategori}) {
    ref.read(kasirProvider.notifier).pilihKendaraan(
          nama: nama,
          kategori: kategori,
        );
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const LayarKonfirmasi()),
    );
  }

  /// Kasir memilih jenis kendaraan sendiri karena pencarian tidak menemukan
  /// apa pun. Kata yang gagal dicatat ke server supaya owner tahu kendaraan
  /// apa yang perlu ditambahkan ke database.
  void _pilihManual(String kategori) {
    final q = _cari.text.trim();
    if (q.isNotEmpty) {
      ref.read(katalogRepoProvider).catatCarianGagal(q);
    }
    _pilih(kategori: kategori);
  }

  @override
  Widget build(BuildContext context) {
    final konfig = ref.watch(konfigProvider);

    return konfig.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: KotakGagal(
            pesan: '$e',
            onCoba: () => ref.invalidate(konfigProvider),
          ),
        ),
      ),
      data: (cfg) => RefreshIndicator(
        onRefresh: () async {
          ref
            ..invalidate(konfigProvider)
            ..invalidate(draftCuciProvider);
        },
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
          children: [
            _kotakCari(),
            const SizedBox(height: 16),
            if (_cari.text.trim().isNotEmpty) _blokHasil(cfg),
            _blokKategori(cfg),
            const SizedBox(height: 16),
            _blokDraft(cfg),
          ],
        ),
      ),
    );
  }

  Widget _kotakCari() {
    return TextField(
      controller: _cari,
      onChanged: _jadwalkanCari,
      textCapitalization: TextCapitalization.words,
      style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
      decoration: InputDecoration(
        hintText: 'Cari kendaraan... (mis. avanza)',
        prefixIcon: const Icon(Icons.search, size: 26),
        suffixIcon: _cari.text.isEmpty
            ? null
            : IconButton(
                icon: const Icon(Icons.close),
                tooltip: 'Hapus pencarian',
                onPressed: () {
                  _cari.clear();
                  _jadwalkanCari('');
                },
              ),
      ),
    );
  }

  Widget _blokHasil(Konfig cfg) {
    if (_mencari) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 24),
        child: Center(child: CircularProgressIndicator()),
      );
    }

    if (_hasil.isEmpty) {
      return Padding(
        padding: const EdgeInsets.only(bottom: 16),
        child: Kartu(
          child: Column(
            children: [
              const Kosong(
                pesan: 'Kendaraan tidak ditemukan.\n'
                    'Pilih jenisnya di bawah, atau tanya AI.',
                ikon: Icons.search_off,
              ),
              const SizedBox(height: 4),
              OutlinedButton.icon(
                onPressed: () => _tanyaAi(cfg),
                icon: const Icon(Icons.auto_awesome, color: Warna.waterDk),
                label: const Text('Tanya AI kendaraan ini'),
              ),
            ],
          ),
        ),
      );
    }

    return Column(
      children: [
        for (final k in _hasil) ...[
          _KartuKendaraan(
            nama: k.nama,
            kategori: cfg.kategori[k.kategori],
            onTap: () => _pilih(nama: k.nama, kategori: k.kategori),
          ),
          const SizedBox(height: 10),
        ],
        const SizedBox(height: 6),
      ],
    );
  }

  Future<void> _tanyaAi(Konfig cfg) async {
    final hasil = await showDialog<Kendaraan>(
      context: context,
      builder: (_) => DialogAi(namaAwal: _cari.text.trim(), konfig: cfg),
    );
    if (hasil == null || !mounted) return;
    // Kendaraan sudah tersimpan di server oleh dialog — lain kali ketemu di
    // pencarian biasa tanpa AI lagi.
    _pilih(nama: hasil.nama, kategori: hasil.kategori);
  }

  Widget _blokKategori(Konfig cfg) {
    final kategori = cfg.kategori.values.toList(growable: false);

    return Kartu(
      judul: 'Pilih jenis kendaraan',
      child: LayoutBuilder(
        builder: (context, c) {
          // Tablet mendatar muat 3 kolom; HP/tablet kecil 2. Dihitung dari
          // lebar sebenarnya, bukan dari tebakan jenis perangkat.
          final kolom = c.maxWidth > 620 ? 3 : 2;
          return GridView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: kategori.length,
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: kolom,
              crossAxisSpacing: 10,
              mainAxisSpacing: 10,
              childAspectRatio: 1.35,
            ),
            itemBuilder: (_, i) {
              final k = kategori[i];
              return _UbinKategori(
                kategori: k,
                onTap: () => _pilihManual(k.slug),
              );
            },
          );
        },
      ),
    );
  }

  Widget _blokDraft(Konfig cfg) {
    final drafts = ref.watch(draftCuciProvider);

    return drafts.when(
      loading: () => const SizedBox.shrink(),
      error: (e, _) => KotakGagal(
        pesan: '$e',
        onCoba: () => ref.invalidate(draftCuciProvider),
      ),
      data: (list) {
        if (list.isEmpty) return const SizedBox.shrink();
        return Kartu(
          judul: 'Belum dibayar (${list.length})',
          child: Column(
            children: [
              for (final d in list)
                _BarisDraft(
                  draft: d,
                  kategori: cfg.kategori[d.kategori],
                  onLanjut: () {
                    ref.read(kasirProvider.notifier).muatDraft(d);
                    Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => const LayarKonfirmasi(),
                      ),
                    );
                  },
                  onHapus: () async {
                    final yakin = await konfirmasi(
                      context,
                      judul: 'Hapus draft?',
                      pesan: '${d.namaKendaraan} akan dihapus dari daftar '
                          'belum dibayar.',
                      tombolYa: 'Hapus',
                    );
                    if (!yakin) return;
                    try {
                      await ref
                          .read(transaksiRepoProvider)
                          .hapusDraft(d.id);
                      ref.invalidate(draftCuciProvider);
                    } catch (e) {
                      // `mounted` milik State, bukan `context.mounted`:
                      // context di sini adalah State.context, dan
                      // menjaganya dengan pemeriksaan yang salah membuat
                      // snackbar dipanggil pada layar yang sudah ditutup.
                      if (mounted) pesanGagal(context, e);
                    }
                  },
                ),
            ],
          ),
        );
      },
    );
  }
}

class _KartuKendaraan extends StatelessWidget {
  const _KartuKendaraan({
    required this.nama,
    required this.kategori,
    required this.onTap,
  });

  final String nama;
  final KategoriCuci? kategori;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        decoration: kotakKartu(),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          children: [
            Siluet(bentuk: kategori?.bentuk ?? 'hatch', ukuran: 76),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    nama,
                    style: const TextStyle(
                      fontSize: 19,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    kategori == null
                        ? 'Jenis tidak dikenal'
                        : '${kategori!.label} · mulai ${rp(kategori!.hargaMulai)}',
                    style: Teks.subjudul,
                  ),
                ],
              ),
            ),
            const Icon(Icons.chevron_right, color: Warna.redup),
          ],
        ),
      ),
    );
  }
}

class _UbinKategori extends StatelessWidget {
  const _UbinKategori({required this.kategori, required this.onTap});

  final KategoriCuci kategori;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        decoration: kotakKartu(),
        padding: const EdgeInsets.all(10),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Expanded(
              child: FittedBox(
                child: Siluet(bentuk: kategori.bentuk, ukuran: 120),
              ),
            ),
            const SizedBox(height: 6),
            Text(
              kategori.label,
              textAlign: TextAlign.center,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w900),
            ),
            Text(
              'mulai ${rp(kategori.hargaMulai)}',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Teks.kecil,
            ),
          ],
        ),
      ),
    );
  }
}

class _BarisDraft extends StatelessWidget {
  const _BarisDraft({
    required this.draft,
    required this.kategori,
    required this.onLanjut,
    required this.onHapus,
  });

  final DraftCuci draft;
  final KategoriCuci? kategori;
  final VoidCallback onLanjut;
  final VoidCallback onHapus;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        onTap: onLanjut,
        borderRadius: BorderRadius.circular(14),
        child: Container(
          decoration: kotakKartu(garis: Warna.tag, radius: 14),
          padding: const EdgeInsets.fromLTRB(14, 10, 6, 10),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      draft.namaKendaraan,
                      style: const TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Wrap(
                      spacing: 6,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        if (draft.plat != null)
                          Cip(teks: draft.plat!, latar: Warna.mist),
                        if (kategori != null) Cip(teks: kategori!.label),
                        if (draft.jumlahFnb > 0)
                          Cip(
                            teks: '${draft.jumlahFnb} item',
                            ikon: Icons.restaurant,
                          ),
                        if (draft.dibuatPada != null)
                          Text(jam(draft.dibuatPada!), style: Teks.kecil),
                      ],
                    ),
                  ],
                ),
              ),
              IconButton(
                icon: const Icon(Icons.delete_outline, color: Warna.danger),
                tooltip: 'Hapus draft',
                onPressed: onHapus,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Menampilkan kegagalan sebagai snackbar. Dipakai seluruh app supaya kasir
/// selalu melihat pesan error di tempat yang sama, bukan kadang dialog kadang
/// teks merah yang terselip di tengah layar.
void pesanGagal(BuildContext context, Object e) {
  ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(
      content: Text('$e'),
      backgroundColor: Warna.danger,
      duration: const Duration(seconds: 4),
    ),
  );
}

/// Kabar baik — hijau, dan lebih singkat, karena tidak perlu dibaca lama.
void pesanSukses(BuildContext context, String teks) {
  ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(
      content: Text(teks),
      backgroundColor: Warna.go,
      duration: const Duration(seconds: 2),
    ),
  );
}
