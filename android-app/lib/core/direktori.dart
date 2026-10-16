import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../data/session.dart';

/// Daftar cucian: KODE -> alamat server.
///
/// Aplikasi di Play Store dipasang sendiri oleh owner atau kasir, dan mereka
/// tidak boleh disuruh mengetik alamat seperti
/// `https://kembar99-carwash.byrapiin.my.id`. Cukup kode pendek (`kembar99`);
/// alamatnya dibaca dari satu berkas JSON di server penyedia, yang ditambah
/// lewat File Manager setiap ada cucian baru — tanpa merilis ulang aplikasi.
///
/// Bentuk berkasnya (lihat deploy/play-store/cucian.json di repo server):
///   {"cucian": {"kembar99": {"nama": "KEMBAR 99 CARWASH",
///                            "url": "https://kembar99-carwash.byrapiin.my.id"}}}
///
/// Alamatnya bisa diganti saat membangun untuk pengujian:
///   --dart-define=URL_DIREKTORI=https://contoh/cucian.json
const String kUrlDirektori = String.fromEnvironment(
  'URL_DIREKTORI',
  defaultValue: 'https://byrapiin.my.id/cucian.json',
);

/// Kegagalan yang pesannya layak dibaca pengguna apa adanya.
class GagalCucian implements Exception {
  const GagalCucian(this.pesan);
  final String pesan;

  @override
  String toString() => pesan;
}

class Direktori {
  /// "Kembar 99", "kembar-99", " KEMBAR99 " -> "kembar99".
  static String rapikanKode(String mentah) =>
      mentah.trim().toLowerCase().replaceAll(RegExp(r'[^a-z0-9]'), '');

  /// Alamat server untuk sebuah kode cucian.
  static Future<String> alamatDariKode(String kode, {http.Client? klien}) async {
    final k = rapikanKode(kode);
    if (k.isEmpty) throw const GagalCucian('Isi kode cucian dulu.');

    final c = klien ?? http.Client();
    try {
      final res = await c.get(Uri.parse(kUrlDirektori)).timeout(const Duration(seconds: 15));
      if (res.statusCode != 200) {
        throw GagalCucian('Daftar cucian sedang tidak bisa dibuka (${res.statusCode}). Coba lagi sebentar.');
      }
      final j = jsonDecode(utf8.decode(res.bodyBytes));
      final daftar = j is Map ? j['cucian'] : null;
      final isi = daftar is Map ? daftar[k] : null;
      final url = isi is Map ? isi['url'] : isi;
      if (url is! String || url.trim().isEmpty) {
        throw GagalCucian('Kode "$k" tidak dikenal. Periksa ejaannya, atau tanyakan ke pemilik cucian.');
      }
      return SesiStore.rapikan(url);
    } on GagalCucian {
      rethrow;
    } on FormatException {
      throw const GagalCucian('Daftar cucian sedang bermasalah. Hubungi penyedia aplikasi.');
    } catch (_) {
      throw const GagalCucian('Tidak bisa terhubung ke internet. Periksa WiFi/data.');
    } finally {
      if (klien == null) c.close();
    }
  }

  /// Pastikan alamat itu memang server kasir carwash yang sedang hidup.
  ///
  /// Yang ditanya `/api/me`: ada di semua versi server (termasuk OTIN, yang
  /// tidak punya `/api/business-profile`), dan tanpa login selalu dijawab
  /// 401 berisi JSON. Halaman lain — situs yang salah, halaman error hosting —
  /// menjawab HTML, sehingga tidak lolos.
  static Future<void> pastikanServer(String url, {http.Client? klien}) async {
    final c = klien ?? http.Client();
    try {
      final res = await c
          .get(Uri.parse('$url/api/me'), headers: const {'Accept': 'application/json'})
          .timeout(const Duration(seconds: 15));
      final json = (res.headers['content-type'] ?? '').contains('json');
      if (!json || (res.statusCode != 401 && res.statusCode != 200)) {
        throw const GagalCucian('Alamat ini bukan server kasir carwash, atau servernya sedang mati.');
      }
    } on GagalCucian {
      rethrow;
    } catch (_) {
      throw const GagalCucian('Server cucian tidak bisa dihubungi. Periksa WiFi/data, lalu coba lagi.');
    } finally {
      if (klien == null) c.close();
    }
  }
}
