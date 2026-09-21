import 'package:flutter/material.dart';

import '../../core/theme.dart';

/// Siluet kendaraan — padanan `siluetSVG()` di public/js/kasir.js.
///
/// String path-nya sengaja DISALIN APA ADANYA dari file JS itu, bukan digambar
/// ulang dengan koordinat sendiri: kalau bentuknya diubah di web, tinggal
/// tempel ulang string di bawah dan tablet langsung ikut. Yang menerjemahkan
/// string itu jadi gambar adalah [_uraiPath] di bagian bawah file.
class Siluet extends StatelessWidget {
  const Siluet({super.key, required this.bentuk, this.ukuran = 170});

  /// 'moto' | 'hatch' | 'mpv' | 'van'. Nilai lain digambar sebagai 'hatch',
  /// sama seperti `bentukKat()` di web.
  final String bentuk;
  final double ukuran;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: ukuran,
      height: ukuran * 0.55,
      child: CustomPaint(painter: _PelukisSiluet(bentuk)),
    );
  }
}

const _badan = {
  'hatch':
      'M8 44 L12 34 Q14 30 20 29 L30 27 Q40 18 52 18 L66 18 Q76 18 82 26 L88 30 Q94 32 95 38 L96 44 Q96 48 92 48 L86 48 A8 8 0 0 1 70 48 L36 48 A8 8 0 0 1 20 48 L12 48 Q8 48 8 44 Z',
  'mpv':
      'M6 44 L9 34 Q11 29 17 28 L26 26 Q34 15 48 14 L74 14 Q84 14 90 24 L94 30 Q99 33 100 39 L100 44 Q100 48 96 48 L88 48 A8 8 0 0 1 72 48 L36 48 A8 8 0 0 1 20 48 L10 48 Q6 48 6 44 Z',
  'van':
      'M5 44 L6 20 Q6 12 14 12 L88 12 Q95 12 98 20 L102 32 L103 44 Q103 48 99 48 L91 48 A9 9 0 0 1 73 48 L35 48 A9 9 0 0 1 17 48 L9 48 Q5 48 5 44 Z',
};

/// Kaca jendela. Sebagian berupa rect (x, y, lebar, tinggi, radius), sebagian
/// path — dipisah supaya tidak perlu mengubah rect jadi path secara manual.
const Map<String, List<String>> _kacaPath = {
  'hatch': ['M34 28 Q42 21 52 21 L64 21 Q72 21 77 27 L34 28 Z'],
  'mpv': ['M76 18 L84 18 Q88 20 90 26 L76 27 Z'],
  'van': ['M74 17 L88 17 Q92 20 94 28 L74 28 Z'],
};

/// Tiap baris: [x, y, lebar, tinggi, radius]. Tipe map ditulis lengkap supaya
/// angka bulat di bawah ikut jadi double — tanpa itu Dart menyimpulkan
/// List<num> dan Rect.fromLTWH menolaknya.
const Map<String, List<List<double>>> _kacaRect = {
  'hatch': [],
  'mpv': [
    [36, 18, 16, 9, 2],
    [56, 18, 16, 9, 2],
  ],
  'van': [
    [14, 17, 16, 11, 2],
    [34, 17, 16, 11, 2],
    [54, 17, 16, 11, 2],
  ],
};

class _PelukisSiluet extends CustomPainter {
  const _PelukisSiluet(this.bentuk);

  final String bentuk;

  /// viewBox asli di web: 0 0 108 54. Seluruh koordinat di atas memakai skala
  /// itu, jadi kanvas diskalakan sekali di sini alih-alih tiap angka dihitung
  /// ulang.
  static const _lebarAsli = 108.0;
  static const _tinggiAsli = 54.0;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.save();
    canvas.scale(size.width / _lebarAsli, size.height / _tinggiAsli);

    if (bentuk == 'moto') {
      _motor(canvas);
    } else {
      _mobil(canvas, _badan.containsKey(bentuk) ? bentuk : 'hatch');
    }

