import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../data/models/fnb.dart';
import '../../data/models/katalog.dart';
import '../../data/models/pekerja.dart';
import '../../state/providers.dart';
import '../widgets/isian.dart';
import '../widgets/kartu.dart';
import '../widgets/pemuat.dart';
import '../widgets/siluet.dart';
import 'layar_kasir.dart';
import 'layar_selesai.dart';

/// Layar konfirmasi sebelum transaksi disimpan. Padanan `layarConfirm` di web.
///
/// Total yang tampil di sini PERKIRAAN. Angka resminya dihitung server saat
/// disimpan, dan itulah yang masuk ke struk & pembukuan — app tidak pernah
/// mengirim total, supaya tablet yang dioprek tidak bisa menentukan harga.
class LayarKonfirmasi extends ConsumerStatefulWidget {
  const LayarKonfirmasi({super.key});

  @override
  ConsumerState<LayarKonfirmasi> createState() => _LayarKonfirmasiState();
}

class _LayarKonfirmasiState extends ConsumerState<LayarKonfirmasi> {
  final _plat = TextEditingController();
  final _tip = TextEditingController();
  bool _menyimpan = false;

  @override
  void initState() {
    super.initState();
    // Draft yang dilanjutkan sudah membawa plat & tip-nya; kolomnya diisi
    // sekali di sini, bukan tiap build — kalau tiap build, kasir tidak bisa
    // mengoreksi isinya karena selalu ditimpa balik.
    final k = ref.read(kasirProvider);
    _plat.text = k.plat;
    if (k.tip > 0) _tip.text = '${k.tip}';
  }

  @override
  void dispose() {
    _plat.dispose();
    _tip.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final konfig = ref.watch(konfigProvider);
    final pekerja = ref.watch(pekerjaProvider);
    final produk = ref.watch(produkProvider);
    final keranjang = ref.watch(kasirProvider);

    return Scaffold(
      appBar: AppBar(
        backgroundColor: Warna.ink,
        foregroundColor: Colors.white,
        title: Text(
          keranjang.lanjutanDraft ? 'Lanjutkan Draft' : 'Konfirmasi',
          style: const TextStyle(fontWeight: FontWeight.w900),
        ),
      ),
      body: konfig.when(
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
        data: (cfg) => _isi(
          cfg,
          pekerja.valueOrNull ?? const [],
          produk.valueOrNull ?? const [],
        ),
      ),
    );
  }

