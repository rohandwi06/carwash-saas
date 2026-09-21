import 'package:esc_pos_utils_plus/esc_pos_utils_plus.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../data/models/transaksi.dart';
import 'format.dart';

/// Pencetak struk ke printer thermal Bluetooth (ESC/POS, kertas 58mm).
///
/// SELURUH file ini dirancang supaya app tetap jalan penuh tanpa printer.
/// Cucian yang membeli tablet tanpa printer harus tetap bisa berjualan; yang
/// hilang cuma struk kertas, bukan kemampuan mencatat transaksi. Karena itu
/// tidak ada satu pun jalur di sini yang boleh melempar keluar — semuanya
/// kembali sebagai [HasilCetak] yang bisa ditampilkan apa adanya ke kasir.
class Pencetak {
  static const _kPrinter = 'otin_printer_mac';

  /// MAC printer yang dipilih owner, disimpan supaya kasir tidak perlu
  /// memilih ulang tiap kali mencetak.
  static Future<String?> printerTersimpan() async {
    final p = await SharedPreferences.getInstance();
    return p.getString(_kPrinter);
  }

  static Future<void> simpanPrinter(String mac) async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_kPrinter, mac);
  }

  static Future<void> lupakanPrinter() async {
    final p = await SharedPreferences.getInstance();
    await p.remove(_kPrinter);
  }

  /// Android 12+ butuh izin BLUETOOTH_CONNECT/SCAN saat berjalan, bukan cuma
  /// di manifest. Tanpa ini daftar printer selalu kosong tanpa pesan apa pun
  /// — gejala yang sangat membingungkan di lapangan.
  static Future<bool> mintaIzin() async {
    final hasil = await [
      Permission.bluetoothConnect,
      Permission.bluetoothScan,
    ].request();
    return hasil.values.every((s) => s.isGranted);
  }

  /// Printer yang sudah dipasangkan (paired) di pengaturan Bluetooth Android.
  /// Sengaja tidak memindai perangkat baru: memasangkan printer adalah kerja
  /// sekali saat pemasangan tablet, dan menu pindai hanya akan membingungkan
  /// kasir yang tiap hari cuma perlu mencetak.
  static Future<List<PrinterInfo>> daftarPrinter() async {
    if (!await mintaIzin()) return const [];
    if (!await PrintBluetoothThermal.bluetoothEnabled) return const [];

    final perangkat = await PrintBluetoothThermal.pairedBluetooths;
    return perangkat
        .map((d) => PrinterInfo(nama: d.name, mac: d.macAdress))
        .toList(growable: false);
  }

  /// Mencetak satu struk transaksi cuci.
  static Future<HasilCetak> cetakStruk(
    Transaksi trx, {
    String namaToko = 'OTIN CARWASH',
    String subJudul = 'Cuci Mobil & Motor',
  }) async {
    final mac = await printerTersimpan();
    if (mac == null) {
      return const HasilCetak.gagal(
        'Printer belum dipilih. Atur di Pengaturan → Printer.',
      );
    }

    try {
      if (!await PrintBluetoothThermal.bluetoothEnabled) {
        return const HasilCetak.gagal('Bluetooth tablet sedang mati.');
      }

      // Sambungan Bluetooth ke printer thermal sering putus sendiri setelah
      // beberapa menit menganggur, jadi disambungkan ulang tiap cetak alih-alih
      // dipelihara terus. Menyambung ulang jauh lebih murah daripada satu
      // struk gagal di depan pelanggan.
      final tersambung = await PrintBluetoothThermal.connectionStatus;
      if (!tersambung) {
        final ok = await PrintBluetoothThermal.connect(macPrinterAddress: mac);
        if (!ok) {
          return const HasilCetak.gagal(
            'Printer tidak menjawab. Pastikan printer menyala dan dekat.',
          );
        }
      }

      final bytes = await _susunStruk(trx, namaToko, subJudul);
      final terkirim = await PrintBluetoothThermal.writeBytes(bytes);

      return terkirim
          ? const HasilCetak.sukses()
          : const HasilCetak.gagal('Struk gagal dikirim ke printer.');
    } catch (e) {
      return HasilCetak.gagal('Printer bermasalah: $e');
    }
  }

  static Future<List<int>> _susunStruk(
    Transaksi trx,
    String namaToko,
    String subJudul,
  ) async {
    final profil = await CapabilityProfile.load();
    final gen = Generator(PaperSize.mm58, profil);
    final waktu = trx.dibuatPada ?? DateTime.now();

    var b = <int>[];

    b += gen.text(
      namaToko,
      styles: const PosStyles(
        align: PosAlign.center,
        bold: true,
        height: PosTextSize.size2,
        width: PosTextSize.size2,
      ),
    );
    b += gen.text(subJudul, styles: const PosStyles(align: PosAlign.center));
    b += gen.hr();

    b += _baris(gen, tglPanjang(waktu), jam(waktu));
    b += _baris(gen, 'No. antrian', '${trx.noAntrian}');
    b += gen.hr();

    b += _baris(gen, 'Kendaraan', trx.namaKendaraan);
    b += _baris(gen, 'Plat', trx.plat ?? '-');
    b += _baris(
      gen,
      'Bayar',
      trx.caraBayar == 'cash' ? 'CASH' : 'TRANSFER',
    );

    if (trx.addons.isNotEmpty) {
      b += gen.hr();
      for (final a in trx.addons) {
        b += _baris(gen, '+ ${a.nama}', rp(a.harga));
      }
    }

    final fnb = trx.itemFnb;
    if (fnb.isNotEmpty) {
      b += gen.hr();
      for (final i in fnb) {
        b += _baris(gen, '${i.namaProduk} x${i.qty}', rp(i.subtotal));
      }
    }

    b += gen.hr();
    b += gen.row([
      PosColumn(
        text: 'TOTAL',
        width: 5,
        styles: const PosStyles(bold: true, height: PosTextSize.size2),
      ),
      PosColumn(
        text: rp(trx.totalSemua),
        width: 7,
        styles: const PosStyles(
          align: PosAlign.right,
          bold: true,
          height: PosTextSize.size2,
        ),
      ),
    ]);

    if (trx.tip > 0) {
      b += _baris(gen, 'Tip', rp(trx.tip));
    }

    b += gen.hr();
    b += gen.text(
      'Terima kasih!',
      styles: const PosStyles(align: PosAlign.center, bold: true),
    );
    b += gen.text(
      'Simpan resi untuk ambil kendaraan',
      styles: const PosStyles(align: PosAlign.center),
    );
    b += gen.feed(2);
    b += gen.cut();

    return b;
  }

  /// Baris "kiri ... kanan". Lebar 12 kolom dibagi 5:7 karena nilainya
  /// (rupiah) hampir selalu lebih panjang daripada labelnya.
  static List<int> _baris(Generator gen, String kiri, String kanan) => gen.row([
        PosColumn(text: kiri, width: 5),
        PosColumn(
          text: kanan,
          width: 7,
          styles: const PosStyles(align: PosAlign.right),
        ),
      ]);
}

class PrinterInfo {
  const PrinterInfo({required this.nama, required this.mac});
  final String nama;
  final String mac;
}

/// Hasil percobaan cetak. Sengaja BUKAN exception: gagal mencetak bukan
/// kegagalan transaksi — uangnya sudah masuk dan tercatat di server, jadi
/// layar tidak boleh memperlakukannya sebagai kesalahan fatal.
class HasilCetak {
  const HasilCetak.sukses() : berhasil = true, pesan = null;
  const HasilCetak.gagal(this.pesan) : berhasil = false;

  final bool berhasil;
  final String? pesan;
}