    canvas.restore();
  }

  void _mobil(Canvas canvas, String b) {
    final isi = Paint()..color = Warna.ink;
    final kaca = Paint()..color = Warna.aqua;

    canvas.drawPath(_uraiPath(_badan[b]!), isi);

    for (final p in _kacaPath[b]!) {
      canvas.drawPath(_uraiPath(p), kaca);
    }
    for (final r in _kacaRect[b]!) {
      canvas.drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromLTWH(r[0], r[1], r[2], r[3]),
          Radius.circular(r[4]),
        ),
        kaca,
      );
    }

    // Roda belakang selalu di x=28; roda depan sedikit berbeda untuk hatch
    // supaya tidak menembus bumper yang lebih pendek.
    _roda(canvas, 28);
    _roda(canvas, b == 'hatch' ? 78 : 81);
  }

  void _roda(Canvas canvas, double x) {
    canvas
      ..drawCircle(Offset(x, 48), 7.5, Paint()..color = Warna.ink)
      ..drawCircle(
        Offset(x, 48),
        7.5,
        Paint()
          ..color = Warna.aqua
          ..style = PaintingStyle.stroke
          ..strokeWidth = 3,
      );
  }

  void _motor(Canvas canvas) {
    Paint garis(double tebal) => Paint()
      ..color = Warna.ink
      ..style = PaintingStyle.stroke
      ..strokeWidth = tebal
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;

    canvas
      ..drawCircle(const Offset(24, 42), 10, garis(5))
      ..drawCircle(const Offset(84, 42), 10, garis(5))
      ..drawPath(
        _uraiPath('M24 42 L40 26 Q44 22 50 24 L64 28 L74 20 L80 20 L84 42'),
        garis(6),
      )
      ..drawPath(_uraiPath('M38 27 Q48 12 60 14'), garis(5))
      ..drawPath(_uraiPath('M70 21 L64 10 L74 10'), garis(4))
      ..drawRRect(
        RRect.fromRectAndRadius(
          const Rect.fromLTWH(44, 20, 18, 6),
          const Radius.circular(3),
        ),
        Paint()..color = Warna.aqua,
      );
  }

  @override
  bool shouldRepaint(covariant _PelukisSiluet lama) => lama.bentuk != bentuk;
}

/// Penerjemah string path SVG jadi [Path] Flutter.
///
/// Hanya mendukung perintah yang benar-benar dipakai siluet di atas — M, L, Q,
/// A, Z, semuanya dengan koordinat absolut. Sengaja tidak memakai paket SVG:
/// menambah satu ketergantungan sebesar itu untuk lima bentuk yang tidak
/// pernah berubah adalah ongkos yang tidak sepadan, dan paket SVG punya
/// riwayat berganti API tiap versi besar.
Path _uraiPath(String d) {
  final path = Path();
  final token = RegExp(r'[MLQAZmlqaz]|-?\d*\.?\d+')
      .allMatches(d)
      .map((m) => m[0]!)
      .toList();

  var i = 0;
  double ambil() => double.parse(token[i++]);

  while (i < token.length) {
    final cmd = token[i++];
    switch (cmd) {
      case 'M':
        path.moveTo(ambil(), ambil());
      case 'L':
        path.lineTo(ambil(), ambil());
      case 'Q':
        path.quadraticBezierTo(ambil(), ambil(), ambil(), ambil());
      case 'A':
        {
          // SVG: A rx ry rotasi busur-besar arah-putar x y.
          // Dibaca ke variabel lebih dulu, bukan langsung jadi argumen, supaya
          // urutan bacanya tidak bergantung pada urutan evaluasi argumen.
          final rx = ambil();
          final ry = ambil();
          final rotasi = ambil();
          final busurBesar = ambil() == 1;
          final searahJarum = ambil() == 1;
          final x = ambil();
          final y = ambil();
          path.arcToPoint(
            Offset(x, y),
            radius: Radius.elliptical(rx, ry),
            rotation: rotasi,
            largeArc: busurBesar,
            clockwise: searahJarum,
          );
        }
      case 'Z':
      case 'z':
        path.close();
      default:
        // Perintah yang belum didukung (mis. C, S, koordinat relatif) sengaja
        // dilewati, bukan melempar: satu bentuk yang cacat jauh lebih baik
        // daripada layar kasir yang mati total di tengah antrean.
        break;
    }
  }
  return path;
}
