import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../data/models/katalog.dart';
import '../../data/models/laporan.dart';
import '../../state/providers.dart';
import '../kerangka.dart';
import '../widgets/kartu.dart';
import '../widgets/pemuat.dart';
import 'layar_kasir.dart';

/// Rekap hari ini. Padanan `layarRekap` di web.
///
/// SEMUA angka datang dari `/api/reports/daily` — app tidak menjumlahkan
/// sendiri. Kalau app ikut berhitung, satu hari akan punya dua versi "omzet"
/// yang beda beberapa ribu rupiah dan tidak ada yang tahu mana yang benar.
class LayarRekap extends ConsumerStatefulWidget {
  const LayarRekap({super.key});

  @override
  ConsumerState<LayarRekap> createState() => _LayarRekapState();
}

class _LayarRekapState extends ConsumerState<LayarRekap> {
  /// null = semua buku digabung. Selain itu, rekap disaring ke satu buku kas.
  int? _bukuPilih;

  @override
  Widget build(BuildContext context) {
    final kunci = (tanggal: hariIni(), idBuku: _bukuPilih);
    final rekap = ref.watch(rekapProvider(kunci));
    final konfig = ref.watch(konfigProvider).valueOrNull;
    final owner = ref.watch(sesiProvider).owner;

    return rekap.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: KotakGagal(
            pesan: '$e',
            onCoba: () => ref.invalidate(rekapProvider(kunci)),
          ),
        ),
      ),
      data: (h) => RefreshIndicator(
        onRefresh: () async => ref.invalidate(rekapProvider(kunci)),
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
          children: [
            _statistik(h),
            const SizedBox(height: 14),
            if (h.buku.isNotEmpty) ...[
              _tabBuku(h.buku),
              const SizedBox(height: 14),
              _aksiBuku(h.buku),
              const SizedBox(height: 14),
            ],
            _kartuCuci(h, konfig),
            const SizedBox(height: 14),
            _kartuFnb(h),
            const SizedBox(height: 14),
            _kartuPengeluaran(h),
            if (owner) ...[
              const SizedBox(height: 14),
              _antreanPembatalan(),
              const SizedBox(height: 14),
              _antreanSetoran(),
            ],
          ],
        ),
      ),
    );
  }

  Widget _statistik(RekapHarian h) {
    return Row(
      children: [
        Expanded(
          child: KotakStatistik(
            label: 'Omzet',
            nilai: rp(h.omzet),
            ikon: Icons.trending_up,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: KotakStatistik(
            label: 'Kendaraan',
            nilai: '${h.jumlahKendaraan}',
            ikon: Icons.local_car_wash,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: KotakStatistik(
            label: 'Laba bersih',
            nilai: rp(h.laba),
            warna: h.laba < 0 ? Warna.danger : Warna.go,
            ikon: Icons.savings_outlined,
          ),
        ),
      ],
    );
  }

  Widget _tabBuku(List<BukuKas> buku) {
    return Kartu(
      judul: 'Buku kas',
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          _cipBuku('Semua', _bukuPilih == null, () {
            setState(() => _bukuPilih = null);
          }),
          for (final b in buku)
            _cipBuku(
              '${b.label} · ${b.labelStatus}',
              _bukuPilih == b.id,
              () => setState(() => _bukuPilih = b.id),
            ),
        ],
      ),
    );
  }

  Widget _cipBuku(String teks, bool aktif, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(20),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
        decoration: BoxDecoration(
          color: aktif ? Warna.ink : Warna.mist,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: aktif ? Warna.ink : Warna.line, width: 2),
        ),
        child: Text(
          teks,
          style: TextStyle(
            color: aktif ? Colors.white : Warna.ink2,
            fontSize: 13,
            fontWeight: FontWeight.w800,
          ),
        ),
      ),
    );
  }

  /// Panel aksi untuk buku yang sedang dipilih: catat saldo kas kecil, dan
  /// ajukan setoran. Buku "Semua" tidak punya aksi — setoran selalu per buku.
  Widget _aksiBuku(List<BukuKas> buku) {
    if (_bukuPilih == null) return const SizedBox.shrink();

    final cocok = buku.where((x) => x.id == _bukuPilih).toList();
    if (cocok.isEmpty) return const SizedBox.shrink();
    final b = cocok.first;

    return Kartu(
      judul: b.label,
      aksi: Cip(
        teks: b.labelStatus.toUpperCase(),
        latar: switch (b.status) {
          'deposited' => Warna.goPudar,
          'pending' => Warna.tagPudar,
          'rejected' => Warna.dangerPudar,
          _ => Warna.mist,
        },
        tinta: switch (b.status) {
          'deposited' => Warna.goDk,
          'pending' => Warna.tagInk,
          'rejected' => Warna.danger,
          _ => Warna.ink2,
        },
      ),
      child: Column(
        children: [
          BarisNilai(label: 'Omzet buku', nilai: rp(b.omzet)),
          BarisNilai(
            label: 'Kas kecil awal',
            nilai: b.saldoAwal == null ? 'belum diisi' : rp(b.saldoAwal),
            subLabel: 'Dicatat manual, tidak menyentuh omzet/laba',
          ),
          if (b.sisaSaldo != null)
            BarisNilai(label: 'Sisa kas kecil', nilai: rp(b.sisaSaldo)),
          BarisNilai(
            label: 'Harus disetor',
            nilai: rp(b.setoran),
            tebal: true,
            subLabel: b.terbuka
                ? 'Perkiraan hidup — dibekukan saat diajukan'
                : 'Angka beku sejak pengajuan',
          ),
          if (b.kasir.isNotEmpty)
            BarisNilai(label: 'Kasir', nilai: b.kasir.join(', ')),
          if (b.ditolak && b.alasanTolak != null)
            BarisNilai(
              label: 'Ditolak',
              nilai: b.alasanTolak!,
              warna: Warna.danger,
            ),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () => _isiSaldoAwal(b),
                  icon: const Icon(Icons.account_balance_wallet_outlined),
                  label: const Text('Kas kecil'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: FilledButton.icon(
                  // Buku yang sudah diajukan/disetor tidak boleh diajukan
                  // lagi — server menolaknya, dan tombol yang tetap hidup
                  // hanya membuat kasir mengira ada yang gagal.
                  onPressed: b.terbuka ? () => _ajukanSetoran(b) : null,
                  icon: const Icon(Icons.upload),
                  label: const Text('Ajukan setoran'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Future<void> _isiSaldoAwal(BukuKas b) async {
    final c = TextEditingController(
      text: b.saldoAwal == null ? '' : '${b.saldoAwal}',
    );
    final hasil = await showDialog<int>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Kas kecil awal', style: Teks.judul),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'Uang receh di laci saat buku ini dibuka. Murni catatan '
              'untuk kasir — tidak menyentuh omzet maupun laba.',
              style: Teks.subjudul,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: c,
              autofocus: true,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(prefixText: 'Rp '),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Batal'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(
              ctx,
              int.tryParse(c.text.replaceAll(RegExp(r'[^\d]'), '')) ?? 0,
            ),
            child: const Text('Simpan'),
          ),
        ],
      ),
    );
    c.dispose();
    if (hasil == null) return;

    try {
      await ref.read(laporanRepoProvider).setSaldoAwal(b.id, hasil);
      _muatUlang();
      if (mounted) pesanSukses(context, 'Kas kecil tersimpan');
    } catch (e) {
      if (mounted) pesanGagal(context, e);
    }
  }

  Future<void> _ajukanSetoran(BukuKas b) async {
    final yakin = await konfirmasi(
      context,
      judul: 'Ajukan setoran ${rp(b.setoran)}?',
      pesan: 'Angka setoran dibekukan sekarang dan menunggu persetujuan '
          'owner. Setelah diajukan, buku ini ditutup.',
      tombolYa: 'Ajukan',
      merah: false,
    );
    if (!yakin) return;

    try {
      await ref.read(laporanRepoProvider).ajukanSetoran(b.id);
      _muatUlang();
      if (mounted) pesanSukses(context, 'Setoran diajukan');
    } catch (e) {
      if (mounted) pesanGagal(context, e);
    }
  }

  Widget _kartuCuci(RekapHarian h, Konfig? cfg) {
    return Kartu(
      judul: 'Cucian',
      child: Column(
        children: [
          BarisNilai(label: 'Total cuci', nilai: rp(h.totalCuci)),
          BarisNilai(
            label: 'Tip',
            nilai: h.tip > 0 ? rp(h.tip) : 'kosong',
            warna: h.tip > 0 ? Warna.go : Warna.redup,
          ),
          // Baris cara bayar bisa dibuka: "Cash Rp 330.000" saja tidak
          // menjawab pertanyaan yang muncul saat menghitung uang laci —
          // motor berapa, mobil berapa.
          for (final r in h.rincianBayar)
            _BarisBisaDibuka(rincian: r, konfig: cfg),
          BarisNilai(
            label: 'Upah pekerja',
            nilai: '-${rp(h.upah)}',
            warna: Warna.danger,
          ),
          const Divider(height: 18),
          BarisNilai(
            label: 'Laba cucian',
            nilai: rp(h.labaCuci),
            warna: Warna.go,
            tebal: true,
          ),
        ],
      ),
    );
  }

  Widget _kartuFnb(RekapHarian h) {
    return Kartu(
      judul: 'Makanan & minuman',
      child: Column(
        children: [
          BarisNilai(label: 'Total F&B', nilai: rp(h.fnbTotal)),
          BarisNilai(
            label: 'Cash',
            nilai: h.fnbCash > 0 ? rp(h.fnbCash) : 'kosong',
          ),
          BarisNilai(
            label: 'Transfer',
            nilai: h.fnbTf > 0 ? rp(h.fnbTf) : 'kosong',
          ),
        ],
      ),
    );
  }

  Widget _kartuPengeluaran(RekapHarian h) {
    return Kartu(
      judul: 'Pengeluaran',
      child: Column(
        children: [
          if (h.daftarPengeluaran.isEmpty)
            const Kosong(pesan: 'Belum ada pengeluaran hari ini.')
          else
            for (final e in h.daftarPengeluaran)
              BarisNilai(
                label: e.keterangan,
                nilai: '-${rp(e.jumlah)}',
                warna: Warna.danger,
                subLabel: e.dicatatOleh,
              ),
          const Divider(height: 18),
          BarisNilai(
            label: 'Total pengeluaran',
            nilai: h.pengeluaran > 0 ? '-${rp(h.pengeluaran)}' : rp(0),
            warna: Warna.danger,
            tebal: true,
          ),
        ],
      ),
    );
  }

  Widget _antreanPembatalan() {
    final antre = ref.watch(antreanVoidProvider);

    return antre.when(
      loading: () => const SizedBox.shrink(),
      error: (_, __) => const SizedBox.shrink(),
      data: (list) {
        if (list.isEmpty) return const SizedBox.shrink();
        return Kartu(
          judul: 'Minta pembatalan (${list.length})',
          child: Column(
            children: [
              for (final t in list)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '#${t.noAntrian} · ${t.namaKendaraan} · ${rp(t.total)}',
                        style: Teks.label,
                      ),
                      if (t.alasanVoid != null)
                        Text('Alasan: ${t.alasanVoid}', style: Teks.kecil),
                      const SizedBox(height: 6),
                      Row(
                        children: [
                          Expanded(
                            child: OutlinedButton(
                              onPressed: () => _tolakVoid(t.id),
                              child: const Text('Tolak'),
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: FilledButton(
                              style: FilledButton.styleFrom(
                                backgroundColor: Warna.danger,
                              ),
                              onPressed: () => _setujuiVoid(t.id),
                              child: const Text('Setujui batal'),
                            ),
                          ),
                        ],
                      ),
                      const Divider(height: 20),
                    ],
                  ),
                ),
            ],
          ),
        );
      },
    );
  }

  Widget _antreanSetoran() {
    final antre = ref.watch(setoranMenungguProvider);

    return antre.when(
      loading: () => const SizedBox.shrink(),
      error: (_, __) => const SizedBox.shrink(),
      data: (list) {
        if (list.isEmpty) return const SizedBox.shrink();
        return Kartu(
          judul: 'Setoran menunggu persetujuan (${list.length})',
          child: Column(
            children: [
              for (final b in list)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${b.label} · ${rp(b.setoran)}',
                        style: Teks.label,
                      ),
                      Text(
                        'Diajukan ${b.diajukanOleh ?? '-'}'
                        '${b.kasir.isEmpty ? '' : ' · kasir ${b.kasir.join(', ')}'}',
                        style: Teks.kecil,
                      ),
                      const SizedBox(height: 6),
                      Row(
                        children: [
                          Expanded(
                            child: OutlinedButton(
                              onPressed: () => _tolakSetoran(b.id),
                              child: const Text('Tolak'),
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: FilledButton(
                              onPressed: () => _setujuiSetoran(b.id),
                              child: const Text('Setujui'),
                            ),
                          ),
                        ],
                      ),
                      const Divider(height: 20),
                    ],
                  ),
                ),
            ],
          ),
        );
      },
    );
  }

  void _muatUlang() {
    ref
      ..invalidate(rekapProvider((tanggal: hariIni(), idBuku: _bukuPilih)))
      ..invalidate(rekapHariIniProvider)
      ..invalidate(setoranMenungguProvider)
      ..invalidate(antreanVoidProvider);
  }

  Future<void> _setujuiVoid(int id) async {
    try {
      await ref.read(transaksiRepoProvider).setujuiVoid(id);
      _muatUlang();
      if (mounted) pesanSukses(context, 'Transaksi dibatalkan');
    } catch (e) {
      if (mounted) pesanGagal(context, e);
    }
  }

  Future<void> _tolakVoid(int id) async {
    final alasan = await _tanyaTeks('Alasan menolak pembatalan');
    if (alasan == null) return;
    try {
      await ref.read(transaksiRepoProvider).tolakVoid(id, alasan);
      _muatUlang();
      if (mounted) pesanSukses(context, 'Pengajuan ditolak');
    } catch (e) {
      if (mounted) pesanGagal(context, e);
    }
  }

  Future<void> _setujuiSetoran(int id) async {
    try {
      await ref.read(laporanRepoProvider).setujuiSetoran(id);
      _muatUlang();
      if (mounted) pesanSukses(context, 'Setoran disetujui');
    } catch (e) {
      if (mounted) pesanGagal(context, e);
    }
  }

  Future<void> _tolakSetoran(int id) async {
    final alasan = await _tanyaTeks('Alasan menolak setoran');
    if (alasan == null) return;
    try {
      await ref.read(laporanRepoProvider).tolakSetoran(id, alasan);
      _muatUlang();
      if (mounted) pesanSukses(context, 'Setoran ditolak');
    } catch (e) {
      if (mounted) pesanGagal(context, e);
    }
  }

  Future<String?> _tanyaTeks(String judul) async {
    final c = TextEditingController();
    final hasil = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(judul, style: Teks.judul),
        content: TextField(
          controller: c,
          autofocus: true,
          maxLength: 120,
          decoration: const InputDecoration(hintText: 'Alasan (wajib)'),
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
    return hasil;
  }
}

