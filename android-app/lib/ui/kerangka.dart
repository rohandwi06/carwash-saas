import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/theme.dart';
import '../state/providers.dart';
import 'screens/layar_buku.dart';
import 'screens/layar_dashboard.dart';
import 'screens/layar_fnb.dart';
import 'screens/layar_kasir.dart';
import 'screens/layar_pekerja.dart';
import 'screens/layar_pengaturan.dart';
import 'screens/layar_pengeluaran.dart';
import 'screens/layar_rekap.dart';

/// Halaman utama aplikasi. Nilai `id` disimpan ke penyimpanan tablet supaya
/// kasir kembali ke layar terakhirnya setelah app ditutup — sama seperti
/// `otinLastPage` di web.
enum Halaman {
  kasir('layarHome', 'Kasir', Icons.local_car_wash),
  fnb('layarFnb', 'Makanan & Minuman', Icons.restaurant),
  rekap('layarRekap', 'Rekap Hari Ini', Icons.receipt_long),
  pengeluaran('layarPengeluaran', 'Pengeluaran', Icons.payments_outlined),
  buku('layarBuku', 'Pembukuan', Icons.menu_book),
  dashboard('layarDashboard', 'Dashboard', Icons.insights, ownerSaja: true),
  pekerja('layarPekerja', 'Pekerja & Upah', Icons.groups, ownerSaja: true),
  pengaturan('layarMenuFnb', 'Pengaturan', Icons.settings, ownerSaja: true);

  const Halaman(this.id, this.judul, this.ikon, {this.ownerSaja = false});

  final String id;
  final String judul;
  final IconData ikon;

  /// Layar yang hanya boleh dibuka owner:
  ///   Dashboard      — laba bersih & tren omzet, angka pemilik usaha.
  ///   Pekerja & Upah — upah tiap orang + setoran kas, bukan urusan kasir.
  ///   Pengaturan     — harga, tarif upah, katalog, jam kerja, dan akun.
  ///
  /// Ini semata perapian TAMPILAN. Penjaga sebenarnya ada di middleware
  /// 'owner' pada routes/api.php, yang tetap menolak walau menunya dipaksa
  /// muncul — jadi jangan pernah pindahkan aturan uang ke sini.
  final bool ownerSaja;

  static Halaman dariId(String? id) => values.firstWhere(
        (h) => h.id == id,
        orElse: () => Halaman.kasir,
      );
}

class Kerangka extends ConsumerStatefulWidget {
  const Kerangka({super.key});

  @override
  ConsumerState<Kerangka> createState() => _KerangkaState();
}

class _KerangkaState extends ConsumerState<Kerangka> {
  late Halaman _halaman;

  @override
  void initState() {
    super.initState();
    final sesi = ref.read(sesiProvider);
    final terakhir = Halaman.dariId(sesi.halamanTerakhir);
    // Kasir yang halaman terakhirnya ternyata layar owner (mis. tablet ini
    // sebelumnya dipakai owner) dibelokkan ke Kasir, bukan dibiarkan membuka
    // layar yang isinya cuma deretan tombol berbalas 403.
    _halaman = (terakhir.ownerSaja && !sesi.owner)
        ? _layarAwal(sesi.owner)
        : terakhir;
  }

  /// Kasir mendarat di Kasir; owner di Dashboard, yang memang layar
  /// pembukanya sejak Dashboard tertutup untuk kasir.
  Halaman _layarAwal(bool owner) => owner ? Halaman.dashboard : Halaman.kasir;

  void _pergi(Halaman h) {
    setState(() => _halaman = h);
    ref.read(sesiProvider.notifier).ingatHalaman(h.id);
  }

