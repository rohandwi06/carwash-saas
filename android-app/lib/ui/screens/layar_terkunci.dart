import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme.dart';
import '../../data/models/laporan.dart';
import '../../state/providers.dart';

/// Layar di luar jam operasional. Padanan `tampilkanTerkunci()` di kasir.js.
///
/// Yang penting di sini bukan penguncian itu sendiri, melainkan JAWABAN atas
/// pertanyaan yang pasti muncul: "jam berapa saya boleh mulai lagi?".
/// Layar kunci tanpa jadwal cuma bikin kasir menelepon owner.
class LayarTerkunci extends ConsumerWidget {
  const LayarTerkunci({super.key, required this.status});

  final StatusShift status;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final berikut = status.shiftBerikut;

    return Scaffold(
      backgroundColor: Warna.ink,
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(28),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 460),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.lock_clock, size: 64, color: Warna.tag),
                const SizedBox(height: 18),
                const Text(
                  'Di luar jam operasional',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 24,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 10),
                Text(
                  status.pesan.isEmpty
                      ? 'Kasir baru bisa dipakai saat toko buka.'
                      : status.pesan,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Warna.redup, fontSize: 15),
                ),
                const SizedBox(height: 24),

                if (berikut != null)
                  _Kotak(
                    judul: 'Buka lagi',
                    isi: '${berikut.nama} · ${berikut.rentang}',
                  )
                else
                  const _Kotak(
                    judul: 'Jadwal berikutnya',
                    isi: 'Belum ada shift aktif berikutnya',
                  ),

                const SizedBox(height: 10),
                _Kotak(judul: 'Sekarang', isi: status.jamSekarang),

                if (status.semuaShift.isNotEmpty) ...[
                  const SizedBox(height: 20),
                  const Text(
                    'Jadwal hari ini',
                    style: TextStyle(
                      color: Warna.redup,
                      fontSize: 12.5,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 0.5,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Wrap(
                    alignment: WrapAlignment.center,
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final s in status.semuaShift)
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 12,
                            vertical: 7,
                          ),
                          decoration: BoxDecoration(
                            color: s.aktif ? Warna.tag : Colors.white10,
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Text(
                            '${s.nama} ${s.rentang}',
                            style: TextStyle(
                              color: s.aktif ? Warna.tagInk : Warna.redup,
                              fontSize: 12.5,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ),
                    ],
                  ),
                ],

                const SizedBox(height: 30),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: () => ref.invalidate(shiftProvider),
                    icon: const Icon(Icons.refresh),
                    label: const Text('Cek lagi'),
                  ),
                ),
                const SizedBox(height: 10),
                TextButton(
                  onPressed: () => ref.read(sesiProvider.notifier).keluar(),
                  child: const Text('Keluar dari akun'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Kotak extends StatelessWidget {
  const _Kotak({required this.judul, required this.isi});

  final String judul;
  final String isi;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: Colors.white10,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            judul,
            style: const TextStyle(color: Warna.redup, fontSize: 13.5),
          ),
          Flexible(
            child: Text(
              isi,
              textAlign: TextAlign.right,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 15,
                fontWeight: FontWeight.w900,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
