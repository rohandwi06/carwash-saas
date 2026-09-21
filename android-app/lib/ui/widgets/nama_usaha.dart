import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme.dart';
import '../../data/models/usaha.dart';
import '../../state/providers.dart';

/// Nama cucian dengan kata terakhir berwarna kuning ("BUDI CARWASH" ->
/// BUDI + CARWASH kuning), sama dengan brand.blade.php di web. Satu kata saja
/// tampil putih polos.
///
/// Selama profil belum termuat (atau server belum diatur) yang tampil
/// [ProfilUsaha.bawaan], bukan ruang kosong yang membuat layar melompat.
class NamaUsaha extends ConsumerWidget {
  const NamaUsaha({
    super.key,
    this.ukuran = 18,
    this.jarakHuruf = 1,
    this.rataTengah = false,
  });

  final double ukuran;
  final double jarakHuruf;
  final bool rataTengah;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final nama = (ref.watch(profilUsahaProvider).valueOrNull ?? ProfilUsaha.bawaan).nama;
    final kata = nama.trim().split(RegExp(r'\s+'));
    final akhir = kata.length > 1 ? kata.removeLast() : null;

    return Text.rich(
      TextSpan(
        children: [
          TextSpan(text: kata.join(' '), style: const TextStyle(color: Colors.white)),
          if (akhir != null)
            TextSpan(text: ' $akhir', style: const TextStyle(color: Warna.tag)),
        ],
      ),
      textAlign: rataTengah ? TextAlign.center : null,
      maxLines: 2,
      overflow: TextOverflow.ellipsis,
      style: TextStyle(
        fontSize: ukuran,
        fontWeight: FontWeight.w900,
        letterSpacing: jarakHuruf,
      ),
    );
  }
}
