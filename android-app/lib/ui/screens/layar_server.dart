import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme.dart';
import '../../state/providers.dart';

/// Layar pertama di tablet yang baru dinyalakan: ke server mana app ini
/// menembak.
///
/// Tidak ada padanannya di web — di sana file JS-nya disajikan dari server
/// yang sama, jadi cukup path relatif `/api`. Di tablet yang DIJUAL ke cucian
/// lain, tiap unit menunjuk server berbeda, jadi alamatnya harus bisa diatur
/// tanpa membangun ulang APK.
class LayarServer extends ConsumerStatefulWidget {
  const LayarServer({super.key});

  @override
  ConsumerState<LayarServer> createState() => _LayarServerState();
}

class _LayarServerState extends ConsumerState<LayarServer> {
  final _alamat = TextEditingController();
  bool _menyimpan = false;

  @override
  void dispose() {
    _alamat.dispose();
    super.dispose();
  }

  Future<void> _simpan() async {
    final url = _alamat.text.trim();
    if (url.isEmpty) return;
    setState(() => _menyimpan = true);
    await ref.read(sesiProvider.notifier).setServer(url);
    // Tidak perlu pindah layar sendiri: Gerbang memantau sesi dan otomatis
    // berganti ke layar login begitu alamatnya terisi.
    if (mounted) setState(() => _menyimpan = false);
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
                const Text.rich(
                  TextSpan(
                    children: [
                      TextSpan(text: 'OTIN', style: TextStyle(color: Warna.tag)),
                      TextSpan(
                        text: ' CARWASH',
                        style: TextStyle(color: Colors.white),
                      ),
                    ],
                  ),
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    fontSize: 30,
                    fontWeight: FontWeight.w900,
                    letterSpacing: 1.5,
                  ),
                ),
                const SizedBox(height: 26),
                const Text(
                  'Alamat server',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 6),
                const Text(
                  'Isi alamat server cucian ini. Tanya pemasang kalau belum tahu.',
                  style: TextStyle(color: Warna.redup, fontSize: 13.5),
                ),
                const SizedBox(height: 14),
                TextField(
                  controller: _alamat,
                  autofocus: true,
                  keyboardType: TextInputType.url,
                  textInputAction: TextInputAction.go,
                  onSubmitted: (_) => _simpan(),
                  decoration: const InputDecoration(
                    hintText: 'otin-carwash.com',
                    prefixIcon: Icon(Icons.dns_outlined),
                  ),
                ),
                const SizedBox(height: 10),
                const Text(
                  'Boleh tanpa https:// . Untuk server di dalam toko, isi '
                  'alamat IP-nya, mis. 192.168.1.5:8000',
                  style: TextStyle(color: Warna.redup, fontSize: 12.5),
                ),
                const SizedBox(height: 22),
                FilledButton(
                  onPressed: _menyimpan ? null : _simpan,
                  child: _menyimpan
                      ? const SizedBox(
                          height: 22,
                          width: 22,
                          child: CircularProgressIndicator(
                            strokeWidth: 2.5,
                            color: Colors.white,
                          ),
                        )
                      : const Text('Simpan & lanjut'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
