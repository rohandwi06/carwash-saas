import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../state/providers.dart';
import 'kerangka.dart';
import 'screens/layar_login.dart';
import 'screens/layar_server.dart';
import 'screens/layar_terkunci.dart';
import 'widgets/pemuat.dart';

/// Penentu layar mana yang tampil paling depan. Urutannya bukan selera:
///
///   1. Alamat server  — tanpa ini tidak ada yang bisa dimuat sama sekali.
///   2. Login          — tanpa token, semua endpoint menjawab 401.
///   3. Jam operasional— kasir di luar jam kerja dikunci; owner selalu lolos.
///   4. Aplikasi.
///
/// Sama seperti `mulaiAplikasi()` di kasir.js yang memanggil `muatShift()`
/// duluan: layar terkunci harus muncul SEBELUM layar lain sempat memuat data,
/// supaya kasir tidak melihat kilasan isi aplikasi yang lalu direbut.
class Gerbang extends ConsumerWidget {
  const Gerbang({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sesi = ref.watch(sesiProvider);

    if (!sesi.serverSiap) return const LayarServer();
    if (!sesi.login) return const LayarLogin();

    // Owner tidak pernah dikunci — dialah yang mengatur jamnya, dan mengunci
    // owner di luar jam kerja berarti ia tidak bisa membukanya lagi.
    if (sesi.owner) return const Kerangka();

    final shift = ref.watch(shiftProvider);
    return shift.when(
      loading: () => const Pemuat(pesan: 'Memeriksa jam operasional...'),
      error: (e, _) => LayarGagalMuat(
        pesan: '$e',
        onCoba: () => ref.invalidate(shiftProvider),
      ),
      data: (s) => s.buka ? const Kerangka() : LayarTerkunci(status: s),
    );
  }
}
