import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/format.dart';
import '../../core/theme.dart';

/// Merapikan plat nomor sambil diketik: "n1234ab" -> "N 1234 AB".
///
/// Kursor sengaja diletakkan ulang berdasarkan JUMLAH HURUF/ANGKA sebelum
/// kursor, bukan posisi karakternya — kalau tidak, kursor melompat ke ujung
/// tiap kali sebuah spasi disisipkan, dan kasir yang mengoreksi huruf di
/// tengah plat harus mengetik ulang semuanya. Aturan ini menyalin
/// `rapikanPlat()` di kasir.js.
class FormatterPlat extends TextInputFormatter {
  const FormatterPlat();

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue lama,
    TextEditingValue baru,
  ) {
    final alnumSebelumKursor = baru.text
        .substring(0, baru.selection.baseOffset.clamp(0, baru.text.length))
        .replaceAll(RegExp('[^A-Za-z0-9]'), '')
        .length;

    final teks = formatPlat(baru.text);

    var n = 0;
    var pos = teks.length;
    if (alnumSebelumKursor == 0) {
      pos = 0;
    } else {
      for (var i = 0; i < teks.length; i++) {
        if (teks[i] != ' ') n++;
        if (n == alnumSebelumKursor) {
          pos = i + 1;
          break;
        }
      }
    }

    return TextEditingValue(
      text: teks,
      selection: TextSelection.collapsed(offset: pos),
    );
  }
}

/// Kolom isian uang: keyboard angka, tanpa pemisah ribuan saat diketik
/// (pemisah bikin kursor melompat), dan menolak huruf.
class IsianUang extends StatelessWidget {
  const IsianUang({
    super.key,
    required this.controller,
    this.label,
    this.hint = '0',
    this.autofocus = false,
    this.onSubmit,
  });

  final TextEditingController controller;
  final String? label;
  final String hint;
  final bool autofocus;
  final VoidCallback? onSubmit;

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      autofocus: autofocus,
      keyboardType: const TextInputType.numberWithOptions(decimal: false),
      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
      textInputAction: TextInputAction.done,
      onSubmitted: (_) => onSubmit?.call(),
      style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
      decoration: InputDecoration(
        labelText: label,
        hintText: hint,
        prefixText: 'Rp ',
        prefixStyle: const TextStyle(
          fontSize: 17,
          fontWeight: FontWeight.w800,
          color: Warna.ink2,
        ),
      ),
    );
  }
}

/// Membaca isi kolom uang jadi angka. Kosong = 0, bukan error: kasir yang
/// tidak mengisi tip memang bermaksud "tidak ada tip".
int bacaUang(TextEditingController c) =>
    int.tryParse(c.text.replaceAll(RegExp(r'[^\d]'), '')) ?? 0;

/// Tombol pilihan bergaya "pil" — dipakai untuk layanan, cara bayar, pekerja,
/// dan add-on. Satu bentuk untuk semua pilihan supaya kasir tidak perlu
/// belajar dua gaya tombol yang artinya sama.
class TombolPilih extends StatelessWidget {
  const TombolPilih({
    super.key,
    required this.teks,
    required this.aktif,
    required this.onTap,
    this.subTeks,
    this.ikon,
    this.lebarPenuh = false,
  });

  final String teks;
  final String? subTeks;
  final bool aktif;
  final VoidCallback onTap;
  final IconData? ikon;
  final bool lebarPenuh;

  @override
  Widget build(BuildContext context) {
    final isi = Row(
      mainAxisSize: lebarPenuh ? MainAxisSize.max : MainAxisSize.min,
      children: [
        if (ikon != null) ...[
          Icon(ikon, size: 18, color: aktif ? Warna.tagInk : Warna.ink2),
          const SizedBox(width: 7),
        ],
        Flexible(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                teks,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w900,
                  color: aktif ? Warna.tagInk : Warna.ink,
                ),
              ),
              if (subTeks != null)
                Text(
                  subTeks!,
                  style: TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w700,
                    color: aktif ? Warna.tagInk : Warna.ink2,
                  ),
                ),
            ],
          ),
        ),
        if (aktif) ...[
          const SizedBox(width: 8),
          const Icon(Icons.check_circle, size: 18, color: Warna.tagInk),
        ],
      ],
    );

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        constraints: const BoxConstraints(minHeight: 48),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
        decoration: BoxDecoration(
          color: aktif ? Warna.tag : Warna.card,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: aktif ? Warna.tag : Warna.line,
            width: 2,
          ),
        ),
        child: isi,
      ),
    );
  }
}
