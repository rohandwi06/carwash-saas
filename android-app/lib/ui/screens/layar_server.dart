import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/direktori.dart';
import '../../core/theme.dart';
import '../../data/session.dart';
import '../../state/providers.dart';
import '../widgets/nama_usaha.dart';

/// Layar pertama di aplikasi yang baru dipasang: cucian mana yang dipakai.
///
/// Tidak ada padanannya di web — di sana file JS-nya disajikan dari server
/// yang sama, jadi cukup path relatif `/api`. Aplikasi ini dipakai banyak
/// cucian, dan tiap cucian punya servernya sendiri.
///
/// Cara utamanya KODE CUCIAN (mis. `kembar99`), yang diterjemahkan jadi
/// alamat lewat [Direktori] — pengguna Play Store tidak boleh disuruh
/// mengetik alamat server. Alamat langsung tetap bisa diisi untuk server di
/// dalam toko (alamat IP) atau cucian yang belum masuk daftar.
class LayarServer extends ConsumerStatefulWidget {
  const LayarServer({super.key});

  @override
  ConsumerState<LayarServer> createState() => _LayarServerState();
}

class _LayarServerState extends ConsumerState<LayarServer> {
  final _isian = TextEditingController();
  bool _pakaiAlamat = false;
  bool _menyimpan = false;
  String? _galat;

  @override
  void dispose() {
    _isian.dispose();
    super.dispose();
  }

  void _gantiCara() => setState(() {
        _pakaiAlamat = !_pakaiAlamat;
        _isian.clear();
        _galat = null;
      });

  Future<void> _simpan() async {
    final isi = _isian.text.trim();
    if (isi.isEmpty || _menyimpan) return;
    setState(() {
      _menyimpan = true;
      _galat = null;
    });
    try {
      final url = _pakaiAlamat ? SesiStore.rapikan(isi) : await Direktori.alamatDariKode(isi);
      await Direktori.pastikanServer(url);
      // Tidak perlu pindah layar sendiri: Gerbang memantau sesi dan otomatis
      // berganti ke layar login begitu alamatnya terisi.
      await ref.read(sesiProvider.notifier).setServer(url);
    } on GagalCucian catch (e) {
      if (mounted) setState(() => _galat = e.pesan);
    } finally {
      if (mounted) setState(() => _menyimpan = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Warna.ink,
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(28),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 480),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text('💧', style: TextStyle(fontSize: 46)),
                const SizedBox(height: 8),
                const NamaUsaha(ukuran: 30, jarakHuruf: 1.5, rataTengah: true),
                const SizedBox(height: 26),
                Text(
                  _pakaiAlamat ? 'Alamat server' : 'Kode cucian',
                  style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w800),
                ),
                const SizedBox(height: 6),
                Text(
                  _pakaiAlamat
                      ? 'Isi alamat server cucian ini. Tanya pemasang kalau belum tahu.'
                      : 'Kode diberikan oleh pemilik cucian, mis. kembar99.',
                  style: const TextStyle(color: Warna.redup, fontSize: 13.5),
                ),
                const SizedBox(height: 14),
                TextField(
                  key: ValueKey(_pakaiAlamat),
                  controller: _isian,
                  autofocus: true,
                  autocorrect: false,
                  enableSuggestions: false,
                  keyboardType: _pakaiAlamat ? TextInputType.url : TextInputType.visiblePassword,
                  textInputAction: TextInputAction.go,
                  onSubmitted: (_) => _simpan(),
                  decoration: InputDecoration(
                    hintText: _pakaiAlamat ? 'namacucian.rapiin.id' : 'kode cucian',
                    prefixIcon: Icon(_pakaiAlamat ? Icons.dns_outlined : Icons.storefront_outlined),
                  ),
                ),
                if (_pakaiAlamat) ...[
                  const SizedBox(height: 10),
                  const Text(
                    'Boleh tanpa https:// . Untuk server di dalam toko, isi '
                    'alamat IP-nya, mis. 192.168.1.5:8000',
                    style: TextStyle(color: Warna.redup, fontSize: 12.5),
                  ),
                ],
                if (_galat != null) ...[
                  const SizedBox(height: 12),
                  Text(_galat!, style: const TextStyle(color: Warna.aqua, fontSize: 13.5, fontWeight: FontWeight.w700)),
                ],
                const SizedBox(height: 22),
                FilledButton(
                  onPressed: _menyimpan ? null : _simpan,
                  child: _menyimpan
                      ? const SizedBox(
                          height: 22,
                          width: 22,
                          child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                        )
                      : const Text('Lanjut'),
                ),
                const SizedBox(height: 8),
                TextButton(
                  onPressed: _menyimpan ? null : _gantiCara,
                  child: Text(
                    _pakaiAlamat ? 'Pakai kode cucian' : 'Punya alamat server? Isi alamatnya',
                    style: const TextStyle(color: Warna.redup),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