  Widget _isi(Konfig cfg, List<Pekerja> pekerja, List<Produk> produk) {
    final k = ref.watch(kasirProvider);
    final kat = cfg.kategori[k.kategori];

    if (kat == null) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text(
            'Jenis kendaraan ini sudah tidak ada di katalog.\n'
            'Kembali dan pilih jenis yang lain.',
            textAlign: TextAlign.center,
            style: Teks.subjudul,
          ),
        ),
      );
    }

    final tersedia = cfg.layananTersedia(k.kategori);
    // Kategori bisa saja tidak punya layanan yang sedang aktif (mis. baru
    // pindah dari mobil ke motor) — dibetulkan di sini, sekali, bukan
    // dibiarkan mengirim layanan yang tidak ada ke server.
    if (tersedia.isNotEmpty && !tersedia.contains(k.layanan)) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) ref.read(kasirProvider.notifier).setLayanan(tersedia.first);
      });
    }

    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: lebarIsi),
        child: Column(
          children: [
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 20),
                children: [
                  _kepala(k.namaKendaraan ?? kat.label, kat),
                  const SizedBox(height: 16),
                  if (tersedia.length > 1) ...[
                    _blokLayanan(cfg, tersedia, k.layanan),
                    const SizedBox(height: 12),
                  ],
                  if (cfg.addons.isNotEmpty) ...[
                    _blokAddon(cfg, k.addon),
                    const SizedBox(height: 12),
                  ],
                  _blokFnb(produk, k.fnb),
                  const SizedBox(height: 12),
                  _blokPekerja(pekerja, k.pekerja),
                  const SizedBox(height: 12),
                  _blokBayar(k.caraBayar),
                ],
              ),
            ),
            _bilahBawah(cfg, produk, kat),
          ],
        ),
      ),
    );
  }

  Widget _kepala(String nama, KategoriCuci kat) {
    return Kartu(
      child: Row(
        children: [
          Siluet(bentuk: kat.bentuk, ukuran: 110),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  nama,
                  style: const TextStyle(
                    fontSize: 21,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  '${kat.label} · mulai ${rp(kat.hargaMulai)}',
                  style: Teks.subjudul,
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: _plat,
                  inputFormatters: const [FormatterPlat()],
                  textCapitalization: TextCapitalization.characters,
                  onChanged: (v) =>
                      ref.read(kasirProvider.notifier).setPlat(v),
                  style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w900,
                    letterSpacing: 1.5,
                  ),
                  decoration: const InputDecoration(
                    hintText: 'Plat nomor (boleh kosong)',
                    isDense: true,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _blokLayanan(Konfig cfg, List<String> tersedia, String aktif) {
    return Kartu(
      judul: 'Jenis cucian',
      child: Column(
        children: [
          for (final id in tersedia)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: TombolPilih(
                lebarPenuh: true,
                teks: cfg.layanan[id]?.label ?? id,
                subTeks: rp(cfg.harga(_kategori, id)),
                aktif: aktif == id,
                onTap: () => ref.read(kasirProvider.notifier).setLayanan(id),
              ),
            ),
        ],
      ),
    );
  }

  String get _kategori => ref.read(kasirProvider).kategori;

  Widget _blokAddon(Konfig cfg, Set<int> dipilih) {
    return Kartu(
      judul: 'Layanan tambahan',
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final a in cfg.addons)
            TombolPilih(
              teks: a.nama,
              subTeks: '+${rp(a.harga)}',
              aktif: dipilih.contains(a.id),
              onTap: () => ref.read(kasirProvider.notifier).toggleAddon(a.id),
            ),
        ],
      ),
    );
  }

  Widget _blokPekerja(List<Pekerja> pekerja, Set<int> dipilih) {
    return Kartu(
      judul: 'Dikerjakan oleh',
      aksi: Text('${dipilih.length} dipilih', style: Teks.kecil),
      child: pekerja.isEmpty
          ? const Kosong(
              pesan: 'Belum ada pekerja. Tambahkan lewat menu '
                  'Pekerja & Upah. (Boleh dilewati)',
              ikon: Icons.groups_outlined,
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final p in pekerja)
                      TombolPilih(
                        teks: p.nama,
                        subTeks: p.training ? 'training' : null,
                        aktif: dipilih.contains(p.id),
                        onTap: () => ref
                            .read(kasirProvider.notifier)
                            .togglePekerja(p.id),
                      ),
                  ],
                ),
                const SizedBox(height: 8),
                const Text(
                  'Upah dibagi rata ke pekerja yang dipilih. '
                  'Boleh dikosongkan kalau belum tahu siapa yang mengerjakan.',
                  style: Teks.kecil,
                ),
              ],
            ),
    );
  }

  Widget _blokFnb(List<Produk> produk, Map<int, int> dipesan) {
    final tersedia = produk.where((p) => p.bisaDijual).toList(growable: false);
    if (tersedia.isEmpty) return const SizedBox.shrink();

    final terpilih = tersedia.where((p) => dipesan.containsKey(p.id)).toList();

    return Kartu(
      judul: 'Makanan & minuman',
      aksi: TextButton.icon(
        onPressed: () => _bukaPemilihFnb(tersedia),
        icon: const Icon(Icons.add, size: 18),
        label: const Text('Tambah'),
      ),
      child: terpilih.isEmpty
          ? const Text(
              'Belum ada pesanan. Boleh dilewati.',
              style: Teks.kecil,
            )
          : Column(
              children: [
                for (final p in terpilih)
                  _BarisQtyFnb(
                    produk: p,
                    qty: dipesan[p.id]!,
                    onUbah: (d) =>
                        ref.read(kasirProvider.notifier).ubahQtyFnb(p.id, d),
                  ),
              ],
            ),
    );
  }

  Future<void> _bukaPemilihFnb(List<Produk> tersedia) async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Warna.mist,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => _PemilihFnb(tersedia: tersedia),
    );
  }

  Widget _blokBayar(String metode) {
    return Kartu(
      judul: 'Cara bayar',
      child: Column(
        children: [
          Row(
            children: [
              Expanded(
                child: TombolPilih(
                  lebarPenuh: true,
                  teks: 'Cash',
                  ikon: Icons.payments,
                  aktif: metode == 'cash',
                  onTap: () =>
                      ref.read(kasirProvider.notifier).setCaraBayar('cash'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: TombolPilih(
                  lebarPenuh: true,
                  teks: 'Transfer',
                  ikon: Icons.account_balance,
                  aktif: metode == 'tf',
                  onTap: () =>
                      ref.read(kasirProvider.notifier).setCaraBayar('tf'),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          IsianUang(
            controller: _tip,
            label: 'Tip (boleh kosong)',
            onSubmit: () {},
          ),
        ],
      ),
    );
  }

  /// Bilah total + tombol aksi, menempel di bawah layar supaya kasir tidak
  /// perlu menggulir ke bawah untuk menyimpan — di antrean sibuk, gulir
  /// tambahan berarti transaksi yang tidak tercatat.
  Widget _bilahBawah(Konfig cfg, List<Produk> produk, KategoriCuci kat) {
    // Tip sengaja TIDAK ikut dijumlahkan: di pembukuan ia berdiri di luar
    // omzet cucian (lihat BookkeepingService), jadi memasukkannya ke sini
    // membuat angka di layar berbeda dengan angka di struk dan di rekap.
    final total = ref.read(kasirProvider.notifier).perkiraanTotal(cfg, produk);

    return Container(
      decoration: const BoxDecoration(
        color: Warna.card,
        border: Border(top: BorderSide(color: Warna.line, width: 2)),
      ),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
      child: SafeArea(
        top: false,
        child: Column(
          children: [
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
                    label: const Text('Simpan draft'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: FilledButton.icon(
                    onPressed: _menyimpan ? null : () => _bayar(kat),
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
        ),
      ),
    );
  }

  Future<void> _bayar(KategoriCuci kat) async {
    setState(() => _menyimpan = true);
    final k = ref.read(kasirProvider);

    try {
      final trx = await ref.read(transaksiRepoProvider).simpan(
            // Kendaraan tanpa nama (kasir pilih jenisnya manual) dicatat
            // memakai label kategorinya, supaya riwayat tidak berisi baris
            // kosong yang tidak bisa dibaca siapa pun nanti.
            namaKendaraan: k.namaKendaraan ?? kat.label,
            kategori: k.kategori,
            layanan: k.layanan,
            caraBayar: k.caraBayar,
            plat: formatPlat(_plat.text).isEmpty ? null : formatPlat(_plat.text),
            tip: bacaUang(_tip),
            idPekerja: k.pekerja.toList(),
            idAddon: k.addon.toList(),
            itemFnb: k.fnb,
            idDraft: k.idDraft,
          );

      if (!mounted) return;

      ref.read(kasirProvider.notifier).bersihkan();
      // Stok sudah berkurang & draft sudah terhapus di server — daftar yang
      // memakainya harus dimuat ulang, bukan dipakai dari cache lama.
      ref
        ..invalidate(produkProvider)
        ..invalidate(draftCuciProvider)
        ..invalidate(rekapHariIniProvider);

      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => LayarSelesai(transaksi: trx)),
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _menyimpan = false);
      pesanGagal(context, e);
    }
  }

  Future<void> _simpanDraft() async {
    setState(() => _menyimpan = true);
    final k = ref.read(kasirProvider);
    final kat = ref.read(konfigProvider).valueOrNull?.kategori[k.kategori];

    final body = <String, dynamic>{
      'vehicle_name': k.namaKendaraan ?? kat?.label ?? k.kategori,
      'category': k.kategori,
      'service': k.layanan,
      'plate': formatPlat(_plat.text).isEmpty ? null : formatPlat(_plat.text),
      'tip': bacaUang(_tip),
      'worker_ids': k.pekerja.toList(),
      'addon_ids': k.addon.toList(),
      'fnb_items': k.fnb.entries
          .map((e) => {'product_id': e.key, 'qty': e.value})
          .toList(),
    };

    try {
      final repo = ref.read(transaksiRepoProvider);
      // Draft yang sedang dibuka cukup diperbarui — jangan sampai jadi dua
      // baris untuk satu mobil yang sama.
      if (k.idDraft != null) {
        await repo.ubahDraft(k.idDraft!, body);
      } else {
        await repo.simpanDraft(body);
      }

      if (!mounted) return;
      ref.read(kasirProvider.notifier).bersihkan();
      ref.invalidate(draftCuciProvider);
      Navigator.pop(context);
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
}

class _BarisQtyFnb extends StatelessWidget {
  const _BarisQtyFnb({
    required this.produk,
    required this.qty,
    required this.onUbah,
  });

  final Produk produk;
  final int qty;
  final ValueChanged<int> onUbah;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(produk.nama, style: Teks.label),
                Text(
                  '${rp(produk.harga)} · subtotal ${rp(produk.harga * qty)}',
                  style: Teks.kecil,
                ),
              ],
            ),
          ),
          _TombolQty(ikon: Icons.remove, onTap: () => onUbah(-1)),
          SizedBox(
            width: 40,
            child: Text(
              '$qty',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
            ),
          ),
          _TombolQty(ikon: Icons.add, onTap: () => onUbah(1)),
        ],
      ),
    );
  }
}

class _TombolQty extends StatelessWidget {
  const _TombolQty({required this.ikon, required this.onTap});

  final IconData ikon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        width: 42,
        height: 42,
        decoration: kotakKartu(radius: 10),
        child: Icon(ikon, size: 20),
      ),
    );
  }
}

