import 'package:flutter_test/flutter_test.dart';
import 'package:kasir_carwash/data/models/usaha.dart';

void main() {
  group('ProfilUsaha', () {
    test('membaca jawaban /api/business-profile', () {
      final p = ProfilUsaha.fromJson({
        'name': 'Budi Carwash',
        'tagline': 'Cuci Mobil & Motor',
        'address': 'Jl. Merdeka 1',
        'phone': '0812',
      });
      expect(p.nama, 'Budi Carwash');
      expect(p.keterangan, 'Cuci Mobil & Motor');
      expect(p.alamat, 'Jl. Merdeka 1');
      expect(p.telepon, '0812');
    });

    test('nama kosong jatuh ke nama bawaan, bukan string kosong di struk', () {
      final p = ProfilUsaha.fromJson({'name': '  ', 'tagline': null});
      expect(p.nama, ProfilUsaha.bawaan.nama);
      expect(p.keterangan, '');
    });
  });
}
