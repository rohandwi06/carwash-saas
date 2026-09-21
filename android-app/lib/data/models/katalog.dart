import 'json_util.dart';

/// Isi `GET /api/config` — jenis kendaraan, jenis layanan, add-on, dan tarif
/// upah. Ini satu-satunya sumber harga di app; app TIDAK PERNAH mengarang
/// atau menyimpan harga sendiri, persis seperti aturan di PricingService.
class Konfig {
  const Konfig({
    required this.kategori,
    required this.layanan,
    required this.addons,
    required this.tarifUpah,
  });

  /// slug -> kategori, mis. 'motor', 'kecil'. Urutannya ikut server
  /// (sort_order), jadi jangan di-sort ulang di layar.
  final Map<String, KategoriCuci> kategori;

  /// slug -> layanan, mis. 'reguler', 'poles'.
  final Map<String, LayananCuci> layanan;

  final List<Addon> addons;

  /// kategori -> layanan -> rupiah upah.
  final Map<String, Map<String, int>> tarifUpah;

  factory Konfig.fromJson(Map<String, dynamic> j) {
    final kat = asMap(j['categories']).map(
      (slug, v) => MapEntry(slug, KategoriCuci.fromJson(slug, asMap(v))),
    );
    final svc = asMap(j['services']).map(
      (slug, v) => MapEntry(slug, LayananCuci.fromJson(slug, asMap(v))),
    );
    final upah = asMap(j['wage_rates']).map(
      (kat, v) => MapEntry(
        kat,
        asMap(v).map((svc, n) => MapEntry(svc, asInt(n))),
      ),
    );
    return Konfig(
      kategori: kat,
      layanan: svc,
      addons: asList(j['addons'], Addon.fromJson),
      tarifUpah: upah,
    );
  }

  /// Harga TOTAL satu kategori x layanan. `null` berarti layanan itu memang
  /// tidak tersedia untuk kategori tersebut (mis. "poles" untuk motor) —
  /// bedakan dari harga 0, yang berarti gratis.
  int? harga(String kategoriSlug, String layananSlug) =>
      kategori[kategoriSlug]?.harga[layananSlug];

  /// Slug layanan yang tersedia untuk satu kategori, urut mengikuti urutan
  /// layanan di server — bukan urutan acak dari Map harga.
  List<String> layananTersedia(String kategoriSlug) {
    final k = kategori[kategoriSlug];
    if (k == null) return const [];
    return layanan.keys.where((id) => k.harga.containsKey(id)).toList();
  }
}

/// Jenis kendaraan. `shape` menentukan siluet mana yang digambar di layar.
class KategoriCuci {
  const KategoriCuci({
    required this.slug,
    required this.id,
    required this.label,
    required this.shape,
    required this.contoh,
    required this.hargaMulai,
    required this.harga,
  });

  final String slug;
  final int id;
  final String label;

  /// 'moto' | 'hatch' | 'mpv' | 'van'. Nilai di luar itu digambar sebagai
  /// 'hatch' — sama seperti `bentukKat()` di kasir.js.
  final String shape;

  /// Contoh kendaraan yang masuk kategori ini, diisi owner di Pengaturan.
  final String contoh;

  /// Harga termurah, dipakai sebagai harga pajangan ("mulai Rp ...").
  final int hargaMulai;

  /// layanan slug -> rupiah.
  final Map<String, int> harga;

  static const _bentukAda = {'moto', 'hatch', 'mpv', 'van'};
  String get bentuk => _bentukAda.contains(shape) ? shape : 'hatch';

  factory KategoriCuci.fromJson(String slug, Map<String, dynamic> j) =>
      KategoriCuci(
        slug: slug,
        id: asInt(j['id']),
        label: asStr(j['label'], slug),
        shape: asStr(j['shape'], 'hatch'),
        contoh: asStr(j['examples']),
        hargaMulai: asInt(j['price']),
        harga: asMap(j['prices']).map((k, v) => MapEntry(k, asInt(v))),
      );
}

class LayananCuci {
  const LayananCuci({
    required this.slug,
    required this.id,
    required this.label,
  });

  final String slug;
  final int id;
  final String label;

  factory LayananCuci.fromJson(String slug, Map<String, dynamic> j) =>
      LayananCuci(
        slug: slug,
        id: asInt(j['id']),
        label: asStr(j['label'], slug),
      );
}

/// Layanan tambahan (semir ban, parfum, dll).
class Addon {
  const Addon({
    required this.id,
    required this.nama,
    required this.harga,
    this.aktif = true,
  });

  final int id;
  final String nama;
  final int harga;
  final bool aktif;

  factory Addon.fromJson(Map<String, dynamic> j) => Addon(
        id: asInt(j['id']),
        nama: asStr(j['name']),
        harga: asInt(j['price']),
        aktif: asBool(j['is_active'], true),
      );
}
