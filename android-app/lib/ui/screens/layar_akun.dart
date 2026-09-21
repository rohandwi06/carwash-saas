import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme.dart';
import '../../data/models/json_util.dart';
import '../../state/providers.dart';
import '../kerangka.dart';
import '../widgets/kartu.dart';
import '../widgets/pemuat.dart';
import 'layar_kasir.dart';

final akunKasirProvider = FutureProvider<List<Map<String, dynamic>>>(
  (ref) => ref.watch(akunRepoProvider).kasir(),
);

/// Tab Akun di layar Pengaturan: akun kasir + akun owner sendiri.
/// Keduanya owner-only di server, jadi tab ini memang tidak pernah terlihat
/// oleh kasir — layar Pengaturan seluruhnya sudah owner-only.
class TabAkun extends ConsumerWidget {
  const TabAkun({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final akun = ref.watch(akunKasirProvider);

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
      children: [
        Kartu(
          judul: 'Akun kasir',
          aksi: TextButton.icon(
            onPressed: () => _formKasir(context, ref),
            icon: const Icon(Icons.add, size: 18),
            label: const Text('Tambah'),
          ),
          child: akun.when(
            loading: () => const Padding(
              padding: EdgeInsets.all(20),
              child: Center(child: CircularProgressIndicator()),
            ),
            error: (e, _) => KotakGagal(
              pesan: '$e',
              onCoba: () => ref.invalidate(akunKasirProvider),
            ),
            data: (list) => list.isEmpty
                ? const Kosong(pesan: 'Belum ada akun kasir.')
                : Column(
                    children: [
                      for (final u in list) _barisKasir(context, ref, u),
                    ],
                  ),
          ),
        ),
        const SizedBox(height: 12),
        const KartuAkunOwner(),
      ],
    );
  }

  Widget _barisKasir(
    BuildContext context,
    WidgetRef ref,
    Map<String, dynamic> u,
  ) {
    final id = asInt(u['id']);
    final nama = asStr(u['name']);

    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: const Icon(Icons.person_outline),
      title: Text(nama, style: Teks.label),
      subtitle: Text('@${asStr(u['username'])}', style: Teks.kecil),
      trailing: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          IconButton(
            icon: const Icon(Icons.key_outlined),
            tooltip: 'Ganti password',
            onPressed: () => _gantiPassword(context, ref, id, nama),
          ),
          IconButton(
            icon: const Icon(Icons.delete_outline, color: Warna.danger),
            onPressed: () async {
              final yakin = await konfirmasi(
                context,
                judul: 'Hapus akun $nama?',
                // Transaksi menyimpan NAMA pencatatnya, bukan id akunnya,
                // jadi riwayat tetap terbaca walau akunnya sudah tidak ada.
                pesan: 'Transaksi yang sudah ia catat tetap tersimpan.',
                tombolYa: 'Hapus',
              );
              if (!yakin) return;
              try {
                await ref.read(akunRepoProvider).hapusKasir(id);
                ref.invalidate(akunKasirProvider);
              } catch (e) {
                if (context.mounted) pesanGagal(context, e);
              }
            },
          ),
        ],
      ),
    );
  }

  Future<void> _formKasir(BuildContext context, WidgetRef ref) async {
    final nama = TextEditingController();
    final username = TextEditingController();
    final password = TextEditingController();

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Akun kasir baru', style: Teks.judul),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: nama,
              autofocus: true,
              textCapitalization: TextCapitalization.words,
              decoration: const InputDecoration(labelText: 'Nama'),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: username,
              autocorrect: false,
              decoration: const InputDecoration(labelText: 'Username'),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: password,
              decoration: const InputDecoration(labelText: 'Password'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          FilledButton(
            onPressed: () {
              if (nama.text.trim().isEmpty ||
                  username.text.trim().isEmpty ||
                  password.text.isEmpty) {
                return;
              }
              Navigator.pop(ctx, true);
            },
            child: const Text('Simpan'),
          ),
        ],
      ),
    );

    // Isi dibaca SEBELUM controller dibuang — membacanya setelah dispose
    // melempar, dan itu terjadi persis di jalur sukses.
    final n = nama.text.trim();
    final u = username.text.trim();
    final p = password.text;
    nama.dispose();
    username.dispose();
    password.dispose();
    if (ok != true) return;

    try {
      await ref
          .read(akunRepoProvider)
          .tambahKasir(nama: n, username: u, password: p);
      ref.invalidate(akunKasirProvider);
      if (context.mounted) pesanSukses(context, 'Akun dibuat');
    } catch (e) {
      if (context.mounted) pesanGagal(context, e);
    }
  }

  Future<void> _gantiPassword(
    BuildContext context,
    WidgetRef ref,
    int id,
    String nama,
  ) async {
    final password = TextEditingController();

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Password baru untuk $nama', style: Teks.judul),
        content: TextField(
          controller: password,
          autofocus: true,
          decoration: const InputDecoration(labelText: 'Password baru'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          FilledButton(
            onPressed: () {
              if (password.text.isEmpty) return;
              Navigator.pop(ctx, true);
            },
            child: const Text('Simpan'),
          ),
        ],
      ),
    );

    final p = password.text;
    password.dispose();
    if (ok != true) return;

    try {
      await ref.read(akunRepoProvider).ubahKasir(id, {'password': p});
      if (context.mounted) pesanSukses(context, 'Password diganti');
    } catch (e) {
      if (context.mounted) pesanGagal(context, e);
    }
  }
}

