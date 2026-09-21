import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../data/models/fnb.dart';
import '../../state/providers.dart';
import '../kerangka.dart';
import '../widgets/isian.dart';
import '../widgets/kartu.dart';
import '../widgets/pemuat.dart';
import 'layar_kasir.dart';

/// Jual makanan & minuman berdiri sendiri (bukan yang menempel di cucian).
/// Padanan `layarFnb` di web.
class LayarFnb extends ConsumerStatefulWidget {
  const LayarFnb({super.key});

  @override
  ConsumerState<LayarFnb> createState() => _LayarFnbState();
}

class _LayarFnbState extends ConsumerState<LayarFnb> {
  final _tip = TextEditingController();
  bool _menyimpan = false;

  @override
  void dispose() {
    _tip.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final produk = ref.watch(produkProvider);

    return produk.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: KotakGagal(
            pesan: '$e',
            onCoba: () => ref.invalidate(produkProvider),
          ),
        ),
      ),
      data: (semua) {
        final jual = semua.where((p) => p.bisaDijual).toList(growable: false);
        return RefreshIndicator(
          onRefresh: () async {
            ref
              ..invalidate(produkProvider)
              ..invalidate(draftFnbProvider)
              ..invalidate(penjualanFnbProvider(hariIni()));
          },
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
            children: [
              _gridMenu(jual),
              const SizedBox(height: 14),
              _keranjang(jual),
              const SizedBox(height: 14),
              _daftarDraft(),
              const SizedBox(height: 14),
              _riwayat(),
            ],
          ),
        );
      },
    );
  }

  Widget _gridMenu(List<Produk> jual) {
    if (jual.isEmpty) {
      return const Kartu(
        judul: 'Menu',
        child: Kosong(
          pesan: 'Belum ada menu yang bisa dijual.\n'
              'Tambah menu atau isi stoknya di Pengaturan.',
          ikon: Icons.restaurant_outlined,
        ),
      );
    }

    final keranjang = ref.watch(keranjangFnbProvider);

    return Kartu(
      judul: 'Menu',
      child: LayoutBuilder(
        builder: (context, c) {
          final kolom = c.maxWidth > 620 ? 4 : 2;
          return GridView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: jual.length,
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: kolom,
              crossAxisSpacing: 10,
              mainAxisSpacing: 10,
              childAspectRatio: 1.15,
            ),
            itemBuilder: (_, i) {
              final p = jual[i];
              final qty = keranjang.items[p.id] ?? 0;
              return InkWell(
                onTap: qty >= p.stok
                    ? () => pesanGagal(
                          context,
                          'Stok ${p.nama} tinggal ${p.stok}.',
                        )
                    : () => ref
                        .read(keranjangFnbProvider.notifier)
                        .ubahQty(p.id, 1),
                borderRadius: BorderRadius.circular(16),
                child: Container(
                  padding: const EdgeInsets.all(10),
                  decoration: kotakKartu(
                    garis: qty > 0 ? Warna.go : Warna.line,
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(
                        p.jenis == 'makanan'
                            ? Icons.lunch_dining
                            : Icons.local_cafe,
                        size: 30,
                        color: Warna.water,
                      ),
                      const SizedBox(height: 6),
                      Text(
                        p.nama,
                        textAlign: TextAlign.center,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      Text(rp(p.harga), style: Teks.kecil),
                      if (qty > 0)
                        Container(
                          margin: const EdgeInsets.only(top: 4),
                          padding: const EdgeInsets.symmetric(
                            horizontal: 10,
                            vertical: 2,
                          ),
                          decoration: BoxDecoration(
                            color: Warna.go,
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Text(
                            'x$qty',
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 12,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }

  Widget _keranjang(List<Produk> jual) {
    final k = ref.watch(keranjangFnbProvider);
    final total = k.total(jual);

    return Kartu(
      judul: k.idDraft == null
          ? 'Pesanan'
          : 'Pesanan · melanjutkan draft',
      aksi: k.kosong
          ? null
          : TextButton(
              onPressed: () {
                ref.read(keranjangFnbProvider.notifier).bersihkan();
                _tip.clear();
              },
              child: const Text('Kosongkan'),
            ),
      child: Column(
        children: [
          if (k.kosong)
            const Kosong(
              pesan: 'Ketuk menu di atas untuk menambah pesanan.',
              ikon: Icons.shopping_cart_outlined,
            )
          else ...[
            for (final e in k.items.entries)
              _barisKeranjang(cariProduk(jual, e.key), e.key, e.value),
            const Divider(height: 20),
            Row(
              children: [
                Expanded(
                  child: TombolPilih(
                    lebarPenuh: true,
                    teks: 'Cash',
                    ikon: Icons.payments,
                    aktif: k.caraBayar == 'cash',
                    onTap: () => ref
                        .read(keranjangFnbProvider.notifier)
                        .setCaraBayar('cash'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: TombolPilih(
                    lebarPenuh: true,
                    teks: 'Transfer',
                    ikon: Icons.qr_code,
                    aktif: k.caraBayar == 'tf',
                    onTap: () => ref
                        .read(keranjangFnbProvider.notifier)
                        .setCaraBayar('tf'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            IsianUang(controller: _tip, label: 'Tip (boleh kosong)'),
            const SizedBox(height: 14),
            Row(
              children: [
                const Text('Total', style: Teks.label),
                const Spacer(),
                Text(rp(total), style: Teks.angkaBesar),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _menyimpan ? null : _simpanDraft,
                    icon: const Icon(Icons.bookmark_border),
                    label: Text(
                      k.idDraft == null ? 'Simpan draft' : 'Perbarui draft',
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: FilledButton.icon(
                    onPressed: _menyimpan ? null : _jual,
                    icon: _menyimpan
                        ? const SizedBox(
                            height: 20,
                            width: 20,
                            child: CircularProgressIndicator(
                              strokeWidth: 2.5,
                              color: Colors.white,
                            ),
                          )
                        : const Icon(Icons.check),
                    label: Text(_menyimpan ? 'Menyimpan...' : 'Bayar'),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _barisKeranjang(Produk? p, int id, int qty) {
    // Menu bisa saja dihapus/dinonaktifkan owner setelah masuk keranjang.
    // Ditampilkan apa adanya sebagai baris yang jelas bermasalah, bukan
    // dibuang diam-diam — kasir harus tahu kenapa totalnya berubah.
    if (p == null) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          children: [
            const Expanded(
              child: Text(
                'Menu sudah dihapus',
                style: TextStyle(color: Warna.danger, fontSize: 14),
              ),
            ),
            IconButton(
              icon: const Icon(Icons.close, color: Warna.danger),
              onPressed: () => ref
                  .read(keranjangFnbProvider.notifier)
                  .ubahQty(id, -qty),
            ),
          ],
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(p.nama, style: Teks.label),
                Text(
                  '${rp(p.harga)} · ${rp(p.harga * qty)}',
                  style: Teks.kecil,
                ),
              ],
            ),
          ),
          IconButton(
            icon: const Icon(Icons.remove_circle_outline),
            onPressed: () =>
                ref.read(keranjangFnbProvider.notifier).ubahQty(p.id, -1),
          ),
          SizedBox(
            width: 34,
            child: Text(
              '$qty',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900),
            ),
          ),
          IconButton(
            icon: const Icon(Icons.add_circle_outline),
            onPressed: qty >= p.stok
                ? null
                : () =>
                    ref.read(keranjangFnbProvider.notifier).ubahQty(p.id, 1),
          ),
        ],
      ),
    );
  }

  Widget _daftarDraft() {
    final drafts = ref.watch(draftFnbProvider);

    return drafts.when(
      loading: () => const SizedBox.shrink(),
      error: (_, __) => const SizedBox.shrink(),
      data: (list) {
        if (list.isEmpty) return const SizedBox.shrink();
        return Kartu(
          judul: 'Pesanan belum dibayar (${list.length})',
          child: Column(
            children: [
              for (final d in list)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(d.judul(jam), style: Teks.label),
                  subtitle: Text(
                    '${d.jumlahItem} item'
                    '${d.tip > 0 ? ' · tip ${rp(d.tip)}' : ''}',
                    style: Teks.kecil,
                  ),
                  trailing: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      TextButton(
                        onPressed: () {
                          ref
                              .read(keranjangFnbProvider.notifier)
                              .muatDraft(d);
                          _tip.text = d.tip > 0 ? '${d.tip}' : '';
                        },
                        child: const Text('Lanjutkan'),
                      ),
                      IconButton(
                        icon: const Icon(
                          Icons.delete_outline,
                          color: Warna.danger,
                        ),
                        onPressed: () async {
                          final yakin = await konfirmasi(
                            context,
                            judul: 'Hapus draft pesanan?',
                            tombolYa: 'Hapus',
                          );
                          if (!yakin) return;
                          try {
                            await ref.read(fnbRepoProvider).hapusDraft(d.id);
                            ref.invalidate(draftFnbProvider);
                          } catch (e) {
                            // `mounted` milik State, bukan `context.mounted`:
                            // context di sini adalah State.context.
                            if (mounted) pesanGagal(context, e);
                          }
                        },
                      ),
                    ],
                  ),
                ),
            ],
          ),
        );
      },
    );
  }

  Widget _riwayat() {
    final riwayat = ref.watch(penjualanFnbProvider(hariIni()));
    final owner = ref.watch(sesiProvider).owner;

    return riwayat.when(
      loading: () => const SizedBox.shrink(),
      error: (_, __) => const SizedBox.shrink(),
      data: (list) {
        // Penjualan yang menempel di cucian sudah tampil di riwayat transaksi
        // cuci; menampilkannya lagi di sini membuat satu penjualan terlihat
        // seperti dua.
        final berdiriSendiri =
            list.where((s) => !s.menempelCucian).toList(growable: false);

        if (berdiriSendiri.isEmpty) {
          return const Kartu(
            judul: 'Penjualan hari ini',
            child: Kosong(pesan: 'Belum ada penjualan F&B hari ini.'),
          );
        }

        return Kartu(
          judul: 'Penjualan hari ini (${berdiriSendiri.length})',
          child: Column(
            children: [
              for (final s in berdiriSendiri)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Text(
                                  s.dibuatPada == null
                                      ? '-'
                                      : jam(s.dibuatPada!),
                                  style: Teks.label,
                                ),
                                const SizedBox(width: 8),
                                Cip(
                                  teks: s.caraBayar == 'cash' ? 'CASH' : 'TF',
                                ),
                                if (s.batal) ...[
                                  const SizedBox(width: 6),
                                  const Cip(
                                    teks: 'BATAL',
                                    latar: Warna.dangerPudar,
                                    tinta: Warna.danger,
                                  ),
                                ] else if (s.menungguVoid) ...[
                                  const SizedBox(width: 6),
                                  const Cip(
                                    teks: 'MENUNGGU BATAL',
                                    latar: Warna.tagPudar,
                                    tinta: Warna.tagInk,
                                  ),
                                ],
                              ],
                            ),
                            Text(s.ringkasItem, style: Teks.kecil),
                          ],
                        ),
                      ),
                      Text(
                        rp(s.total),
                        style: TextStyle(
                          fontWeight: FontWeight.w900,
                          decoration:
                              s.batal ? TextDecoration.lineThrough : null,
                          color: s.batal ? Warna.redup : Warna.ink,
                        ),
                      ),
                      if (!s.batal && !s.menungguVoid)
                        IconButton(
                          tooltip: owner
                              ? 'Batalkan penjualan'
                              : 'Ajukan pembatalan',
                          icon: const Icon(
                            Icons.cancel_outlined,
                            color: Warna.danger,
                          ),
                          onPressed: () => _batalkan(s),
                        ),
                    ],
                  ),
                ),
            ],
          ),
        );
      },
    );
  }

  Future<void> _batalkan(PenjualanFnb s) async {
    final owner = ref.read(sesiProvider).owner;
    final alasan = await _tanyaAlasan(
      judul: owner ? 'Batalkan penjualan' : 'Ajukan pembatalan',
      // Kasir hanya MENGAJUKAN; angka rekap baru berubah setelah owner
      // menyetujui, supaya omzet harian tidak bisa diubah sepihak.
      catatan: owner
          ? null
          : 'Penjualan tetap dihitung di rekap sampai owner menyetujui.',
    );
    if (alasan == null) return;

    try {
      await ref.read(fnbRepoProvider).ajukanVoid(s.id, alasan);
      ref
        ..invalidate(penjualanFnbProvider(hariIni()))
        ..invalidate(rekapHariIniProvider)
        ..invalidate(produkProvider);
      if (mounted) {
        pesanSukses(
          context,
          owner ? 'Penjualan dibatalkan' : 'Pengajuan terkirim ke owner',
        );
      }
    } catch (e) {
      if (mounted) pesanGagal(context, e);
    }
  }

  Future<String?> _tanyaAlasan({
    required String judul,
    String? catatan,
  }) async {
    final c = TextEditingController();
    final hasil = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(judul, style: Teks.judul),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (catatan != null) ...[
              Text(catatan, style: Teks.subjudul),
              const SizedBox(height: 12),
            ],
            TextField(
              controller: c,
              autofocus: true,
              maxLength: 120,
              decoration: const InputDecoration(
                hintText: 'Alasan (wajib)',
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Batal'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: Warna.danger,
              minimumSize: const Size(110, 44),
            ),
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

  Future<void> _simpanDraft() async {
    final k = ref.read(keranjangFnbProvider);
    if (k.kosong) {
      pesanGagal(context, 'Pesanan masih kosong.');
      return;
    }

    setState(() => _menyimpan = true);
    try {
      final repo = ref.read(fnbRepoProvider);
      if (k.idDraft != null) {
        await repo.ubahDraft(k.idDraft!, k.items, tip: bacaUang(_tip));
      } else {
        await repo.simpanDraft(k.items, tip: bacaUang(_tip));
      }
      if (!mounted) return;
      ref.read(keranjangFnbProvider.notifier).bersihkan();
      _tip.clear();
      ref.invalidate(draftFnbProvider);
      setState(() => _menyimpan = false);
      pesanSukses(
        context,
        k.idDraft != null ? 'Draft diperbarui' : 'Tersimpan sebagai draft',
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _menyimpan = false);
      pesanGagal(context, e);
    }
  }

  Future<void> _jual() async {
    final k = ref.read(keranjangFnbProvider);
    if (k.kosong) {
      pesanGagal(context, 'Pesanan masih kosong.');
      return;
    }

    setState(() => _menyimpan = true);
    try {
      await ref.read(fnbRepoProvider).jual(
            keranjang: k.items,
            caraBayar: k.caraBayar,
            tip: bacaUang(_tip),
            idDraft: k.idDraft,
          );
      if (!mounted) return;
      ref.read(keranjangFnbProvider.notifier).bersihkan();
      _tip.clear();
      // Stok berkurang & draft terhapus di server; rekap ikut berubah.
      ref
        ..invalidate(produkProvider)
        ..invalidate(draftFnbProvider)
        ..invalidate(penjualanFnbProvider(hariIni()))
        ..invalidate(rekapHariIniProvider);
      setState(() => _menyimpan = false);
      pesanSukses(context, 'Penjualan tersimpan');
    } catch (e) {
      if (!mounted) return;
      setState(() => _menyimpan = false);
      pesanGagal(context, e);
    }
  }
}
