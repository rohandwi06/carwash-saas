import 'package:shared_preferences/shared_preferences.dart';

/// Isi sesi yang bertahan walau app ditutup. Padanan `localStorage` di
/// kasir.js (`otin_token`, `otin_role`, `otin_name`, `otinLastPage`) plus satu
/// hal yang tidak ada di web: alamat server.
///
/// Alamat server harus bisa diatur karena tiap cucian yang membeli tablet
/// punya servernya sendiri. Di web ini tidak perlu — file JS-nya disajikan
/// dari server yang sama, jadi cukup path relatif `/api`.
class Sesi {
  const Sesi({
    this.token = '',
    this.role = '',
    this.nama = '',
    this.baseUrl = '',
    this.halamanTerakhir,
  });

  final String token;
  final String role; // 'owner' | 'kasir' | ''
  final String nama;
  final String baseUrl;
  final String? halamanTerakhir;

  bool get login => token.isNotEmpty;
  bool get owner => role == 'owner';

  /// Tablet yang baru dinyalakan pertama kali belum tahu harus menembak
  /// server mana — layar pengaturan server muncul lebih dulu daripada login.
  bool get serverSiap => baseUrl.isNotEmpty;

  Sesi salin({
    String? token,
    String? role,
    String? nama,
    String? baseUrl,
    String? halamanTerakhir,
  }) =>
      Sesi(
        token: token ?? this.token,
        role: role ?? this.role,
        nama: nama ?? this.nama,
        baseUrl: baseUrl ?? this.baseUrl,
        halamanTerakhir: halamanTerakhir ?? this.halamanTerakhir,
      );
}

/// Pembaca/penulis sesi ke penyimpanan tablet.
class SesiStore {
  SesiStore(this._prefs);
  final SharedPreferences _prefs;

  static const _kToken = 'otin_token';
  static const _kRole = 'otin_role';
  static const _kNama = 'otin_name';
  static const _kBase = 'otin_base_url';
  static const _kHalaman = 'otinLastPage';

  static Future<SesiStore> buka() async =>
      SesiStore(await SharedPreferences.getInstance());

  Sesi baca() => Sesi(
        token: _prefs.getString(_kToken) ?? '',
        role: _prefs.getString(_kRole) ?? '',
        nama: _prefs.getString(_kNama) ?? '',
        baseUrl: _prefs.getString(_kBase) ?? '',
        halamanTerakhir: _prefs.getString(_kHalaman),
      );

  Future<void> simpanLogin(String token, String role, String nama) async {
    await _prefs.setString(_kToken, token);
    await _prefs.setString(_kRole, role);
    await _prefs.setString(_kNama, nama);
  }

  /// Menghapus token saja, BUKAN alamat server: kasir yang logout tetap di
  /// tablet toko yang sama, jadi jangan memaksa owner mengetik ulang
  /// alamat server tiap ganti shift.
  Future<void> hapusLogin() async {
    await _prefs.remove(_kToken);
    await _prefs.remove(_kRole);
    await _prefs.remove(_kNama);
  }

  /// Membuang alamat server sekalian — dipakai tombol "Ganti server" di
  /// Pengaturan, mis. saat tablet dipindah ke cabang lain.
  Future<void> setBaseUrl(String url) => _prefs.setString(_kBase, _rapikan(url));

  Future<void> simpanHalaman(String id) => _prefs.setString(_kHalaman, id);

  /// Owner mengetik "otin.example.com" atau "192.168.1.5:8000"; keduanya harus
  /// jadi URL yang sah. Tanpa skema dianggap https, kecuali alamat IP LAN yang
  /// hampir pasti http — server di dalam toko jarang punya sertifikat.
  static String _rapikan(String mentah) {
    var s = mentah.trim().replaceAll(RegExp(r'/+$'), '');
    if (s.isEmpty) return '';
    if (!s.startsWith('http://') && !s.startsWith('https://')) {
      final lan = RegExp(r'^(\d{1,3}\.){3}\d{1,3}(:\d+)?$').hasMatch(s) ||
          s.startsWith('localhost');
      final skema = lan ? 'http' : 'https';
      s = '$skema://$s';
    }
    // Owner sering ikut menempelkan "/api" dari dokumentasi; ApiClient sudah
    // menambahkannya sendiri, jadi buang di sini supaya tidak jadi "/api/api".
    return s.replaceAll(RegExp(r'/api$'), '');
  }
}
