import 'package:flutter_test/flutter_test.dart';
import 'package:otin_carwash/core/format.dart';

/// Aturan format yang HARUS sama persis dengan web (public/js/kasir.js).
/// Kalau salah satunya berubah sendiri, plat nomor yang sama akan tersimpan
/// dalam dua bentuk berbeda antara tablet dan browser, dan pencarian riwayat
/// jadi meleset tanpa ada yang sadar.
void main() {
  group('formatPlat', () {
    test('huruf kecil dirapikan jadi pola plat Indonesia', () {
      expect(formatPlat('n1234ab'), 'N 1234 AB');
    });

    test('spasi & tanda baca yang diketik kasir dibuang dulu', () {
      expect(formatPlat('  b-1234-xyz '), 'B 1234 XYZ');
    });

    test('plat wilayah dua huruf tetap utuh', () {
      expect(formatPlat('AB1234CD'), 'AB 1234 CD');
    });

    test('input separuh jadi tetap diterima, tidak dipaksa lengkap', () {
      expect(formatPlat('n12'), 'N 12');
      expect(formatPlat('n'), 'N');
    });

    test('kosong tetap kosong, bukan spasi', () {
      expect(formatPlat(''), '');
      expect(formatPlat(null), '');
      expect(formatPlat('   '), '');
    });

    test('karakter berlebih tidak dibuang, hanya dipisah', () {
      // Pola plat cuma menampung 3 huruf di belakang; sisanya tetap ikut
      // supaya kasir tidak kehilangan yang sudah diketiknya.
      expect(formatPlat('b1234abcd'), 'B 1234 ABC D');
    });
  });

  group('rp', () {
    test('memakai titik sebagai pemisah ribuan (gaya Indonesia)', () {
      expect(rp(25000), 'Rp 25.000');
      expect(rp(1250000), 'Rp 1.250.000');
    });

    test('nol dan null sama-sama Rp 0, bukan kosong atau error', () {
      expect(rp(0), 'Rp 0');
      expect(rp(null), 'Rp 0');
    });

    test('angka negatif tetap terbaca (mis. laba minus)', () {
      expect(rp(-5000), 'Rp -5.000');
    });
  });

  group('tglSaja', () {
    test('memotong tanggal dari timestamp ISO server', () {
      expect(tglSaja('2026-07-11T08:30:00.000000Z'), '2026-07-11');
    });

    test('tanggal yang sudah pendek dibiarkan', () {
      expect(tglSaja('2026-07-11'), '2026-07-11');
    });

    test('nilai kosong/pendek tidak melempar', () {
      expect(tglSaja(null), '');
      expect(tglSaja(''), '');
      expect(tglSaja('2026'), '');
    });
  });
}