/// Baris cara bayar yang bisa dibuka untuk melihat pecahannya per jenis
/// kendaraan.
class _BarisBisaDibuka extends StatefulWidget {
  const _BarisBisaDibuka({required this.rincian, required this.konfig});

  final RincianBayar rincian;
  final Konfig? konfig;

  @override
  State<_BarisBisaDibuka> createState() => _BarisBisaDibukaState();
}

class _BarisBisaDibukaState extends State<_BarisBisaDibuka> {
  bool _buka = false;

  @override
  Widget build(BuildContext context) {
    final r = widget.rincian;
    return Column(
      children: [
        InkWell(
          onTap: () => setState(() => _buka = !_buka),
          child: Row(
            children: [
              Icon(
                _buka ? Icons.expand_less : Icons.expand_more,
                size: 20,
                color: Warna.ink2,
              ),
              const SizedBox(width: 4),
              Expanded(
                child: BarisNilai(
                  label: r.label,
                  nilai: rp(r.total),
                  subLabel: '${r.jumlah} kendaraan',
                ),
              ),
            ],
          ),
        ),
        if (_buka)
          Padding(
            padding: const EdgeInsets.only(left: 24, bottom: 6),
            child: Column(
              children: [
                for (final b in r.baris)
                  BarisNilai(
                    label: widget.konfig?.kategori[b.kategori]?.label ??
                        b.kategori,
                    nilai: rp(b.total),
                    subLabel: '${b.jumlah}x',
                  ),
              ],
            ),
          ),
      ],
    );
  }
}
