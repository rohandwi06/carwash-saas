import 'fnb.dart';
import 'json_util.dart';

/// Satu transaksi cuci. Angka `total` SELALU datang dari server — app tidak
/// pernah menghitung dan tidak pernah mengirimkannya, supaya tablet yang
/// dioprek tidak bisa mengubah harga (lihat PricingService di backend).
class Transaksi {
  const Transaksi({
    required this.id,
    required this.noAntrian,
    required this.namaKendaraan,
    required this.kategori,
    required this.layanan,
    required this.caraBayar,
    required this.plat,
    required this.tip,
    required this.total,
    required this.tanggal,
    required this.dibuatPada,
    required this.dicatatOleh,
    required this.statusVoid,
    required this.alasanVoid,
    required this.pekerja,
    required this.addons,
    required this.penjualanFnb,
  });

  final int id;
  final int noAntrian;
  final String namaKendaraan;
  final String kategori;
  final String layanan;

  /// 'cash' | 'tf'
  final String caraBayar;
  final String? plat;
  final int tip;

  /// Cucian + add-on. TIDAK termasuk makanan/minuman yang menempel —
  /// itu ada di [penjualanFnb], lihat [totalSemua].
  final int total;
  final String tanggal;
  final DateTime? dibuatPada;
  final String? dicatatOleh;

  /// 'aktif' | 'menunggu' | 'batal' | 'ditolak' — dihitung server dari tiga
  /// kolom void, jadi app tidak perlu menebaknya sendiri.
  final String statusVoid;
  final String? alasanVoid;

  final List<PekerjaRingkas> pekerja;
  final List<AddonTersimpan> addons;
  final List<PenjualanFnb> penjualanFnb;

  bool get batal => statusVoid == 'batal';
  bool get menungguVoid => statusVoid == 'menunggu';

  /// Cucian + add-on + makanan/minuman yang menempel pada transaksi ini.
  /// Padanan `totalTrx()` di kasir.js — ini angka yang dicetak di struk.
  int get totalSemua =>
      total + penjualanFnb.fold(0, (t, s) => t + s.total);

  /// Semua item F&B dari semua penjualan yang menempel. Padanan `itemFnb()`.
  List<ItemFnb> get itemFnb =>
      penjualanFnb.expand((s) => s.items).toList(growable: false);

  factory Transaksi.fromJson(Map<String, dynamic> j) => Transaksi(
        id: asInt(j['id']),
        noAntrian: asInt(j['queue_no']),
        namaKendaraan: asStr(j['vehicle_name']),
        kategori: asStr(j['category']),
        layanan: asStr(j['service']),
        caraBayar: asStr(j['payment_method'], 'cash'),
        plat: asStrNull(j['plate']),
        tip: asInt(j['tip']),
        total: asInt(j['total']),
        tanggal: asStr(j['date']),
        dibuatPada: asTanggal(j['created_at']),
        dicatatOleh: asStrNull(j['created_by']),
        statusVoid: asStr(j['void_status'], 'aktif'),
        alasanVoid: asStrNull(j['void_reason']) ??
            asStrNull(j['void_request_reason']),
        pekerja: asList(j['workers'], PekerjaRingkas.fromJson),
        addons: asList(j['addons'], AddonTersimpan.fromJson),
        penjualanFnb: asList(j['fnb_sales'], PenjualanFnb.fromJson),
      );
}

/// Pekerja yang menempel di transaksi — hanya id, nama, dan bagian upahnya.
/// Data pribadi (NIK, alamat) sengaja tidak ikut; server memang tidak
/// mengirimkannya ke kasir.
class PekerjaRingkas {
  const PekerjaRingkas({
    required this.id,
    required this.nama,
    required this.bagianUpah,
  });

  final int id;
  final String nama;
  final int bagianUpah;

  factory PekerjaRingkas.fromJson(Map<String, dynamic> j) => PekerjaRingkas(
        id: asInt(j['id']),
        nama: asStr(j['name']),
        bagianUpah: asInt(asMap(j['pivot'])['wage_share']),
      );
}

/// Add-on SAAT transaksi dibuat. Nama & harganya disalin ke pivot supaya
/// riwayat lama tidak ikut berubah ketika owner mengubah harga add-on
/// belakangan — jadi baca dari pivot dulu, baru jatuh ke kolom aslinya.
class AddonTersimpan {
  const AddonTersimpan({required this.id, required this.nama, required this.harga});

  final int id;
  final String nama;
  final int harga;

  factory AddonTersimpan.fromJson(Map<String, dynamic> j) {
    final pivot = asMap(j['pivot']);
    return AddonTersimpan(
      id: asInt(j['id']),
      nama: asStr(pivot['name'] ?? j['name']),
      harga: asInt(pivot['price'] ?? j['price']),
    );
  }
}

/// Draft cucian: kendaraan sudah masuk, bayarnya belakangan.
/// Harga sengaja TIDAK disimpan di draft — baru dikunci server saat draft
/// benar-benar jadi transaksi, supaya kenaikan harga tidak bocor lewat draft
/// yang mengendap dari kemarin.
class DraftCuci {
  const DraftCuci({
    required this.id,
    required this.namaKendaraan,
    required this.kategori,
    required this.layanan,
    required this.plat,
    required this.catatan,
    required this.tip,
    required this.idPekerja,
    required this.idAddon,
    required this.itemFnb,
    required this.dibuatPada,
    required this.dicatatOleh,
  });

  final int id;
  final String namaKendaraan;
  final String kategori;
  final String layanan;
  final String? plat;
  final String? catatan;
  final int tip;
  final List<int> idPekerja;
  final List<int> idAddon;

  /// {product_id, qty} saja — tanpa harga, sama seperti kolom draft lain.
  final Map<int, int> itemFnb;
  final DateTime? dibuatPada;
  final String? dicatatOleh;

  int get jumlahFnb => itemFnb.values.fold(0, (a, b) => a + b);

  factory DraftCuci.fromJson(Map<String, dynamic> j) => DraftCuci(
        id: asInt(j['id']),
        namaKendaraan: asStr(j['vehicle_name']),
        kategori: asStr(j['category']),
        layanan: asStr(j['service'], 'reguler'),
        plat: asStrNull(j['plate']),
        catatan: asStrNull(j['note']),
        tip: asInt(j['tip']),
        idPekerja: _daftarInt(j['worker_ids']),
        idAddon: _daftarInt(j['addon_ids']),
        itemFnb: _itemDraft(j['fnb_items']),
        dibuatPada: asTanggal(j['created_at']),
        dicatatOleh: asStrNull(j['created_by']),
      );
}

List<int> _daftarInt(dynamic v) =>
    v is List ? v.map(asInt).toList(growable: false) : const [];

/// `[{product_id: 3, qty: 2}]` -> `{3: 2}`.
Map<int, int> _itemDraft(dynamic v) {
  if (v is! List) return {};
  final out = <int, int>{};
  for (final e in v.whereType<Map>()) {
    final m = e.cast<String, dynamic>();
    final id = asInt(m['product_id']);
    if (id != 0) out[id] = asInt(m['qty']);
  }
  return out;
}
