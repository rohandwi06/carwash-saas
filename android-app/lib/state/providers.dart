import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/format.dart';
import '../core/pembaruan.dart';
import '../data/api_client.dart';
import '../data/models/fnb.dart';
import '../data/models/katalog.dart';
import '../data/models/laporan.dart';
import '../data/models/pekerja.dart';
import '../data/models/transaksi.dart';
import '../data/repositories/repo.dart';
import '../data/session.dart';

/// Diganti di main() dengan instance sungguhan setelah SharedPreferences siap.
/// Sengaja melempar kalau lupa di-override — lebih baik gagal keras saat
/// pengembangan daripada diam-diam kehilangan sesi di tablet pelanggan.
final sesiStoreProvider = Provider<SesiStore>(
  (ref) => throw UnimplementedError('sesiStoreProvider belum di-override'),
);

class SesiNotifier extends Notifier<Sesi> {
  @override
  Sesi build() => ref.read(sesiStoreProvider).baca();

  SesiStore get _store => ref.read(sesiStoreProvider);

  Future<void> masuk(HasilLogin hasil) async {
    await _store.simpanLogin(hasil.token, hasil.role, hasil.nama);
    state = state.salin(token: hasil.token, role: hasil.role, nama: hasil.nama);
  }

  /// Logout yang disengaja kasir — token juga dicabut di server.
  Future<void> keluar() async {
    await ref.read(authRepoProvider).logout();
    await _paksaKeluar();
  }

  /// Sesi ditolak server (401). Tidak memanggil /logout: tokennya memang
  /// sudah tidak berlaku, dan memanggilnya cuma menambah satu permintaan
  /// gagal lagi saat koneksi sedang bermasalah.
  Future<void> sesiHabis() => _paksaKeluar();

  Future<void> _paksaKeluar() async {
    await _store.hapusLogin();
    state = const Sesi().salin(
      baseUrl: state.baseUrl,
      halamanTerakhir: state.halamanTerakhir,
    );
  }

  Future<void> setServer(String url) async {
    await _store.setBaseUrl(url);
    state = state.salin(baseUrl: ref.read(sesiStoreProvider).baca().baseUrl);
  }

  Future<void> ingatHalaman(String id) async {
    await _store.simpanHalaman(id);
    state = state.salin(halamanTerakhir: id);
  }
}

final sesiProvider = NotifierProvider<SesiNotifier, Sesi>(SesiNotifier.new);

/// Klien API dibangun ulang setiap token/alamat server berubah, sehingga
/// seluruh repository ikut memakai token baru tanpa perlu diberi tahu.
final apiProvider = Provider<ApiClient>((ref) {
  final sesi = ref.watch(sesiProvider);
  final klien = ApiClient(
    baseUrl: sesi.baseUrl,
    token: sesi.token,
    onSesiHabis: () => ref.read(sesiProvider.notifier).sesiHabis(),
  );
  ref.onDispose(klien.tutup);
  return klien;
});

final authRepoProvider = Provider((ref) => AuthRepo(ref.watch(apiProvider)));
final shiftRepoProvider = Provider((ref) => ShiftRepo(ref.watch(apiProvider)));
final katalogRepoProvider =
    Provider((ref) => KatalogRepo(ref.watch(apiProvider)));
final transaksiRepoProvider =
    Provider((ref) => TransaksiRepo(ref.watch(apiProvider)));
final fnbRepoProvider = Provider((ref) => FnbRepo(ref.watch(apiProvider)));
final pekerjaRepoProvider =
    Provider((ref) => PekerjaRepo(ref.watch(apiProvider)));
final laporanRepoProvider =
    Provider((ref) => LaporanRepo(ref.watch(apiProvider)));
final akunRepoProvider = Provider((ref) => AkunRepo(ref.watch(apiProvider)));
final aiRepoProvider = Provider((ref) => AiRepo(ref.watch(apiProvider)));

/* ------------------------------------------------------------------
   Data dari server.
   Semuanya FutureProvider supaya layar dapat loading & error otomatis
   lewat AsyncValue — tidak ada satu pun layar yang menulis `isLoading`
   sendiri. Untuk menyegarkan: ref.invalidate(providerNya).
   ------------------------------------------------------------------ */

