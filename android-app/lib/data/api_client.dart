import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

/// Sesi habis / belum login (HTTP 401). Ditangkap di lapisan atas untuk
/// melempar kasir kembali ke layar login, bukan menampilkan alert error.
class SesiHabis implements Exception {
  const SesiHabis();
  @override
  String toString() => 'Sesi berakhir. Silakan login lagi.';
}

/// Di luar jam operasional (HTTP 423). Membawa pesan & jadwal dari server
/// supaya layar terkunci bisa memberitahu kasir jam berapa boleh masuk lagi.
class ShiftTutup implements Exception {
  const ShiftTutup(this.pesan, this.shift);
  final String? pesan;
  final Map<String, dynamic>? shift;
  @override
  String toString() => pesan ?? 'Di luar jam operasional.';
}

/// Error yang layak ditampilkan apa adanya ke kasir (pesan dari server).
class ApiError implements Exception {
  const ApiError(this.pesan, {this.status});
  final String pesan;
  final int? status;
  @override
  String toString() => pesan;
}

/// Klien REST ke backend Laravel.
///
/// Perilakunya sengaja dibuat sama persis dengan `api()` di public/js/kasir.js
/// supaya tablet dan web tidak pernah berbeda hasil untuk permintaan yang sama.
/// Kalau aturan di sana berubah, ubah juga di sini.
class ApiClient {
  ApiClient({
    required this.baseUrl,
    this.token,
    this.onSesiHabis,
    http.Client? klien,
  }) : _klien = klien ?? http.Client();

  /// Alamat server TANPA `/api` dan tanpa garis miring di akhir,
  /// mis. `https://otin.example.com`. Bisa berbeda tiap tablet karena tiap
  /// cucian punya servernya sendiri.
  final String baseUrl;
  final String? token;

  /// Dipanggil saat server menjawab 401. Dipakai lapisan sesi untuk
  /// menghapus token tersimpan sebelum kasir dilempar ke layar login.
  final void Function()? onSesiHabis;

  final http.Client _klien;

  /// Server rumahan lewat tunnel kadang lambat bangun; 20 detik cukup lama
  /// untuk itu tapi masih cukup pendek supaya kasir tidak menatap layar beku.
  static const _batasWaktu = Duration(seconds: 20);

  /// Menggabungkan query yang sudah menempel di [path] (mis.
  /// `/products?active=1`) dengan [query] tambahan, tanpa membuang salah satu.
  Uri _uri(String path, [Map<String, dynamic>? query]) {
    final u = Uri.parse('$baseUrl/api$path');
    if (query == null || query.isEmpty) return u;
    return u.replace(
      queryParameters: {
        ...u.queryParameters,
        ...query.map((k, v) => MapEntry(k, '$v')),
      },
    );
  }

  Future<dynamic> get(String path, {Map<String, dynamic>? query}) =>
      _kirim('GET', path, query: query);

  Future<dynamic> post(String path, {Map<String, dynamic>? body}) =>
      _kirim('POST', path, body: body);

  Future<dynamic> put(String path, {Map<String, dynamic>? body}) =>
      _kirim('PUT', path, body: body);

  Future<dynamic> patch(String path, {Map<String, dynamic>? body}) =>
      _kirim('PATCH', path, body: body);

  Future<dynamic> delete(String path, {Map<String, dynamic>? body}) =>
      _kirim('DELETE', path, body: body);

  /// Sama seperti [get] tapi mengembalikan SELURUH badan JSON, bukan cuma
  /// `.data`. Dipakai endpoint yang ikut mengirim ringkasan hitungan dari
  /// server (mis. `/worker-deposits` dengan `summary`), supaya angka uang
  /// tetap dihitung di satu tempat dan app tidak menjumlahkan ulang sendiri.
  Future<Map<String, dynamic>> getPenuh(
    String path, {
    Map<String, dynamic>? query,
  }) async {
    final r = await _kirim('GET', path, query: query, penuh: true);
    return (r as Map).cast<String, dynamic>();
  }

  Future<dynamic> _kirim(
    String metode,
    String path, {
    Map<String, dynamic>? query,
    Map<String, dynamic>? body,
    bool penuh = false,
  }) async {
    final headers = <String, String>{
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      if (token != null && token!.isNotEmpty) 'Authorization': 'Bearer $token',
    };

    // --- Menembus WAF shared hosting (ArenHost/LiteSpeed) ------------------
    // Hosting memasang dua aturan mod_security yang memutus separuh aplikasi
    // SEBELUM Laravel sempat jalan, sehingga yang kembali bukan JSON melainkan
    // halaman HTML 403:
    //   1. Metode DELETE / PATCH / PUT diblokir seluruhnya.
    //   2. POST tanpa badan permintaan diblokir (mis. /logout).
    // Keduanya diakali di sini, bukan di tiap pemanggil. Symfony (dasar
    // Laravel) membaca X-HTTP-Method-Override lebih dulu daripada metode
    // aslinya, jadi rute & controller tetap yang semula.
    // Aman juga di luar hosting: di server lokal header ini cuma mengubah
    // POST kembali jadi metode yang sama, hasilnya identik.
    var metodeKirim = metode;
    if (metode == 'DELETE' || metode == 'PATCH' || metode == 'PUT') {
      headers['X-HTTP-Method-Override'] = metode;
      metodeKirim = 'POST';
    }

    final req = http.Request(metodeKirim, _uri(path, query))
      ..headers.addAll(headers);
    if (metode != 'GET') {
      // Badan permintaan tidak pernah kosong: minimal "{}".
      req.body = jsonEncode(body ?? const <String, dynamic>{});
    }

    late http.Response res;
    try {
      res = await http.Response.fromStream(
        await _klien.send(req).timeout(_batasWaktu),
      );
    } on TimeoutException {
      throw const ApiError(
        'Server tidak menjawab. Periksa koneksi internet tablet.',
      );
    } catch (_) {
      throw const ApiError(
        'Tidak bisa terhubung ke server. Periksa WiFi/data tablet.',
      );
    }

    if (res.statusCode == 401) {
      onSesiHabis?.call();
      throw const SesiHabis();
    }

    if (res.statusCode == 423) {
      final j = _jsonAman(res.body);
      throw ShiftTutup(
        j?['message'] as String?,
        (j?['shift'] as Map?)?.cast<String, dynamic>(),
      );
    }

    if (res.statusCode < 200 || res.statusCode >= 300) {
      final j = _jsonAman(res.body);
      throw ApiError(
        (j?['message'] as String?) ??
            'Gagal terhubung ke server (${res.statusCode})',
        status: res.statusCode,
      );
    }

    final badan = _jsonAman(res.body);
    if (badan == null) return null;
    return penuh ? badan : badan['data'];
  }

  /// Badan 403 dari WAF adalah HTML, bukan JSON — jangan sampai app-nya
  /// ikut mati hanya karena gagal mengurai halaman error hosting.
  Map<String, dynamic>? _jsonAman(String body) {
    if (body.isEmpty) return null;
    try {
      final j = jsonDecode(body);
      return j is Map<String, dynamic> ? j : {'data': j};
    } catch (_) {
      return null;
    }
  }

  void tutup() => _klien.close();
}
