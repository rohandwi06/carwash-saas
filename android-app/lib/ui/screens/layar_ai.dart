import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../data/models/json_util.dart';
import '../../data/models/katalog.dart';
import '../../data/repositories/repo.dart';
import '../../state/providers.dart';
import '../widgets/siluet.dart';

/// Tanya Gemini kendaraan apa ini. Padanan modal AI di kasir.js.
///
/// Alurnya sengaja bertahap dan TIDAK otomatis: AI menjawab -> kasir MELIHAT
/// jawabannya -> kasir menekan konfirmasi -> baru tersimpan ke database.
/// Kalau AI langsung menyimpan sendiri, satu tebakan meleset akan mengendap
/// selamanya di daftar kendaraan dan muncul di pencarian semua orang.
///
/// Setelah tersimpan, kendaraan itu ketemu lewat pencarian biasa — AI tidak
/// ditanya dua kali untuk mobil yang sama.
class DialogAi extends ConsumerStatefulWidget {
  const DialogAi({super.key, required this.namaAwal, required this.konfig});

  final String namaAwal;
  final Konfig konfig;

  @override
  ConsumerState<DialogAi> createState() => _DialogAiState();
}

class _DialogAiState extends ConsumerState<DialogAi> {
  late final TextEditingController _q =
      TextEditingController(text: widget.namaAwal);

  bool _menanya = false;
  bool _menyimpan = false;
  String? _error;

  /// Usulan AI yang belum dikonfirmasi. null = belum bertanya, atau AI
  /// menjawab tidak tahu.
  Map<String, dynamic>? _usulan;
  bool _tidakDikenali = false;

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  Future<void> _tanya() async {
    final q = _q.text.trim();
    if (q.isEmpty) return;

    setState(() {
      _menanya = true;
      _error = null;
      _usulan = null;
      _tidakDikenali = false;
    });

    try {
      final r = await ref.read(aiRepoProvider).tebakKendaraan(q);
      if (!mounted) return;
      setState(() {
        _menanya = false;
        if (asBool(r['recognized'])) {
          _usulan = r;
        } else {
          _tidakDikenali = true;
        }
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _menanya = false;
        _error = '$e';
      });
    }
  }

  Future<void> _pakai() async {
    final u = _usulan;
    if (u == null) return;

    setState(() => _menyimpan = true);
    final nama = asStr(u['name']);
    final kategori = asStr(u['category']);

    try {
      // Kendaraan yang ternyata SUDAH ada tidak disimpan ulang — server
      // memang memakai firstOrCreate, tapi melewatinya di sini menghemat satu
      // permintaan dan membuat maksudnya jelas terbaca.
      if (!asBool(u['already_exists'])) {
        await ref
            .read(aiRepoProvider)
            .simpanKendaraan(nama: nama, kategori: kategori);
      }
      if (!mounted) return;
      Navigator.pop(
        context,
        Kendaraan(id: 0, nama: nama, kategori: kategori),
      );
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _menyimpan = false;
        _error = '$e';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Row(
        children: [
          Icon(Icons.auto_awesome, color: Warna.water),
          SizedBox(width: 8),
          Text('Tanya AI', style: Teks.judul),
        ],
      ),
      content: SizedBox(
        width: 420,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Ketik nama kendaraan yang tidak ketemu di pencarian. '
                'AI menebak jenisnya; kamu yang memutuskan.',
                style: Teks.subjudul,
              ),
              const SizedBox(height: 14),
              TextField(
                controller: _q,
                autofocus: true,
                enabled: !_menanya && !_menyimpan,
                textInputAction: TextInputAction.search,
                onSubmitted: (_) => _tanya(),
                decoration: const InputDecoration(
                  hintText: 'mis. wuling almaz',
                  prefixIcon: Icon(Icons.directions_car_outlined),
                ),
              ),
              const SizedBox(height: 14),
              if (_menanya)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 20),
                  child: Center(child: CircularProgressIndicator()),
                ),
              if (_error != null) _kotakPesan(_error!, Warna.danger),
              if (_tidakDikenali)
                _kotakPesan(
                  'AI juga tidak yakin ini kendaraan apa.\n'
                  'Pilih jenisnya manual saja di layar kasir.',
                  Warna.ink2,
                ),
              if (_usulan != null) _hasil(_usulan!),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed:
              _menyimpan ? null : () => Navigator.pop(context),
          child: const Text('Tutup'),
        ),
        if (_usulan == null)
          FilledButton.icon(
            style: FilledButton.styleFrom(
              backgroundColor: Warna.water,
              minimumSize: const Size(120, 44),
            ),
            onPressed: _menanya ? null : _tanya,
            icon: const Icon(Icons.auto_awesome, size: 18),
            label: const Text('Tanya'),
          )
        else
          FilledButton(
            style: FilledButton.styleFrom(minimumSize: const Size(120, 44)),
            onPressed: _menyimpan ? null : _pakai,
            child: Text(
              asBool(_usulan!['already_exists'])
                  ? 'Pakai kendaraan ini'
                  : 'Simpan & pakai',
            ),
          ),
      ],
    );
  }

  Widget _hasil(Map<String, dynamic> u) {
    final kategori = widget.konfig.kategori[asStr(u['category'])];
    final alasan = asStrNull(u['reason']);
    final sudahAda = asBool(u['already_exists']);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: kotakKartu(garis: Warna.water),
      child: Column(
        children: [
          Siluet(bentuk: kategori?.bentuk ?? 'hatch', ukuran: 130),
          const SizedBox(height: 8),
          Text(
            asStr(u['name']),
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w900),
          ),
          const SizedBox(height: 2),
          Text(
            '${asStr(u['category_label'])} · ${rp(asInt(u['price']))}',
            style: Teks.subjudul,
          ),
          if (alasan != null) ...[
            const SizedBox(height: 10),
            Text(
              '"$alasan"',
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 13,
                fontStyle: FontStyle.italic,
                color: Warna.ink2,
              ),
            ),
          ],
          if (sudahAda) ...[
            const SizedBox(height: 12),
            _kotakPesan(
              'Kendaraan ini sudah ada di database — lain kali cukup cari '
              'langsung di kolom pencarian.',
              Warna.go,
            ),
          ],
        ],
      ),
    );
  }

  Widget _kotakPesan(String teks, Color warna) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(top: 4),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: warna == Warna.danger ? Warna.dangerPudar : Warna.mist,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: warna),
      ),
      child: Text(
        teks,
        style: TextStyle(
          color: warna,
          fontSize: 13.5,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}
