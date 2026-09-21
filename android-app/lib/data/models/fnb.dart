import 'json_util.dart';

/// Barang jualan makanan/minuman.
class Produk {
  const Produk({
    required this.id,
    required this.nama,
    required this.jenis,
    required this.harga,
    required this.stok,
    required this.aktif,
  });

  final int id;
  final String nama;

  /// 'minuman' | 'makanan' — dipakai penyaring di layar Menu.
  final String jenis;
  final int harga;

  /// Stok dicatat manual oleh kasir/owner, bukan dihitung otomatis.
  final int stok;
  final bool aktif;

  /// Yang boleh muncul di layar jual: aktif DAN masih ada stoknya.
  /// Padanan `fnbTersedia()` di kasir.js.
  bool get bisaDijual => aktif && stok > 0;

  factory Produk.fromJson(Map<String, dynamic> j) => Produk(
        id: asInt(j['id']),
        nama: asStr(j['name']),
        jenis: asStr(j['type'], 'minuman'),
        harga: asInt(j['price']),
        stok: asInt(j['stock']),
        aktif: asBool(j['is_active'], true),
      );
}

/// Satu struk penjualan F&B. Bisa berdiri sendiri (jualan di warung) atau
/// menempel pada transaksi cuci (dipesan sambil menunggu mobil).
class PenjualanFnb {
  const PenjualanFnb({
    required this.id,
    required this.idTransaksi,
    required this.caraBayar,
    required this.total,
    required this.tip,
    required this.tanggal,
    required this.dibuatPada,
    required this.dicatatOleh,
    required this.statusVoid,
    required this.items,
  });

  final int id;

  /// Terisi bila dipesan dari kasir cuci; null bila penjualan berdiri sendiri.
  final int? idTransaksi;
  final String caraBayar;
  final int total;
  final int tip;
  final String tanggal;
  final DateTime? dibuatPada;
  final String? dicatatOleh;
  final String statusVoid;
  final List<ItemFnb> items;

  bool get batal => statusVoid == 'batal';
  bool get menungguVoid => statusVoid == 'menunggu';
  bool get menempelCucian => idTransaksi != null;

  String get ringkasItem =>
      items.map((i) => '${i.namaProduk} x${i.qty}').join(', ');

  factory PenjualanFnb.fromJson(Map<String, dynamic> j) => PenjualanFnb(
        id: asInt(j['id']),
        idTransaksi:
            j['transaction_id'] == null ? null : asInt(j['transaction_id']),
        caraBayar: asStr(j['payment_method'], 'cash'),
        total: asInt(j['total']),
        tip: asInt(j['tip']),
        tanggal: asStr(j['date']),
        dibuatPada: asTanggal(j['created_at']),
        dicatatOleh: asStrNull(j['created_by']),
        statusVoid: asStr(j['void_status'], 'aktif'),
        items: asList(j['items'], ItemFnb.fromJson),
      );
}

/// Baris di dalam struk F&B. Nama & harga disalin saat penjualan dibuat,
/// jadi mengubah harga menu tidak mengacak riwayat penjualan kemarin.
class ItemFnb {
  const ItemFnb({
    required this.idProduk,
    required this.namaProduk,
    required this.harga,
    required this.qty,
    required this.subtotal,
  });

  final int idProduk;
  final String namaProduk;
  final int harga;
  final int qty;
  final int subtotal;

  factory ItemFnb.fromJson(Map<String, dynamic> j) => ItemFnb(
        idProduk: asInt(j['product_id']),
        namaProduk: asStr(j['product_name']),
        harga: asInt(j['price']),
        qty: asInt(j['qty']),
        subtotal: asInt(j['subtotal']),
      );
}

/// Pesanan F&B yang sudah dicatat tapi belum dibayar. Tidak memotong stok dan
/// tidak masuk rekap uang mana pun sampai benar-benar disimpan.
class DraftFnb {
  const DraftFnb({
    required this.id,
    required this.label,
    required this.items,
    required this.tip,
    required this.catatan,
    required this.dibuatPada,
    required this.dicatatOleh,
  });

  final int id;

  /// Penanda pemesan, mis. "Meja 2". Web kasir tidak pernah mengisinya —
  /// di sana draft dibedakan lewat jam pencatatan saja, jadi ini sering null.
  /// Layar memakai [judul] supaya keduanya tetap kebaca.
  final String? label;

  /// {product_id: qty} — tanpa harga.
  final Map<int, int> items;
  final int tip;
  final String? catatan;
  final DateTime? dibuatPada;
  final String? dicatatOleh;

  int get jumlahItem => items.values.fold(0, (a, b) => a + b);

  /// Nama yang ditampilkan di daftar draft. Kalau owner belum memberi label,
  /// jam pencatatan sudah cukup untuk membedakan beberapa draft yang
  /// mengantre — sama seperti perilaku web.
  String judul(String Function(DateTime) jam) =>
      label ?? (dibuatPada == null ? 'Draft' : 'Draft ${jam(dibuatPada!)}');

  factory DraftFnb.fromJson(Map<String, dynamic> j) => DraftFnb(
        id: asInt(j['id']),
        label: asStrNull(j['label']),
        items: _itemDraftFnb(j['items']),
        tip: asInt(j['tip']),
        catatan: asStrNull(j['note']),
        dibuatPada: asTanggal(j['created_at']),
        dicatatOleh: asStrNull(j['created_by']),
      );
}

/// Kolom `items` draft F&B bisa datang dua bentuk tergantung siapa yang
/// menulisnya: daftar `[{product_id, qty}]` (dari web) atau peta
/// `{"3": 2}` (bentuk lama). Keduanya diterima supaya draft yang dibuat di
/// web tetap bisa dilanjutkan dari tablet, dan sebaliknya.
Map<int, int> _itemDraftFnb(dynamic v) {
  final out = <int, int>{};
  if (v is List) {
    for (final e in v.whereType<Map>()) {
      final m = e.cast<String, dynamic>();
      final id = asInt(m['product_id']);
      if (id != 0) out[id] = asInt(m['qty']);
    }
  } else if (v is Map) {
    v.forEach((k, val) {
      final id = asInt(k);
      if (id != 0) out[id] = asInt(val);
    });
  }
  return out;
}