  @override
  Widget build(BuildContext context) {
    final sesi = ref.watch(sesiProvider);
    final menu = Halaman.values
        .where((h) => !h.ownerSaja || sesi.owner)
        .toList(growable: false);

    return Scaffold(
      drawer: _Drawer(
        aktif: _halaman,
        menu: menu,
        nama: sesi.nama,
        role: sesi.role,
        onPilih: (h) {
          Navigator.pop(context);
          _pergi(h);
        },
      ),
      body: Column(
        children: [
          _Header(judul: _halaman.judul, role: sesi.role, nama: sesi.nama),
          const _BilahPembaruan(),
          Expanded(
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: lebarIsi),
                child: _isi(),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _isi() => switch (_halaman) {
        Halaman.kasir => const LayarKasir(),
        Halaman.fnb => const LayarFnb(),
        Halaman.rekap => const LayarRekap(),
        Halaman.pengeluaran => const LayarPengeluaran(),
        Halaman.buku => const LayarBuku(),
        Halaman.dashboard => const LayarDashboard(),
        Halaman.pekerja => const LayarPekerja(),
        Halaman.pengaturan => const LayarPengaturan(),
      };
}

/// Pemberitahuan versi baru. Tablet yang dijual ke cucian lain tidak lewat
/// Play Store, jadi tanpa bilah ini pemiliknya tidak akan pernah tahu ada
/// perbaikan yang menunggu. Sengaja berupa bilah tipis, bukan dialog: kasir
/// yang sedang melayani antrean tidak boleh dipaksa menutup pop-up dulu.
class _BilahPembaruan extends ConsumerWidget {
  const _BilahPembaruan();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final p = ref.watch(pembaruanProvider).valueOrNull;
    if (p == null) return const SizedBox.shrink();

    return Material(
      color: Warna.tag,
      child: InkWell(
        onTap: () => showDialog<void>(
          context: context,
          builder: (c) => AlertDialog(
            title: Text('Versi ${p.versi} tersedia', style: Teks.judul),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (p.catatan.isNotEmpty) Text(p.catatan),
                const SizedBox(height: 12),
                const Text(
                  'Unduh APK-nya lewat alamat di bawah, lalu pasang seperti '
                  'biasa. Aplikasi tidak memasang sendiri.',
                  style: Teks.kecil,
                ),
                const SizedBox(height: 8),
                SelectableText(p.urlUnduh, style: Teks.label),
              ],
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(c),
                child: const Text('Nanti'),
              ),
            ],
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(
            children: [
              const Icon(Icons.system_update, size: 18, color: Warna.tagInk),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'Versi ${p.versi} tersedia — ketuk untuk lihat caranya',
                  style: const TextStyle(
                    color: Warna.tagInk,
                    fontSize: 13,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Bilah hitam bergaris kuning di atas — menyalin `.top` di kasir.css.
class _Header extends StatelessWidget {
  const _Header({required this.judul, required this.role, required this.nama});

  final String judul;
  final String role;
  final String nama;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Warna.ink,
      child: SafeArea(
        bottom: false,
        child: Container(
          decoration: const BoxDecoration(
            border: Border(bottom: BorderSide(color: Warna.tag, width: 4)),
          ),
          padding: const EdgeInsets.fromLTRB(8, 8, 16, 10),
          // Lebar header diukur, bukan diasumsikan. Di tablet mendatar semua
          // muat; di layar sempit (HP, atau tablet berdiri) tulisan
          // "OTIN CARWASH" + judul halaman + lencana role melebihi lebar dan
          // memicu overflow. Yang dikorbankan duluan adalah wordmark-nya:
          // merek sudah jelas dari ikon tetesnya, sedangkan judul halaman dan
          // siapa yang sedang login keduanya informasi yang dipakai kerja.
          child: LayoutBuilder(
            builder: (context, c) {
              final sempit = c.maxWidth < 480;
              return Row(
                children: [
                  IconButton(
                    icon: const Icon(Icons.menu, color: Colors.white, size: 28),
                    tooltip: 'Menu',
                    onPressed: () => Scaffold.of(context).openDrawer(),
                  ),
                  _Brand(ringkas: sempit),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      judul,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 16,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  // Flexible, bukan lebar tetap: nama kasir yang panjang
                  // dipotong dengan elipsis, bukan mendorong header sampai
                  // meluber.
                  Flexible(child: _Lencana(role: role, nama: nama)),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _Brand extends StatelessWidget {
  const _Brand({this.ringkas = false});

  /// Hanya ikon tetesnya, tanpa tulisan "OTIN CARWASH". Dipakai header di
  /// layar sempit; drawer selalu memakai bentuk penuh karena di sana ruangnya
  /// tersedia dan merek memang pantas ditegaskan sekali.
  final bool ringkas;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        const Text('💧', style: TextStyle(fontSize: 22)),
        if (!ringkas) ...[
          const SizedBox(width: 6),
          const Text.rich(
            TextSpan(
              children: [
                TextSpan(text: 'OTIN', style: TextStyle(color: Warna.tag)),
                TextSpan(
                  text: ' CARWASH',
                  style: TextStyle(color: Colors.white),
                ),
              ],
            ),
            style: TextStyle(
              fontSize: 18,
              fontWeight: FontWeight.w900,
              letterSpacing: 1,
            ),
          ),
        ],
      ],
    );
  }
}

/// Penanda siapa yang sedang login. Bukan hiasan: di tablet yang dipakai
/// bergantian, ini satu-satunya cara kasir tahu ia belum keluar dari akun
/// orang sebelumnya sebelum mencatat transaksi atas nama yang salah.
class _Lencana extends StatelessWidget {
  const _Lencana({required this.role, required this.nama});

  final String role;
  final String nama;

  @override
  Widget build(BuildContext context) {
    if (role.isEmpty) return const SizedBox.shrink();
    final owner = role == 'owner';
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: owner ? Warna.tag : Colors.white24,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        // Owner selalu bernama "Owner", jadi "OWNER · Owner" cuma mengulang
        // kata yang sama dan memakan lebar yang dibutuhkan judul halaman.
        nama.isEmpty || nama.toLowerCase() == role.toLowerCase()
            ? role.toUpperCase()
            : '${role.toUpperCase()} · $nama',
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(
          color: owner ? Warna.tagInk : Colors.white,
          fontSize: 12,
          fontWeight: FontWeight.w900,
        ),
      ),
    );
  }
}

class _Drawer extends ConsumerWidget {
  const _Drawer({
    required this.aktif,
    required this.menu,
    required this.nama,
    required this.role,
    required this.onPilih,
  });

  final Halaman aktif;
  final List<Halaman> menu;
  final String nama;
  final String role;
  final ValueChanged<Halaman> onPilih;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Drawer(
      backgroundColor: Warna.card,
      child: SafeArea(
        child: Column(
          children: [
            Container(
              width: double.infinity,
              color: Warna.ink,
              padding: const EdgeInsets.fromLTRB(20, 22, 20, 22),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const _Brand(),
                  const SizedBox(height: 10),
                  Text(
                    nama.isEmpty ? 'Belum login' : nama,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 15,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  Text(
                    role.toUpperCase(),
                    style: const TextStyle(color: Warna.tag, fontSize: 12),
                  ),
                ],
              ),
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.symmetric(vertical: 8),
                children: [
                  for (final h in menu)
                    ListTile(
                      leading: Icon(
                        h.ikon,
                        color: h == aktif ? Warna.waterDk : Warna.ink2,
                      ),
                      title: Text(
                        h.judul,
                        style: TextStyle(
                          fontWeight:
                              h == aktif ? FontWeight.w900 : FontWeight.w700,
                          color: h == aktif ? Warna.waterDk : Warna.ink,
                        ),
                      ),
                      selected: h == aktif,
                      selectedTileColor: Warna.mist,
                      onTap: () => onPilih(h),
                    ),
                ],
              ),
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.logout, color: Warna.danger),
              title: const Text(
                'Keluar',
                style: TextStyle(
                  color: Warna.danger,
                  fontWeight: FontWeight.w800,
                ),
              ),
              onTap: () async {
                final yakin = await konfirmasi(
                  context,
                  judul: 'Keluar dari akun?',
                  pesan: 'Kasir berikutnya harus login lagi.',
                  tombolYa: 'Keluar',
                );
                if (!yakin) return;
                await ref.read(sesiProvider.notifier).keluar();
              },
            ),
          ],
        ),
      ),
    );
  }
}

/// Dialog ya/tidak. Dipakai di seluruh app supaya tiap tindakan yang tidak
/// bisa dibatalkan (hapus data, batalkan transaksi, keluar akun) selalu
/// bertanya dengan cara yang sama.
Future<bool> konfirmasi(
  BuildContext context, {
  required String judul,
  String? pesan,
  String tombolYa = 'Ya',
  bool merah = true,
}) async {
  final hasil = await showDialog<bool>(
    context: context,
    builder: (c) => AlertDialog(
      title: Text(judul, style: Teks.judul),
      content: pesan == null ? null : Text(pesan),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(c, false),
          child: const Text('Batal'),
        ),
        FilledButton(
          style: FilledButton.styleFrom(
            backgroundColor: merah ? Warna.danger : Warna.go,
            minimumSize: const Size(100, 44),
          ),
          onPressed: () => Navigator.pop(c, true),
          child: Text(tombolYa),
        ),
      ],
    ),
  );
  return hasil ?? false;
}