final konfigProvider =
    FutureProvider<Konfig>((ref) => ref.watch(katalogRepoProvider).konfig());

final pekerjaProvider =
    FutureProvider<List<Pekerja>>((ref) => ref.watch(pekerjaRepoProvider).semua());

/// Menu yang boleh dijual kasir. Layar Pengaturan memakai
/// [semuaProdukProvider] supaya menu nonaktif tetap bisa diurus.
final produkProvider = FutureProvider<List<Produk>>(
  (ref) => ref.watch(fnbRepoProvider).produk(hanyaAktif: true),
);

final semuaProdukProvider =
    FutureProvider<List<Produk>>((ref) => ref.watch(fnbRepoProvider).produk());

final shiftProvider =
    FutureProvider<StatusShift>((ref) => ref.watch(shiftRepoProvider).status());

final draftCuciProvider =
    FutureProvider<List<DraftCuci>>((ref) => ref.watch(transaksiRepoProvider).drafts());

final draftFnbProvider =
    FutureProvider<List<DraftFnb>>((ref) => ref.watch(fnbRepoProvider).drafts());

/// Transaksi satu tanggal. `.family` supaya layar Pembukuan bisa membuka
/// beberapa tanggal tanpa saling menimpa cache.
final transaksiHariProvider = FutureProvider.family<List<Transaksi>, String>(
  (ref, tanggal) => ref.watch(transaksiRepoProvider).hari(tanggal),
);

final penjualanFnbProvider = FutureProvider.family<List<PenjualanFnb>, String>(
  (ref, tanggal) => ref.watch(fnbRepoProvider).penjualan(tanggal),
);

/// Rekap harian. Kunci gabungan tanggal + buku supaya pindah tab buku tidak
/// membuang cache tanggalnya.
typedef KunciRekap = ({String tanggal, int? idBuku});

final rekapProvider = FutureProvider.family<RekapHarian, KunciRekap>(
  (ref, k) =>
      ref.watch(laporanRepoProvider).harian(k.tanggal, idBuku: k.idBuku),
);

final rekapHariIniProvider = FutureProvider<RekapHarian>(
  (ref) => ref.watch(laporanRepoProvider).harian(hariIni()),
);

typedef KunciBulan = ({int tahun, int bulan});

final kalenderProvider = FutureProvider.family<List<RekapHarian>, KunciBulan>(
  (ref, k) => ref.watch(laporanRepoProvider).kalender(k.tahun, k.bulan),
);

/// Antrean persetujuan owner. Kasir tidak pernah membukanya — layarnya
/// disembunyikan, dan server menolaknya kalau dipaksa.
final antreanVoidProvider = FutureProvider<List<Transaksi>>(
  (ref) => ref.watch(transaksiRepoProvider).antreanVoid(),
);

final antreanVoidFnbProvider = FutureProvider<List<PenjualanFnb>>(
  (ref) => ref.watch(fnbRepoProvider).antreanVoid(),
);

final setoranMenungguProvider = FutureProvider<List<BukuKas>>(
  (ref) => ref.watch(laporanRepoProvider).bukuMenunggu(),
);

/// null = sudah versi terbaru, atau servernya belum punya endpoint ini.
/// Tidak pernah gagal — lihat [Pembaruan.cek].
final pembaruanProvider = FutureProvider<Pembaruan?>(
  (ref) => Pembaruan.cek(ref.watch(apiProvider)),
);

/// Menu berdasarkan id. Sengaja tidak memakai `firstOrNull` dari
/// package:collection supaya app tidak menambah satu ketergantungan hanya
/// untuk satu baris. null = menu sudah dihapus/dinonaktifkan sejak keranjang
/// diisi; pemanggil yang memutuskan mau diapakan.
Produk? cariProduk(List<Produk> produk, int id) {
  for (final p in produk) {
    if (p.id == id) return p;
  }
  return null;
}

