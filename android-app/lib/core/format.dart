import 'package:intl/intl.dart';

/// Padanan Dart dari fungsi format di public/js/kasir.js. Sengaja satu file
/// supaya kalau aturannya berubah di web, di sini ketahuan harus ikut diubah.

final _rp = NumberFormat.decimalPattern('id_ID');

/// `rp(25000)` -> "Rp 25.000". Menyalin `rp()` di kasir.js.
String rp(num? n) => 'Rp ${_rp.format((n ?? 0).round())}';

/// Angka polos tanpa "Rp" — untuk kolom tabel yang sudah berjudul rupiah.
String angka(num? n) => _rp.format((n ?? 0).round());

final _jam = DateFormat('HH:mm');
final _tglPanjang = DateFormat('d MMMM yyyy', 'id_ID');
final _tglPendek = DateFormat('d MMM', 'id_ID');
final _tglIso = DateFormat('yyyy-MM-dd');

String jam(DateTime d) => _jam.format(d);
String tglPanjang(DateTime d) => _tglPanjang.format(d);
String tglPendek(DateTime d) => _tglPendek.format(d);

/// Format tanggal yang dipakai SELURUH API (`?date=2026-07-11`).
String iso(DateTime d) => _tglIso.format(d);

/// `hariIni()` di kasir.js — tanggal lokal tablet, bukan UTC. Penting: kalau
/// dipakai UTC, transaksi jam 7 pagi WIB masuk ke tanggal kemarin dan rekap
/// harian jadi salah.
String hariIni() => iso(DateTime.now());

/// Ambil "2026-07-11" dari "2026-07-11T08:30:00Z" — padanan `tglSaja()`.
String tglSaja(String? s) => (s ?? '').length >= 10 ? s!.substring(0, 10) : '';

const namaBulan = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

/// Plat nomor: kasir cukup ketik "n1234ab", spasi & huruf besar diurus di sini.
/// Pola plat Indonesia: [1-2 huruf wilayah] [1-4 angka] [0-3 huruf].
/// Sisa karakter di luar pola tidak dibuang, hanya dipisah spasi.
/// Padanan `formatPlat()` di kasir.js — aturannya harus tetap sama supaya
/// plat yang sama tidak tersimpan dua bentuk berbeda antara tablet dan web.
String formatPlat(String? mentah) {
  final bersih =
      (mentah ?? '').toUpperCase().replaceAll(RegExp('[^A-Z0-9]'), '');
  final m = RegExp(r'^([A-Z]{0,2})(\d{0,4})([A-Z]{0,3})(.*)$').firstMatch(bersih);
  if (m == null) return bersih;
  return [m[1], m[2], m[3], m[4]]
      .where((s) => s != null && s.isNotEmpty)
      .join(' ');
}
