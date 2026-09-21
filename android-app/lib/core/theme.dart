import 'package:flutter/material.dart';

/// Palet OTIN CARWASH — disalin apa adanya dari `:root` di public/css/kasir.css
/// supaya app tablet dan web kasir terlihat satu produk. Kalau warna di web
/// diubah, ubah di sini juga; sengaja tidak diambil dari server karena warna
/// adalah identitas merek, bukan data toko.
abstract final class Warna {
  static const ink = Color(0xFF141414);
  static const ink2 = Color(0xFF57503E);
  static const mist = Color(0xFFF6F5F0);
  static const card = Color(0xFFFFFFFF);
  static const water = Color(0xFFE5A800);
  static const waterDk = Color(0xFF8F6B00);
  static const aqua = Color(0xFFFFD34D);
  static const tag = Color(0xFFFFC229);
  static const tagInk = Color(0xFF4A3400);
  static const go = Color(0xFF1B9E62);
  static const goDk = Color(0xFF157A4C);
  static const line = Color(0xFFE5E0D2);
  static const danger = Color(0xFFC0442B);
  static const redup = Color(0xFFA8A190);

  /// Versi pudar yang DIHITUNG DI MUKA, bukan lewat withOpacity/withValues.
  /// Kedua fungsi itu berganti nama antar versi Flutter (withOpacity jadi
  /// usang, withValues belum ada di SDK lama), jadi memakainya membuat app
  /// gagal ter-build di sebagian tablet. Warna tetap tidak punya masalah itu.
  static const dangerPudar = Color(0xFFF7E4E0); // di atas latar terang
  static const dangerGelap = Color(0xFF3A1A14); // di atas latar hitam
  static const goPudar = Color(0xFFE3F3EB);
  static const waterPudar = Color(0xFFFDF3DC);
  static const tagPudar = Color(0xFFFFF6DC);
}

/// Tinggi sentuh minimum. Kasir memakai tablet sambil berdiri, sering dengan
/// tangan basah — tombol kecil bikin salah pencet, dan salah pencet di layar
/// kasir berarti salah uang. Semua tombol utama dipatok setinggi ini.
const double tinggiSentuh = 56;

/// Lebar isi maksimum, menyalin `max-width:860px` di .body web. Tanpa ini,
/// di tablet 10 inci mendatar satu baris jadi selebar layar dan mata kasir
/// harus menyapu terlalu jauh untuk membaca satu transaksi.
const double lebarIsi = 860;

/// Sengaja TIDAK memakai `cardTheme` / `appBarTheme` di ThemeData: nama kelas
/// keduanya berganti (CardTheme -> CardThemeData, AppBarTheme ->
/// AppBarThemeData) di Flutter versi baru, dan app ini harus tetap ter-build
/// di SDK tablet yang mungkin lebih tua/baru. Kartu memakai [kotakKartu] di
/// bawah, header memakai widget sendiri di ui/widgets/header.dart.
ThemeData temaOtin() {
  const skema = ColorScheme.light(
    primary: Warna.water,
    onPrimary: Colors.white,
    secondary: Warna.tag,
    onSecondary: Warna.tagInk,
    surface: Warna.card,
    onSurface: Warna.ink,
    error: Warna.danger,
    onError: Colors.white,
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: skema,
    scaffoldBackgroundColor: Warna.mist,

    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Warna.card,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      border: _garis(Warna.line),
      enabledBorder: _garis(Warna.line),
      focusedBorder: _garis(Warna.water, tebal: 2.5),
      errorBorder: _garis(Warna.danger),
      hintStyle: const TextStyle(
        color: Warna.redup,
        fontWeight: FontWeight.w600,
      ),
    ),

    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        minimumSize: const Size.fromHeight(tinggiSentuh),
        backgroundColor: Warna.go,
        foregroundColor: Colors.white,
        textStyle: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
    ),

    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        minimumSize: const Size.fromHeight(tinggiSentuh),
        foregroundColor: Warna.ink,
        side: const BorderSide(color: Warna.line, width: 2),
        textStyle: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
    ),

    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(
        foregroundColor: Warna.waterDk,
        textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800),
      ),
    ),

    dividerTheme: const DividerThemeData(color: Warna.line, thickness: 1.5),

    snackBarTheme: const SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor: Warna.ink,
      contentTextStyle: TextStyle(color: Colors.white, fontSize: 15),
    ),
  );
}

OutlineInputBorder _garis(Color c, {double tebal = 2}) => OutlineInputBorder(
      borderRadius: BorderRadius.circular(14),
      borderSide: BorderSide(color: c, width: tebal),
    );

/// Kotak putih bergaris — bentuk dasar hampir semua kartu di app ini,
/// menyalin `.kartu-mobil` / `.card` di web.
BoxDecoration kotakKartu({Color? garis, double radius = 16, Color? isi}) =>
    BoxDecoration(
      color: isi ?? Warna.card,
      borderRadius: BorderRadius.circular(radius),
      border: Border.all(color: garis ?? Warna.line, width: 2),
    );

/// Gaya teks yang dipakai berulang di banyak layar.
abstract final class Teks {
  static const judul = TextStyle(fontSize: 20, fontWeight: FontWeight.w900);
  static const judulHalaman = TextStyle(
    fontSize: 17,
    fontWeight: FontWeight.w900,
    letterSpacing: 0.3,
  );
  static const subjudul = TextStyle(
    fontSize: 13.5,
    fontWeight: FontWeight.w700,
    color: Warna.ink2,
  );
  static const angkaBesar = TextStyle(fontSize: 28, fontWeight: FontWeight.w900);
  static const label = TextStyle(fontSize: 15, fontWeight: FontWeight.w800);
  static const kecil = TextStyle(fontSize: 12.5, color: Warna.ink2);
}