/* ------------------------------------------------------------------
   Keranjang kasir — transaksi yang sedang disusun.
   ------------------------------------------------------------------ */

/// Isi layar konfirmasi sebelum disimpan. Immutable: tiap perubahan
/// menghasilkan objek baru, jadi Riverpod tahu persis kapan layar perlu
/// digambar ulang dan tidak ada state yang berubah diam-diam di tengah jalan.
class KeranjangKasir {
  const KeranjangKasir({
    this.namaKendaraan,
    this.kategori = '',
    this.layanan = 'reguler',
    this.caraBayar = 'cash',
    this.plat = '',
    this.tip = 0,
    this.pekerja = const {},
    this.addon = const {},
    this.fnb = const {},
    this.idDraft,
  });

  /// null = kasir memilih kategori manual tanpa menyebut nama kendaraan.
  /// Saat disimpan, label kategori dipakai sebagai gantinya.
  final String? namaKendaraan;
  final String kategori;
  final String layanan;
  final String caraBayar;
  final String plat;
  final int tip;
  final Set<int> pekerja;
  final Set<int> addon;

  /// {product_id: qty} — makanan/minuman yang dipesan bareng cucian ini.
  final Map<int, int> fnb;

  /// Draft yang sedang dilanjutkan; server menghapusnya begitu tersimpan.
  final int? idDraft;

  bool get lanjutanDraft => idDraft != null;

  KeranjangKasir salin({
    Object? namaKendaraan = _tak,
    String? kategori,
    String? layanan,
    String? caraBayar,
    String? plat,
    int? tip,
    Set<int>? pekerja,
    Set<int>? addon,
    Map<int, int>? fnb,
    Object? idDraft = _tak,
  }) =>
      KeranjangKasir(
        namaKendaraan: namaKendaraan == _tak
            ? this.namaKendaraan
            : namaKendaraan as String?,
        kategori: kategori ?? this.kategori,
        layanan: layanan ?? this.layanan,
        caraBayar: caraBayar ?? this.caraBayar,
        plat: plat ?? this.plat,
        tip: tip ?? this.tip,
        pekerja: pekerja ?? this.pekerja,
        addon: addon ?? this.addon,
        fnb: fnb ?? this.fnb,
        idDraft: idDraft == _tak ? this.idDraft : idDraft as int?,
      );

  /// Penanda "argumen tidak diisi", supaya `salin(namaKendaraan: null)` bisa
  /// benar-benar mengosongkan nama — kalau memakai null sebagai penanda,
  /// mengosongkan nilai jadi mustahil.
  static const _tak = Object();
}

class KasirNotifier extends Notifier<KeranjangKasir> {
  @override
  KeranjangKasir build() => const KeranjangKasir();

  /// Kendaraan dipilih dari hasil pencarian. Keranjang di-reset lebih dulu
  /// supaya sisa pilihan cucian sebelumnya (pekerja, add-on, F&B) tidak
  /// terbawa diam-diam ke mobil berikutnya.
  void pilihKendaraan({String? nama, required String kategori}) {
    state = KeranjangKasir(namaKendaraan: nama, kategori: kategori);
  }

  void setLayanan(String slug) => state = state.salin(layanan: slug);
  void setCaraBayar(String m) => state = state.salin(caraBayar: m);
  void setPlat(String p) => state = state.salin(plat: p);
  void setTip(int t) => state = state.salin(tip: t < 0 ? 0 : t);

  void togglePekerja(int id) => state = state.salin(
        pekerja: {...state.pekerja}.._balik(id),
      );

  void toggleAddon(int id) => state = state.salin(
        addon: {...state.addon}.._balik(id),
      );

  /// [delta] boleh negatif. Qty yang jatuh ke 0 dibuang dari peta, bukan
  /// disimpan sebagai 0 — supaya "tidak dipesan" dan "dipesan nol" tidak jadi
  /// dua keadaan berbeda yang harus diurus di mana-mana.
  void ubahQtyFnb(int idProduk, int delta) {
    final baru = {...state.fnb};
    final qty = (baru[idProduk] ?? 0) + delta;
    if (qty <= 0) {
      baru.remove(idProduk);
    } else {
      baru[idProduk] = qty;
    }
    state = state.salin(fnb: baru);
  }

