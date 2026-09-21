import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme.dart';
import '../../state/providers.dart';

/// Login username + password. Padanan `kirimLogin()` di kasir.js.
///
/// Owner dan kasir memakai layar yang sama; yang membedakan role adalah
/// jawaban server, bukan pilihan di layar ini — jadi tidak ada tombol
/// "masuk sebagai owner" yang bisa ditebak-tebak orang.
class LayarLogin extends ConsumerStatefulWidget {
  const LayarLogin({super.key});

  @override
  ConsumerState<LayarLogin> createState() => _LayarLoginState();
}

class _LayarLoginState extends ConsumerState<LayarLogin> {
  final _username = TextEditingController();
  final _password = TextEditingController();
  final _fokusPassword = FocusNode();
  String _error = '';
  bool _kirim = false;
  bool _lihatPassword = false;

  @override
  void dispose() {
    _username.dispose();
    _password.dispose();
    _fokusPassword.dispose();
    super.dispose();
  }

  Future<void> _masuk() async {
    final u = _username.text.trim();
    final p = _password.text;
    if (u.isEmpty || p.isEmpty) return;

    setState(() {
      _kirim = true;
      _error = '';
    });

    try {
      final hasil = await ref.read(authRepoProvider).login(u, p);
      await ref.read(sesiProvider.notifier).masuk(hasil);
      // Tidak memanggil Navigator: Gerbang memantau sesi dan berganti sendiri
      // begitu token tersimpan.
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = '$e';
        _kirim = false;
      });
      // Password dikosongkan tiap gagal — kalau tidak, kasir yang salah ketik
      // akan menekan tombol berkali-kali dengan isi yang sama persis, dan
      // server membatasi 10 percobaan per menit.
      _password.clear();
      _fokusPassword.requestFocus();
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
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text(
                  '💧',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 46),
                ),
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
                const SizedBox(height: 30),
                TextField(
                  controller: _username,
                  autofocus: true,
                  enabled: !_kirim,
                  textInputAction: TextInputAction.next,
                  autocorrect: false,
                  decoration: const InputDecoration(
                    hintText: 'Username',
                    prefixIcon: Icon(Icons.person_outline),
                  ),
                  onSubmitted: (_) => _fokusPassword.requestFocus(),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _password,
                  focusNode: _fokusPassword,
                  enabled: !_kirim,
                  obscureText: !_lihatPassword,
                  textInputAction: TextInputAction.go,
                  decoration: InputDecoration(
                    hintText: 'Password',
                    prefixIcon: const Icon(Icons.lock_outline),
                    suffixIcon: IconButton(
                      icon: Icon(
                        _lihatPassword
                            ? Icons.visibility_off_outlined
                            : Icons.visibility_outlined,
                      ),
                      tooltip: _lihatPassword
                          ? 'Sembunyikan password'
                          : 'Tampilkan password',
                      onPressed: () =>
                          setState(() => _lihatPassword = !_lihatPassword),
                    ),
                  ),
                  onSubmitted: (_) => _masuk(),
                ),
                if (_error.isNotEmpty) ...[
                  const SizedBox(height: 14),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Warna.dangerGelap,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: Warna.danger),
                    ),
                    child: Text(
                      _error,
                      style: const TextStyle(
                        color: Warna.tag,
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                ],
                const SizedBox(height: 22),
                FilledButton(
                  onPressed: _kirim ? null : _masuk,
                  child: _kirim
                      ? const SizedBox(
                          height: 22,
                          width: 22,
                          child: CircularProgressIndicator(
                            strokeWidth: 2.5,
                            color: Colors.white,
                          ),
                        )
                      : const Text('Masuk'),
                ),
                const SizedBox(height: 18),
                TextButton.icon(
                  onPressed: _kirim
                      ? null
                      : () => ref.read(sesiProvider.notifier).setServer(''),
                  icon: const Icon(Icons.dns_outlined, size: 18),
                  label: Text(
                    'Server: ${ref.watch(sesiProvider).baseUrl}',
                    style: const TextStyle(fontSize: 12),
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
