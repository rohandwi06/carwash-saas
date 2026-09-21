import 'package:flutter/material.dart';

import '../../core/theme.dart';

/// Layar tunggu seluruh halaman.
class Pemuat extends StatelessWidget {
  const Pemuat({super.key, this.pesan});

  final String? pesan;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const CircularProgressIndicator(color: Warna.water),
            if (pesan != null) ...[
              const SizedBox(height: 16),
              Text(pesan!, style: Teks.subjudul),
            ],
          ],
        ),
      ),
    );
  }
}

/// Kegagalan memuat data, dengan tombol coba lagi.
///
/// Kasir tidak boleh dibiarkan menatap layar kosong: kalau server mati atau
/// WiFi putus, ia harus tahu APA yang salah dan bisa mencoba lagi sendiri
/// tanpa menutup aplikasi.
class LayarGagalMuat extends StatelessWidget {
  const LayarGagalMuat({super.key, required this.pesan, this.onCoba});

  final String pesan;
  final VoidCallback? onCoba;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: KotakGagal(pesan: pesan, onCoba: onCoba),
        ),
      ),
    );
  }
}

/// Versi yang bisa ditaruh di dalam layar lain (mis. satu kartu yang gagal
/// dimuat), tanpa mengambil alih seluruh halaman.
class KotakGagal extends StatelessWidget {
  const KotakGagal({super.key, required this.pesan, this.onCoba});

  final String pesan;
  final VoidCallback? onCoba;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(20),
      decoration: kotakKartu(garis: Warna.danger),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.cloud_off, size: 44, color: Warna.danger),
          const SizedBox(height: 12),
          const Text('Gagal memuat data', style: Teks.judul),
          const SizedBox(height: 6),
          Text(
            pesan,
            textAlign: TextAlign.center,
            style: Teks.subjudul,
          ),
          if (onCoba != null) ...[
            const SizedBox(height: 16),
            OutlinedButton.icon(
              onPressed: onCoba,
              icon: const Icon(Icons.refresh),
              label: const Text('Coba lagi'),
            ),
          ],
        ],
      ),
    );
  }
}

/// Keadaan kosong yang disengaja — bukan error. Dibedakan supaya kasir tidak
/// mengira daftar yang memang belum terisi adalah aplikasi yang rusak.
class Kosong extends StatelessWidget {
  const Kosong({super.key, required this.pesan, this.ikon});

  final String pesan;
  final IconData? ikon;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 28, horizontal: 16),
      child: Column(
        children: [
          Icon(ikon ?? Icons.inbox_outlined, size: 36, color: Warna.redup),
          const SizedBox(height: 10),
          Text(pesan, textAlign: TextAlign.center, style: Teks.subjudul),
        ],
      ),
    );
  }
}
