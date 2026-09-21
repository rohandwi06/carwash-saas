import 'json_util.dart';

/// Identitas cucian: tampil di header, layar login, layar selesai, dan
/// struk. Datang dari `GET /api/business-profile` (diatur owner di
/// Pengaturan -> Akun di web), karena satu APK dipakai banyak cucian.
class ProfilUsaha {
  const ProfilUsaha({
    required this.nama,
    this.keterangan = '',
    this.alamat = '',
    this.telepon = '',
  });

  final String nama;
  final String keterangan;
  final String alamat;
  final String telepon;

  /// Dipakai sebelum server diketahui (tablet baru) atau saat server tidak
  /// terjangkau. Sengaja bukan nama cucian mana pun.
  static const bawaan = ProfilUsaha(nama: 'KASIR CARWASH');

  factory ProfilUsaha.fromJson(Map<String, dynamic> j) {
    final nama = asStr(j['name']).trim();
    return ProfilUsaha(
      nama: nama.isEmpty ? bawaan.nama : nama,
      keterangan: asStr(j['tagline']).trim(),
      alamat: asStr(j['address']).trim(),
      telepon: asStr(j['phone']).trim(),
    );
  }
}