/// Pemilih menu F&B yang muncul dari bawah. Pencarian disaring di sisi app,
/// bukan permintaan baru ke server: daftar produk sudah termuat utuh untuk
/// layar kasir, jadi menyaringnya di sini tidak menambah jeda sama sekali.
class _PemilihFnb extends ConsumerStatefulWidget {
  const _PemilihFnb({required this.tersedia});

  final List<Produk> tersedia;

  @override
  ConsumerState<_PemilihFnb> createState() => _PemilihFnbState();
}

class _PemilihFnbState extends ConsumerState<_PemilihFnb> {
  String _cari = '';

  @override
  Widget build(BuildContext context) {
    final dipesan = ref.watch(kasirProvider).fnb;
    final q = _cari.trim().toLowerCase();
    final daftar = q.isEmpty
        ? widget.tersedia
        : widget.tersedia
            .where((p) => p.nama.toLowerCase().contains(q))
            .toList(growable: false);

    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.75,
      maxChildSize: 0.92,
      builder: (_, scroll) => Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
            child: Row(
              children: [
                const Expanded(child: Text('Pilih menu', style: Teks.judul)),
                IconButton(
                  icon: const Icon(Icons.close),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: TextField(
              onChanged: (v) => setState(() => _cari = v),
              decoration: const InputDecoration(
                hintText: 'Cari menu...',
                prefixIcon: Icon(Icons.search),
                isDense: true,
              ),
            ),
          ),
          const SizedBox(height: 8),
          Expanded(
            child: daftar.isEmpty
                ? const Kosong(pesan: 'Menu tidak ketemu.')
                : ListView.separated(
                    controller: scroll,
                    padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
                    itemCount: daftar.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 8),
                    itemBuilder: (_, i) {
                      final p = daftar[i];
                      final qty = dipesan[p.id] ?? 0;
                      return Container(
                        decoration: kotakKartu(
                          garis: qty > 0 ? Warna.go : Warna.line,
                        ),
                        padding: const EdgeInsets.fromLTRB(14, 8, 8, 8),
                        child: Row(
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(p.nama, style: Teks.label),
                                  Text(
                                    '${rp(p.harga)} · stok ${p.stok}',
                                    style: Teks.kecil,
                                  ),
                                ],
                              ),
                            ),
                            if (qty == 0)
                              OutlinedButton(
                                style: OutlinedButton.styleFrom(
                                  minimumSize: const Size(64, 42),
                                ),
                                onPressed: () => ref
                                    .read(kasirProvider.notifier)
                                    .ubahQtyFnb(p.id, 1),
                                child: const Text('Tambah'),
                              )
                            else ...[
                              _TombolQty(
                                ikon: Icons.remove,
                                onTap: () => ref
                                    .read(kasirProvider.notifier)
                                    .ubahQtyFnb(p.id, -1),
                              ),
                              SizedBox(
                                width: 36,
                                child: Text(
                                  '$qty',
                                  textAlign: TextAlign.center,
                                  style: const TextStyle(
                                    fontSize: 17,
                                    fontWeight: FontWeight.w900,
                                  ),
                                ),
                              ),
                              _TombolQty(
                                ikon: Icons.add,
                                // Stok habis tidak boleh ditambah lagi:
                                // server akan menolaknya, dan lebih baik
                                // ditolak di sini daripada setelah kasir
                                // menekan Bayar di depan pelanggan.
                                onTap: qty >= p.stok
                                    ? () => pesanGagal(
                                          context,
                                          'Stok ${p.nama} tinggal ${p.stok}.',
                                        )
                                    : () => ref
                                        .read(kasirProvider.notifier)
                                        .ubahQtyFnb(p.id, 1),
                              ),
                            ],
                          ],
                        ),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}
