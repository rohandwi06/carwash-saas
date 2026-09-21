import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../data/models/json_util.dart';
import '../../state/providers.dart';
import '../widgets/kartu.dart';
import '../widgets/pemuat.dart';

/// Dashboard owner: tren omzet + peringkat layanan/kategori/add-on.
/// Padanan `layarDashboard` di web (yang memakai Chart.js).
///
/// Endpoint-nya owner-only di server, jadi kasir yang entah bagaimana sampai
/// ke sini tetap dapat 403 — tampilan bukan penjaganya.
class LayarDashboard extends ConsumerStatefulWidget {
  const LayarDashboard({super.key});

  @override
  ConsumerState<LayarDashboard> createState() => _LayarDashboardState();
}

final _statistikProvider =
    FutureProvider.family<Map<String, dynamic>, String>((ref, periode) {
  return ref.watch(laporanRepoProvider).statistik(periode: periode);
});

class _LayarDashboardState extends ConsumerState<LayarDashboard> {
  String _periode = 'harian';

  static const _pilihanPeriode = {
    'harian': '30 hari',
    'mingguan': '12 minggu',
    'bulanan': '12 bulan',
  };

  @override
  Widget build(BuildContext context) {
    final stat = ref.watch(_statistikProvider(_periode));

    return stat.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: KotakGagal(
            pesan: '$e',
            onCoba: () => ref.invalidate(_statistikProvider(_periode)),
          ),
        ),
      ),
      data: (d) {
        final ringkas = asMap(d['summary']);
        final titik = (d['points'] as List? ?? const [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList(growable: false);

        return RefreshIndicator(
          onRefresh: () async => ref.invalidate(_statistikProvider(_periode)),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
            children: [
              _pemilihPeriode(),
              const SizedBox(height: 14),
              _ringkasan(ringkas),
              const SizedBox(height: 14),
              _grafikTren(titik, asStr(d['label'])),
              const SizedBox(height: 14),
              _peringkat('Jenis layanan', d['services']),
              const SizedBox(height: 14),
              _peringkat('Jenis kendaraan', d['categories']),
              const SizedBox(height: 14),
              _peringkat('Layanan tambahan', d['addons']),
            ],
          ),
        );
      },
    );
  }

  Widget _pemilihPeriode() {
    return Row(
      children: [
        for (final e in _pilihanPeriode.entries) ...[
          Expanded(
            child: InkWell(
              onTap: () => setState(() => _periode = e.key),
              borderRadius: BorderRadius.circular(12),
              child: Container(
                padding: const EdgeInsets.symmetric(vertical: 12),
                decoration: BoxDecoration(
                  color: _periode == e.key ? Warna.ink : Warna.card,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(
                    color: _periode == e.key ? Warna.ink : Warna.line,
                    width: 2,
                  ),
                ),
                child: Text(
                  e.value,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: _periode == e.key ? Colors.white : Warna.ink2,
                    fontWeight: FontWeight.w900,
                    fontSize: 14,
                  ),
                ),
              ),
            ),
          ),
          if (e.key != 'bulanan') const SizedBox(width: 8),
        ],
      ],
    );
  }

  Widget _ringkasan(Map<String, dynamic> s) {
    return Column(
      children: [
        Row(
          children: [
            Expanded(
              child: KotakStatistik(
                label: 'Omzet',
                nilai: rp(asInt(s['omzet'])),
                ikon: Icons.trending_up,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: KotakStatistik(
                label: 'Laba bersih',
                nilai: rp(asInt(s['profit'])),
                warna: asInt(s['profit']) < 0 ? Warna.danger : Warna.go,
                ikon: Icons.savings_outlined,
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: KotakStatistik(
                label: 'Kendaraan',
                nilai: '${asInt(s['vehicles'])}',
                ikon: Icons.local_car_wash,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: KotakStatistik(
                label: 'Rata-rata/hari aktif',
                nilai: rp(asInt(s['avg_omzet'])),
                ikon: Icons.calendar_today,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: KotakStatistik(
                label: 'Rata-rata/mobil',
                nilai: rp(asInt(s['avg_ticket'])),
                ikon: Icons.receipt,
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Kartu(
          child: Column(
            children: [
              BarisNilai(label: 'Cucian', nilai: rp(asInt(s['wash']))),
              BarisNilai(label: 'F&B', nilai: rp(asInt(s['fnb']))),
              BarisNilai(label: 'Tip', nilai: rp(asInt(s['tip']))),
              BarisNilai(
                label: 'Upah pekerja',
                nilai: '-${rp(asInt(s['wages']))}',
                warna: Warna.danger,
              ),
              BarisNilai(
                label: 'Pengeluaran',
                nilai: '-${rp(asInt(s['expenses']))}',
                warna: Warna.danger,
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _grafikTren(List<Map<String, dynamic>> titik, String judul) {
    if (titik.isEmpty) {
      return const Kartu(
        judul: 'Tren omzet',
        child: Kosong(pesan: 'Belum ada data.'),
      );
    }

    final nilai = titik.map((t) => asInt(t['omzet']).toDouble()).toList();
    final maks = nilai.fold<double>(0, (a, b) => b > a ? b : a);

    return Kartu(
      judul: 'Tren omzet',
      aksi: Text(judul, style: Teks.kecil),
      child: SizedBox(
        height: 210,
        child: LineChart(
          LineChartData(
            minY: 0,
            // Batas atas diberi kelonggaran 15% supaya puncak grafik tidak
            // menempel di garis paling atas dan angkanya masih kebaca.
            maxY: maks == 0 ? 10 : maks * 1.15,
            gridData: FlGridData(
              show: true,
              drawVerticalLine: false,
              getDrawingHorizontalLine: (_) =>
                  const FlLine(color: Warna.line, strokeWidth: 1),
            ),
            borderData: FlBorderData(show: false),
            titlesData: FlTitlesData(
              topTitles: const AxisTitles(
                sideTitles: SideTitles(showTitles: false),
              ),
              rightTitles: const AxisTitles(
                sideTitles: SideTitles(showTitles: false),
              ),
              leftTitles: AxisTitles(
                sideTitles: SideTitles(
                  showTitles: true,
                  reservedSize: 52,
                  getTitlesWidget: (v, _) => Text(
                    _ringkasRupiah(v),
                    style: const TextStyle(fontSize: 10, color: Warna.ink2),
                  ),
                ),
              ),
              bottomTitles: AxisTitles(
                sideTitles: SideTitles(
                  showTitles: true,
                  reservedSize: 28,
                  // Label sumbu-X dijarangkan: 30 titik yang semuanya berlabel
                  // jadi tumpang tindih dan tidak terbaca sama sekali.
                  interval: (titik.length / 6).ceilToDouble(),
                  getTitlesWidget: (v, _) {
                    final i = v.round();
                    if (i < 0 || i >= titik.length) {
                      return const SizedBox.shrink();
                    }
                    return Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Text(
                        asStr(titik[i]['label']),
                        style: const TextStyle(
                          fontSize: 9.5,
                          color: Warna.ink2,
                        ),
                      ),
                    );
                  },
                ),
              ),
            ),
            lineBarsData: [
              LineChartBarData(
                spots: [
                  for (var i = 0; i < nilai.length; i++)
                    FlSpot(i.toDouble(), nilai[i]),
                ],
                isCurved: true,
                curveSmoothness: 0.25,
                color: Warna.water,
                barWidth: 3,
                dotData: FlDotData(show: titik.length <= 14),
                belowBarData: BarAreaData(
                  show: true,
                  color: Warna.waterPudar,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _peringkat(String judul, dynamic data) {
    final baris = (data as List? ?? const [])
        .whereType<Map>()
        .map((e) => e.cast<String, dynamic>())
        .toList(growable: false);

    if (baris.isEmpty) {
      return Kartu(judul: judul, child: const Kosong(pesan: 'Belum ada data.'));
    }

    final tertinggi = asInt(baris.first['total']);

    return Kartu(
      judul: judul,
      child: Column(
        children: [
          for (final r in baris)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(asStr(r['label']), style: Teks.label),
                      ),
                      Text(
                        '${asInt(r['count'])}x · ${rp(asInt(r['total']))}',
                        style: Teks.kecil,
                      ),
                    ],
                  ),
                  const SizedBox(height: 5),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(6),
                    child: LinearProgressIndicator(
                      value: tertinggi == 0
                          ? 0
                          : asInt(r['total']) / tertinggi,
                      minHeight: 8,
                      backgroundColor: Warna.mist,
                      color: Warna.tag,
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// Rupiah dipendekkan untuk label sumbu grafik: 1.250.000 -> "1,3jt".
/// Sumbu yang menulis angka penuh membuat grafiknya tergencet ke kanan.
String _ringkasRupiah(double v) {
  if (v >= 1000000) return '${(v / 1000000).toStringAsFixed(1)}jt';
  if (v >= 1000) return '${(v / 1000).round()}rb';
  return v.round().toString();
}