  /// Memuat draft ke keranjang untuk dilanjutkan jadi transaksi.
  void muatDraft(DraftCuci d) {
    state = KeranjangKasir(
      namaKendaraan: d.namaKendaraan,
      kategori: d.kategori,
      layanan: d.layanan,
      plat: d.plat ?? '',
      tip: d.tip,
      pekerja: d.idPekerja.toSet(),
      addon: d.idAddon.toSet(),
      fnb: Map.of(d.itemFnb),
      idDraft: d.id,
    );
  }

  void bersihkan() => state = const KeranjangKasir();

  /// Perkiraan total untuk DITAMPILKAN saja. Angka resmi tetap dihitung
  /// server saat disimpan — kalau keduanya berbeda, yang benar server.
  int perkiraanTotal(Konfig cfg, List<Produk> produk) {
    final cuci = cfg.harga(state.kategori, state.layanan) ?? 0;
    final addon = cfg.addons
        .where((a) => state.addon.contains(a.id))
        .fold(0, (t, a) => t + a.harga);
    final fnb = state.fnb.entries.fold(0, (t, e) {
      final p = cariProduk(produk, e.key);
      return t + (p == null ? 0 : p.harga * e.value);
    });
    return cuci + addon + fnb;
  }
}

final kasirProvider =
    NotifierProvider<KasirNotifier, KeranjangKasir>(KasirNotifier.new);

extension on Set<int> {
  /// remove() mengembalikan true kalau id-nya memang ada; kalau tidak ada,
  /// berarti belum terpilih dan harus ditambahkan.
  void _balik(int id) {
    if (!remove(id)) add(id);
  }
}

/* ------------------------------------------------------------------
   Keranjang F&B berdiri sendiri (layar jual makanan/minuman).
   ------------------------------------------------------------------ */

class KeranjangFnb {
  const KeranjangFnb({
    this.items = const {},
    this.caraBayar = 'cash',
    this.tip = 0,
    this.idDraft,
  });

  final Map<int, int> items;
  final String caraBayar;
  final int tip;
  final int? idDraft;

  bool get kosong => items.isEmpty;
  int get jumlahItem => items.values.fold(0, (a, b) => a + b);

  int total(List<Produk> produk) => items.entries.fold(0, (t, e) {
        final p = cariProduk(produk, e.key);
        return t + (p == null ? 0 : p.harga * e.value);
      });
}

class FnbNotifier extends Notifier<KeranjangFnb> {
  @override
  KeranjangFnb build() => const KeranjangFnb();

  void ubahQty(int idProduk, int delta) {
    final baru = {...state.items};
    final qty = (baru[idProduk] ?? 0) + delta;
    if (qty <= 0) {
      baru.remove(idProduk);
    } else {
      baru[idProduk] = qty;
    }
    state = KeranjangFnb(
      items: baru,
      caraBayar: state.caraBayar,
      tip: state.tip,
      idDraft: state.idDraft,
    );
  }

  void setCaraBayar(String m) => state = KeranjangFnb(
        items: state.items,
        caraBayar: m,
        tip: state.tip,
        idDraft: state.idDraft,
      );

  void setTip(int t) => state = KeranjangFnb(
        items: state.items,
        caraBayar: state.caraBayar,
        tip: t < 0 ? 0 : t,
        idDraft: state.idDraft,
      );

  void muatDraft(DraftFnb d) => state = KeranjangFnb(
        items: Map.of(d.items),
        caraBayar: state.caraBayar,
        tip: d.tip,
        idDraft: d.id,
      );

  /// Lepas dari draft TANPA menghapus draftnya di server, sekaligus
  /// mengosongkan keranjang — supaya isinya tidak tidak sengaja tersimpan
  /// sebagai penjualan milik pesanan lain.
  void bersihkan() => state = const KeranjangFnb();
}

final keranjangFnbProvider =
    NotifierProvider<FnbNotifier, KeranjangFnb>(FnbNotifier.new);
