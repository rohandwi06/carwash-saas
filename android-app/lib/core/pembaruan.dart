import 'package:package_info_plus/package_info_plus.dart';

import '../data/api_client.dart';
import '../data/models/json_util.dart';

/// Pengecek pembaruan aplikasi.
///
/// Tablet yang DIJUAL ke cucian lain tidak lewat Play Store, jadi tidak ada
/// yang memberitahu pemiliknya bahwa ada versi baru. Tanpa mekanisme ini,
/// satu-satunya cara memperbarui adalah mendatangi tiap tablet — yang berarti
/// perbaikan bug tidak pernah benar-benar sampai ke pelanggan.
///
/// Server memberitahu build number terbaru; app membandingkannya dengan
/// miliknya sendiri, lalu menawarkan tautan unduh APK. App TIDAK pernah
/// mengunduh atau memasang sendiri — pemasangan tetap keputusan manusia, dan
/// pemasangan diam-diam di tengah antrean cucian adalah cara tercepat
/// kehilangan pelanggan.
class Pembaruan {
  const Pembaruan({
    required this.versi,
    required this.buildBaru,
    required this.urlUnduh,
    required this.catatan,
    required this.wajib,
  });

  final String versi;
  final int buildBaru;
  final String urlUnduh;
  final String catatan;

  /// Rilis yang menutup masalah serius (mis. salah hitung uang). Layar boleh
  /// memaksa pesannya tidak bisa ditutup — tapi pemasangannya tetap manual.
  final bool wajib;

  /// Mengembalikan null kalau app sudah versi terbaru, ATAU kalau server
  /// belum punya endpoint ini sama sekali.
  ///
  /// Kegagalan sengaja ditelan: cek pembaruan adalah kenyamanan, bukan syarat
  /// berjualan. Server lama yang menjawab 404 tidak boleh membuat kasir
  /// melihat pesan error saat membuka aplikasi.
  static Future<Pembaruan?> cek(ApiClient api) async {
    try {
      final info = await PackageInfo.fromPlatform();
      final sekarang = int.tryParse(info.buildNumber) ?? 0;

      final j = asMap(await api.get('/app-version'));
      final terbaru = asInt(j['build']);
      if (terbaru <= sekarang) return null;

      final url = asStr(j['url']);
      // Tanpa tautan unduh, pemberitahuan ini tidak bisa ditindaklanjuti
      // siapa pun — lebih baik diam daripada memberi kabar buntu.
      if (url.isEmpty) return null;

      return Pembaruan(
        versi: asStr(j['version'], '?'),
        buildBaru: terbaru,
        urlUnduh: url,
        catatan: asStr(j['notes']),
        wajib: asBool(j['mandatory']),
      );
    } catch (_) {
      return null;
    }
  }
}
