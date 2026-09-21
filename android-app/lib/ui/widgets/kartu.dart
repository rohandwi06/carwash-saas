import 'package:flutter/material.dart';

import '../../core/theme.dart';

/// Kartu putih bergaris dengan judul — bentuk dasar hampir semua blok isi di
/// aplikasi ini, menyalin `.card` di kasir.css.
class Kartu extends StatelessWidget {
  const Kartu({
    super.key,
    this.judul,
    this.aksi,
    required this.child,
    this.padding = const EdgeInsets.all(16),
  });

  final String? judul;

  /// Tombol kecil di kanan judul (mis. "Lihat semua", "Tambah").
  final Widget? aksi;
  final Widget child;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      decoration: kotakKartu(),
      padding: padding,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (judul != null) ...[
            Row(
              children: [
                Expanded(child: Text(judul!, style: Teks.judul)),
                if (aksi != null) aksi!,
              ],
            ),
            const SizedBox(height: 12),
          ],
          child,
        ],
      ),
    );
  }
}

/// Satu baris "label di kiri, angka di kanan" — bentuk yang dipakai seluruh
/// rekap uang, menyalin `.cat-baris` di web.
class BarisNilai extends StatelessWidget {
  const BarisNilai({
    super.key,
    required this.label,
    required this.nilai,
    this.warna,
    this.tebal = false,
    this.subLabel,
  });

  final String label;
  final String nilai;
  final Color? warna;
  final bool tebal;
  final String? subLabel;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: tebal ? FontWeight.w900 : FontWeight.w600,
                  ),
                ),
                if (subLabel != null)
                  Text(subLabel!, style: Teks.kecil),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Text(
            nilai,
            style: TextStyle(
              fontSize: tebal ? 17 : 15.5,
              fontWeight: FontWeight.w900,
              color: warna,
            ),
          ),
        ],
      ),
    );
  }
}

/// Kotak angka besar (omzet, laba, jumlah kendaraan).
class KotakStatistik extends StatelessWidget {
  const KotakStatistik({
    super.key,
    required this.label,
    required this.nilai,
    this.warna,
    this.ikon,
  });

  final String label;
  final String nilai;
  final Color? warna;
  final IconData? ikon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: kotakKartu(),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              if (ikon != null) ...[
                Icon(ikon, size: 16, color: Warna.ink2),
                const SizedBox(width: 5),
              ],
              Expanded(
                child: Text(
                  label,
                  overflow: TextOverflow.ellipsis,
                  style: Teks.kecil,
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              nilai,
              style: TextStyle(
                fontSize: 22,
                fontWeight: FontWeight.w900,
                color: warna ?? Warna.ink,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Label kecil berlatar warna — status transaksi, cara bayar, dsb.
class Cip extends StatelessWidget {
  const Cip({
    super.key,
    required this.teks,
    this.latar = Warna.mist,
    this.tinta = Warna.ink2,
    this.ikon,
  });

  final String teks;
  final Color latar;
  final Color tinta;
  final IconData? ikon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(
        color: latar,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (ikon != null) ...[
            Icon(ikon, size: 13, color: tinta),
            const SizedBox(width: 4),
          ],
          Text(
            teks,
            style: TextStyle(
              color: tinta,
              fontSize: 11.5,
              fontWeight: FontWeight.w900,
            ),
          ),
        ],
      ),
    );
  }
}
