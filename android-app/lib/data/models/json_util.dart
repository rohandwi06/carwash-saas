/// Pembaca JSON yang tidak rewel.
///
/// MySQL lewat PDO kadang mengembalikan DECIMAL/BIGINT sebagai string
/// ("25000"), sementara SQLite di laptop mengembalikannya sebagai angka.
/// Kalau app-nya menganggap salah satu saja, ia jalan mulus di laptop lalu
/// mati di tablet pelanggan — kegagalan yang paling mahal ditemukan.
/// Semua model di app ini WAJIB lewat helper ini, jangan pernah `as int`.
library;

int asInt(dynamic v, [int fallback = 0]) {
  if (v == null) return fallback;
  if (v is int) return v;
  if (v is num) return v.round();
  return int.tryParse('$v') ?? double.tryParse('$v')?.round() ?? fallback;
}

double asDouble(dynamic v, [double fallback = 0]) {
  if (v == null) return fallback;
  if (v is num) return v.toDouble();
  return double.tryParse('$v') ?? fallback;
}

String asStr(dynamic v, [String fallback = '']) =>
    v == null ? fallback : '$v';

String? asStrNull(dynamic v) {
  if (v == null) return null;
  final s = '$v'.trim();
  return s.isEmpty ? null : s;
}

/// Laravel mengirim boolean sebagai true/false, tapi kolom tinyint lewat
/// beberapa driver keluar sebagai 1/0 atau "1"/"0".
bool asBool(dynamic v, [bool fallback = false]) {
  if (v == null) return fallback;
  if (v is bool) return v;
  if (v is num) return v != 0;
  final s = '$v'.toLowerCase();
  return s == 'true' || s == '1';
}

DateTime? asTanggal(dynamic v) {
  final s = asStrNull(v);
  return s == null ? null : DateTime.tryParse(s)?.toLocal();
}

/// Daftar objek JSON -> daftar model. Elemen yang bukan Map dilewati diam-diam
/// daripada menjatuhkan seluruh layar karena satu baris rusak.
List<T> asList<T>(dynamic v, T Function(Map<String, dynamic>) buat) {
  if (v is! List) return <T>[];
  return v
      .whereType<Map>()
      .map((e) => buat(e.cast<String, dynamic>()))
      .toList(growable: false);
}

Map<String, dynamic> asMap(dynamic v) =>
    v is Map ? v.cast<String, dynamic>() : <String, dynamic>{};