/// Ganti username/password owner sendiri. Password LAMA wajib — itulah yang
/// mencegah tablet yang ditinggal tidak terkunci dipakai mengambil alih akun
/// owner beserta seluruh angka pembukuan di belakangnya.
class KartuAkunOwner extends ConsumerStatefulWidget {
  const KartuAkunOwner({super.key});

  @override
  ConsumerState<KartuAkunOwner> createState() => _KartuAkunOwnerState();
}

class _KartuAkunOwnerState extends ConsumerState<KartuAkunOwner> {
  final _lama = TextEditingController();
  final _username = TextEditingController();
  final _baru = TextEditingController();
  bool _menyimpan = false;

  @override
  void dispose() {
    _lama.dispose();
    _username.dispose();
    _baru.dispose();
    super.dispose();
  }

  Future<void> _simpan() async {
    if (_lama.text.isEmpty) {
      pesanGagal(context, 'Password lama wajib diisi.');
      return;
    }
    if (_username.text.trim().isEmpty && _baru.text.isEmpty) {
      pesanGagal(context, 'Tidak ada yang diubah.');
      return;
    }

    setState(() => _menyimpan = true);
    try {
      await ref.read(akunRepoProvider).ubahAkunOwner(
            passwordLama: _lama.text,
            username:
                _username.text.trim().isEmpty ? null : _username.text.trim(),
            passwordBaru: _baru.text.isEmpty ? null : _baru.text,
          );
      if (!mounted) return;
      _lama.clear();
      _username.clear();
      _baru.clear();
      setState(() => _menyimpan = false);
      pesanSukses(context, 'Akun owner diperbarui. Login lagi setelah ini.');
    } catch (e) {
      if (!mounted) return;
      setState(() => _menyimpan = false);
      pesanGagal(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Kartu(
      judul: 'Akun owner',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Kosongkan bagian yang tidak ingin diubah. Password lama selalu '
            'wajib.',
            style: Teks.kecil,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _lama,
            obscureText: true,
            decoration: const InputDecoration(labelText: 'Password lama'),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _username,
            autocorrect: false,
            decoration: const InputDecoration(
              labelText: 'Username baru (opsional)',
            ),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _baru,
            obscureText: true,
            decoration: const InputDecoration(
              labelText: 'Password baru (opsional)',
            ),
          ),
          const SizedBox(height: 14),
          FilledButton(
            onPressed: _menyimpan ? null : _simpan,
            child: Text(_menyimpan ? 'Menyimpan...' : 'Simpan perubahan'),
          ),
        ],
      ),
    );
  }
}
