import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kasir_carwash/core/direktori.dart';

/// Kode cucian -> alamat server (layar pertama aplikasi Play Store).
///
/// Yang dijaga: cara tulis kode bebas, kode tak dikenal dan daftar rusak
/// memberi pesan yang bisa dibaca, dan hanya server kasir sungguhan (JSON
/// 401 di /api/me) yang diterima.
void main() {
  final daftar = jsonEncode({
    'cucian': {
      'kembar99': {'nama': 'KEMBAR 99 CARWASH', 'url': 'kembar99-carwash.byrapiin.my.id/'},
      'otin': 'https://otincarwash-pos.my.id',
    },
  });
  MockClient klienDaftar([String isi = '', int status = 200]) =>
      MockClient((_) async => http.Response(isi.isEmpty ? daftar : isi, status));

  test('kode dengan cara tulis apa pun menemukan alamatnya', () async {
    expect(await Direktori.alamatDariKode(' Kembar-99 ', klien: klienDaftar()),
        'https://kembar99-carwash.byrapiin.my.id',);
    expect(await Direktori.alamatDariKode('OTIN', klien: klienDaftar()), 'https://otincarwash-pos.my.id');
  });

  test('kode tak dikenal, kosong, dan daftar rusak memberi pesan jelas', () async {
    expect(() => Direktori.alamatDariKode('budi', klien: klienDaftar()),
        throwsA(isA<GagalCucian>().having((e) => e.pesan, 'pesan', contains('tidak dikenal'))),);
    expect(() => Direktori.alamatDariKode('  ', klien: klienDaftar()), throwsA(isA<GagalCucian>()));
    expect(() => Direktori.alamatDariKode('otin', klien: klienDaftar('<html>', 200)),
        throwsA(isA<GagalCucian>().having((e) => e.pesan, 'pesan', contains('bermasalah'))),);
    expect(() => Direktori.alamatDariKode('otin', klien: klienDaftar('x', 503)),
        throwsA(isA<GagalCucian>().having((e) => e.pesan, 'pesan', contains('503'))),);
  });

  test('hanya server kasir yang diterima', () async {
    MockClient balas(int status, String tipe) => MockClient((r) async {
          expect(r.url.path, '/api/me');
          return http.Response('{}', status, headers: {'content-type': tipe});
        });

    await Direktori.pastikanServer('https://a', klien: balas(401, 'application/json'));
    expect(() => Direktori.pastikanServer('https://a', klien: balas(200, 'text/html')), throwsA(isA<GagalCucian>()));
    expect(() => Direktori.pastikanServer('https://a', klien: balas(404, 'application/json')), throwsA(isA<GagalCucian>()));
  });
}
