import '../api_client.dart';
import '../models/fnb.dart';
import '../models/json_util.dart';
import '../models/katalog.dart';
import '../models/laporan.dart';
import '../models/pekerja.dart';
import '../models/transaksi.dart';

/// Lapisan repository: SATU-SATUNYA tempat layar boleh menyentuh server.
///
/// Layar tidak pernah memanggil [ApiClient] langsung. Aturannya kelihatan
/// berlebihan untuk app yang online-only seperti sekarang, tapi inilah yang
/// membuat mode offline bisa ditambahkan nanti tanpa membongkar satu pun
/// layar: cukup sisipkan cache/antrean lokal di dalam kelas-kelas di file ini,
/// dan seluruh app ikut mendapatkannya.

/// Kendaraan hasil pencarian. `skor` datang dari fuzzy search di server —
/// makin tinggi makin cocok, dan urutannya sudah diatur server.
class Kendaraan {
  const Kendaraan({required this.id, required this.nama, required this.kategori});

  final int id;
  final String nama;
  final String kategori;

  factory Kendaraan.fromJson(Map<String, dynamic> j) => Kendaraan(
        id: asInt(j['id']),
        nama: asStr(j['name']),
        kategori: asStr(j['category']),
      );
}

/// Hasil login dari server.
class HasilLogin {
  const HasilLogin({required this.token, required this.role, required this.nama});

  final String token;
  final String role;
  final String nama;

  factory HasilLogin.fromJson(Map<String, dynamic> j) => HasilLogin(
        token: asStr(j['token']),
        role: asStr(j['role'], 'kasir'),
        nama: asStr(j['name']),
      );
}

class AuthRepo {
  const AuthRepo(this._api);
  final ApiClient _api;

  Future<HasilLogin> login(String username, String password) async {
    final r = await _api.post(
      '/login',
      body: {'username': username, 'password': password},
    );
    return HasilLogin.fromJson(asMap(r));
  }

  /// Kegagalan sengaja ditelan: token yang dipakai mungkin sudah kedaluwarsa
  /// di server, dan kasir tetap harus bisa keluar dari akunnya.
  Future<void> logout() async {
    try {
      await _api.post('/logout');
    } catch (_) {}
  }

  Future<HasilLogin> saya() async {
    final r = await _api.get('/me');
    return HasilLogin.fromJson(asMap(r));
  }
}

class ShiftRepo {
  const ShiftRepo(this._api);
  final ApiClient _api;

  Future<StatusShift> status() async =>
      StatusShift.fromJson(asMap(await _api.get('/shift')));

  Future<StatusShift> setSakelar({
    required bool aktif,
    required String pesan,
  }) async =>
      StatusShift.fromJson(
        asMap(await _api.put('/shift', body: {'enabled': aktif, 'message': pesan})),
      );

  Future<void> tambah({
    required String nama,
    required String mulai,
    required String selesai,
    bool aktif = true,
  }) =>
      _api.post('/shifts', body: _badan(nama, mulai, selesai, aktif));

  Future<void> ubah(
    int id, {
    required String nama,
    required String mulai,
    required String selesai,
    required bool aktif,
  }) =>
      _api.patch('/shifts/$id', body: _badan(nama, mulai, selesai, aktif));

  Future<void> hapus(int id) => _api.delete('/shifts/$id');

  Map<String, dynamic> _badan(String n, String m, String s, bool a) => {
        'name': n,
        'start_time': m,
        'end_time': s,
        'is_active': a,
      };
}

class KatalogRepo {
  const KatalogRepo(this._api);
  final ApiClient _api;

  Future<Konfig> konfig() async =>
      Konfig.fromJson(asMap(await _api.get('/config')));

  Future<List<Kendaraan>> cariKendaraan(String q) async =>
      asList(await _api.get('/vehicles/search', query: {'q': q}), Kendaraan.fromJson);

  /// Dicatat supaya owner tahu kendaraan apa yang dicari kasir tapi belum ada
  /// di database. Kegagalannya tidak pernah mengganggu kasir — ini catatan
  /// untuk owner, bukan bagian dari transaksi.
  Future<void> catatCarianGagal(String q) async {
    try {
      await _api.post('/vehicles/failed-search', body: {'query': q});
    } catch (_) {}
  }

  // --- Katalog cuci (owner) ---
  Future<List<Map<String, dynamic>>> kategori() async =>
      _daftarMap(await _api.get('/wash-categories'));

  Future<void> tambahKategori(Map<String, dynamic> body) =>
      _api.post('/wash-categories', body: body);

  Future<void> ubahKategori(int id, Map<String, dynamic> body) =>
      _api.patch('/wash-categories/$id', body: body);

  Future<void> hapusKategori(int id) => _api.delete('/wash-categories/$id');

  Future<List<Map<String, dynamic>>> layanan() async =>
      _daftarMap(await _api.get('/wash-services'));

  Future<void> tambahLayanan(Map<String, dynamic> body) =>
      _api.post('/wash-services', body: body);

  Future<void> ubahLayanan(int id, Map<String, dynamic> body) =>
      _api.patch('/wash-services/$id', body: body);

  Future<void> hapusLayanan(int id) => _api.delete('/wash-services/$id');

  /// Harga satu sel (kategori x layanan). Server yang menyimpan; app tidak
  /// pernah menghitung turunannya.
  Future<void> setHarga({
    required String kategori,
    required String layanan,
    required int total,
  }) =>
      _api.put('/pricing', body: {
        'category': kategori,
        'service': layanan,
        'total': total,
      },);

  // --- Add-on ---
  Future<List<Addon>> addons() async =>
      asList(await _api.get('/addons'), Addon.fromJson);

  Future<void> tambahAddon(String nama, int harga) =>
      _api.post('/addons', body: {'name': nama, 'price': harga});

  Future<void> ubahAddon(int id, Map<String, dynamic> body) =>
      _api.patch('/addons/$id', body: body);

  Future<void> hapusAddon(int id) => _api.delete('/addons/$id');

  // --- Tarif upah ---
  /// Berbeda dengan `wage_rates` di `/api/config` yang sudah DIKELOMPOKKAN
  /// per kategori, endpoint ini mengirim baris mentah apa adanya
  /// (`WageRate::all()`): {id, category, service, amount, trainee_amount}.
  /// Layar Pengaturan memang butuh bentuk mentah itu untuk mengedit sel per
  /// sel, jadi sengaja tidak dikelompokkan di sini.
  Future<List<Map<String, dynamic>>> tarifUpah() async =>
      _daftarMap(await _api.get('/wage-rates'));

  Future<void> setTarifUpah({
    required String kategori,
    required String layanan,
    required int jumlah,
  }) =>
      _api.put('/wage-rates', body: {
        'category': kategori,
        'service': layanan,
        'amount': jumlah,
      },);

  // --- Upah karyawan training (nominal tetap per cucian) ---
  /// Ikut mengirim upah senior sebagai pembanding — tanpa angka itu owner
  /// tidak punya dasar untuk menilai nominal training yang ia isi.
  Future<List<Map<String, dynamic>>> upahTraining() async =>
      _daftarMap(await _api.get('/trainee-wage'));

  Future<void> setUpahTraining({
    required String kategori,
    required String layanan,
    required int jumlah,
  }) =>
      _api.put('/trainee-wage', body: {
        'category': kategori,
        'service': layanan,
        'amount': jumlah,
      },);
}

class TransaksiRepo {
  const TransaksiRepo(this._api);
  final ApiClient _api;

  Future<List<Transaksi>> hari(String tanggal) async => asList(
        await _api.get('/transactions', query: {'date': tanggal}),
        Transaksi.fromJson,
      );

  /// Menyimpan transaksi. Perhatikan: TIDAK ada field `total` di sini —
  /// server yang menghitungnya dari kategori + layanan + add-on, supaya
  /// tablet yang dioprek tidak bisa menetapkan harganya sendiri.
  Future<Transaksi> simpan({
    required String namaKendaraan,
    required String kategori,
    required String layanan,
    required String caraBayar,
    String? plat,
    int tip = 0,
    List<int> idPekerja = const [],
    List<int> idAddon = const [],
    Map<int, int> itemFnb = const {},
    int? idDraft,
  }) async {
    final r = await _api.post('/transactions', body: {
      'vehicle_name': namaKendaraan,
      'category': kategori,
      'service': layanan,
      'payment_method': caraBayar,
      'plate': plat,
      'tip': tip,
      'worker_ids': idPekerja,
      'addon_ids': idAddon,
      'fnb_items': itemFnb.entries
          .map((e) => {'product_id': e.key, 'qty': e.value})
          .toList(),
      // Draft ikut terhapus di server begitu jadi transaksi.
      if (idDraft != null) 'draft_id': idDraft,
    },);
    return Transaksi.fromJson(asMap(r));
  }

  /// Owner membatalkan langsung; kasir hanya MENGAJUKAN. Yang menentukan
  /// bukan app melainkan role di token — server yang memutuskan.
  Future<void> ajukanVoid(int id, String alasan) =>
      _api.post('/transactions/$id/void', body: {'reason': alasan});

  Future<List<Transaksi>> antreanVoid() async => asList(
        await _api.get('/transactions/void-requests'),
        Transaksi.fromJson,
      );

  Future<void> setujuiVoid(int id) =>
      _api.post('/transactions/$id/void/approve');

  Future<void> tolakVoid(int id, String alasan) =>
      _api.post('/transactions/$id/void/reject', body: {'reason': alasan});

  // --- Draft cucian ---
  Future<List<DraftCuci>> drafts() async =>
      asList(await _api.get('/drafts'), DraftCuci.fromJson);

  Future<void> simpanDraft(Map<String, dynamic> body) =>
      _api.post('/drafts', body: body);

  Future<void> ubahDraft(int id, Map<String, dynamic> body) =>
      _api.patch('/drafts/$id', body: body);

  Future<void> hapusDraft(int id) => _api.delete('/drafts/$id');
}

class FnbRepo {
  const FnbRepo(this._api);
  final ApiClient _api;

  /// [hanyaAktif] dipakai layar kasir (cuma yang boleh dijual); layar
  /// Pengaturan memanggil tanpa itu supaya menu nonaktif tetap bisa diurus.
  Future<List<Produk>> produk({bool hanyaAktif = false}) async => asList(
        await _api.get('/products', query: hanyaAktif ? {'active': 1} : null),
        Produk.fromJson,
      );

  Future<void> tambahProduk(Map<String, dynamic> body) =>
      _api.post('/products', body: body);

  Future<void> ubahProduk(int id, Map<String, dynamic> body) =>
      _api.patch('/products/$id', body: body);

  Future<void> hapusProduk(int id) => _api.delete('/products/$id');

  Future<List<PenjualanFnb>> penjualan(String tanggal) async => asList(
        await _api.get('/fnb-sales', query: {'date': tanggal}),
        PenjualanFnb.fromJson,
      );

  Future<PenjualanFnb> jual({
    required Map<int, int> keranjang,
    required String caraBayar,
    int tip = 0,
    int? idDraft,
  }) async {
    final r = await _api.post('/fnb-sales', body: {
      'items': keranjang.entries
          .map((e) => {'product_id': e.key, 'qty': e.value})
          .toList(),
      'payment_method': caraBayar,
      'tip': tip,
      if (idDraft != null) 'draft_id': idDraft,
    },);
    return PenjualanFnb.fromJson(asMap(r));
  }

  Future<void> ajukanVoid(int id, String alasan) =>
      _api.post('/fnb-sales/$id/void', body: {'reason': alasan});

  Future<List<PenjualanFnb>> antreanVoid() async => asList(
        await _api.get('/fnb-sales/void-requests'),
        PenjualanFnb.fromJson,
      );

  Future<void> setujuiVoid(int id) => _api.post('/fnb-sales/$id/void/approve');

  Future<void> tolakVoid(int id, String alasan) =>
      _api.post('/fnb-sales/$id/void/reject', body: {'reason': alasan});

  // --- Draft F&B ---
  Future<List<DraftFnb>> drafts() async =>
      asList(await _api.get('/fnb-drafts'), DraftFnb.fromJson);

  Future<void> simpanDraft(Map<int, int> items, {int tip = 0, String? label}) =>
      _api.post('/fnb-drafts', body: _badanDraft(items, tip, label));

  Future<void> ubahDraft(
    int id,
    Map<int, int> items, {
    int tip = 0,
    String? label,
  }) =>
      _api.patch('/fnb-drafts/$id', body: _badanDraft(items, tip, label));

  Future<void> hapusDraft(int id) => _api.delete('/fnb-drafts/$id');

  Map<String, dynamic> _badanDraft(Map<int, int> items, int tip, String? label) => {
        'items': items.entries
            .map((e) => {'product_id': e.key, 'qty': e.value})
            .toList(),
        'tip': tip,
        if (label != null && label.isNotEmpty) 'label': label,
      };
}

class PekerjaRepo {
  const PekerjaRepo(this._api);
  final ApiClient _api;

  Future<List<Pekerja>> semua() async =>
      asList(await _api.get('/workers'), Pekerja.fromJson);

  Future<void> tambah(Map<String, dynamic> body) =>
      _api.post('/workers', body: body);

  Future<void> ubah(int id, Map<String, dynamic> body) =>
      _api.patch('/workers/$id', body: body);

  Future<void> hapus(int id) => _api.delete('/workers/$id');

  Future<List<UpahPekerja>> upahRentang(String dari, String sampai) async =>
      asList(
        await _api.get('/reports/wages', query: {'from': dari, 'to': sampai}),
        UpahPekerja.fromJson,
      );

  // --- Deposit pekerja ke kas (owner) ---
  /// Memakai [ApiClient.getPenuh] karena server ikut mengirim `summary` —
  /// totalnya dihitung di sana, app tidak menjumlahkan ulang.
  Future<({List<DepositPekerja> daftar, int total})> deposit({
    String? dari,
    String? sampai,
    int? idPekerja,
  }) async {
    final r = await _api.getPenuh('/worker-deposits', query: {
      if (dari != null) 'from': dari,
      if (sampai != null) 'to': sampai,
      if (idPekerja != null) 'worker_id': idPekerja,
    },);
    return (
      daftar: asList(r['data'], DepositPekerja.fromJson),
      total: asInt(asMap(r['summary'])['total']),
    );
  }

  Future<void> tambahDeposit({
    required int idPekerja,
    required int jumlah,
    String? catatan,
    String? tanggal,
  }) =>
      _api.post('/worker-deposits', body: {
        'worker_id': idPekerja,
        'amount': jumlah,
        if (catatan != null) 'note': catatan,
        if (tanggal != null) 'date': tanggal,
      },);

  Future<void> hapusDeposit(int id) => _api.delete('/worker-deposits/$id');

  // --- Penyesuaian upah (owner) ---
  Future<List<PenyesuaianUpah>> penyesuaian({
    String? dari,
    String? sampai,
  }) async =>
      asList(
        await _api.get('/wage-adjustments', query: {
          if (dari != null) 'from': dari,
          if (sampai != null) 'to': sampai,
        },),
        PenyesuaianUpah.fromJson,
      );

  Future<void> tambahPenyesuaian({
    required int idPekerja,
    required String jenis,
    required int jumlah,
    required String tanggal,
    String? alasan,
  }) =>
      _api.post('/wage-adjustments', body: {
        'worker_id': idPekerja,
        'type': jenis,
        'amount': jumlah,
        'date': tanggal,
        if (alasan != null) 'reason': alasan,
      },);

  Future<void> hapusPenyesuaian(int id) => _api.delete('/wage-adjustments/$id');
}

class LaporanRepo {
  const LaporanRepo(this._api);
  final ApiClient _api;

  /// [idBuku] menyaring rekap ke satu buku kas saja — dipakai tab buku di
  /// layar Rekap.
  Future<RekapHarian> harian(String tanggal, {int? idBuku}) async =>
      RekapHarian.fromJson(
        asMap(await _api.get('/reports/daily', query: {
          'date': tanggal,
          if (idBuku != null) 'book': idBuku,
        },),),
      );

  Future<List<RekapHarian>> kalender(int tahun, int bulan) async => asList(
        await _api.get('/reports/calendar', query: {
          'year': tahun,
          'month': bulan,
        },),
        RekapHarian.fromJson,
      );

  Future<List<RekapHarian>> rentang(String dari, String sampai) async => asList(
        await _api.get('/reports/date-range', query: {
          'from': dari,
          'to': sampai,
        },),
        RekapHarian.fromJson,
      );

  /// Statistik Dashboard — owner-only di server, jadi kasir yang memaksa
  /// membukanya tetap ditolak 403 walau menunya dipaksa muncul.
  Future<Map<String, dynamic>> statistik({String periode = 'harian'}) async =>
      asMap(await _api.get('/reports/stats', query: {'period': periode}));

  // --- Pengeluaran ---
  Future<List<Pengeluaran>> pengeluaran({
    String? tanggal,
    String? dari,
    String? sampai,
  }) async =>
      asList(
        await _api.get('/expenses', query: {
          if (tanggal != null) 'date': tanggal,
          if (dari != null) 'from': dari,
          if (sampai != null) 'to': sampai,
        },),
        Pengeluaran.fromJson,
      );

  Future<void> tambahPengeluaran({
    required String keterangan,
    required int jumlah,
    String? tanggal,
  }) =>
      _api.post('/expenses', body: {
        'description': keterangan,
        'amount': jumlah,
        if (tanggal != null) 'date': tanggal,
      },);

  Future<void> ubahPengeluaran(int id, Map<String, dynamic> body) =>
      _api.patch('/expenses/$id', body: body);

  Future<void> hapusPengeluaran(int id) => _api.delete('/expenses/$id');

  // --- Buku kas ---
  Future<List<BukuKas>> bukuMenunggu() async =>
      asList(await _api.get('/cash-books/pending'), BukuKas.fromJson);

  Future<void> ajukanSetoran(int id) =>
      _api.post('/cash-books/$id/request-deposit');

  Future<void> setujuiSetoran(int id) => _api.post('/cash-books/$id/approve');

  Future<void> tolakSetoran(int id, String alasan) =>
      _api.post('/cash-books/$id/reject', body: {'reason': alasan});

  Future<void> setSaldoAwal(int id, int saldo) =>
      _api.put('/cash-books/$id/opening-balance', body: {
        'opening_balance': saldo,
      },);
}

/// Akun kasir & akun owner — keduanya owner-only di server.
class AkunRepo {
  const AkunRepo(this._api);
  final ApiClient _api;

  Future<List<Map<String, dynamic>>> kasir() async =>
      _daftarMap(await _api.get('/users'));

  Future<void> tambahKasir({
    required String nama,
    required String username,
    required String password,
  }) =>
      _api.post('/users', body: {
        'name': nama,
        'username': username,
        'password': password,
      },);

  Future<void> ubahKasir(int id, Map<String, dynamic> body) =>
      _api.patch('/users/$id', body: body);

  Future<void> hapusKasir(int id) => _api.delete('/users/$id');

  Future<Map<String, dynamic>> akunOwner() async =>
      asMap(await _api.get('/owner-account'));

  /// Password lama WAJIB — server menolak tanpa itu. Ini yang mencegah tablet
  /// yang ditinggal tidak terkunci dipakai mengambil alih akun owner.
  Future<void> ubahAkunOwner({
    required String passwordLama,
    String? username,
    String? passwordBaru,
  }) =>
      _api.put('/owner-account', body: {
        'current_password': passwordLama,
        if (username != null) 'username': username,
        if (passwordBaru != null) 'password': passwordBaru,
      },);
}

/// Gemini — dipakai HANYA saat kendaraan tidak ditemukan pencarian lokal.
/// Alurnya: tanya AI -> kasir KONFIRMASI -> tersimpan ke tabel vehicles ->
/// lain kali langsung ketemu di pencarian biasa, tanpa AI lagi.
class AiRepo {
  const AiRepo(this._api);
  final ApiClient _api;

  /// Field-nya `query`, BUKAN `name` — server memvalidasinya persis begitu.
  /// Jawabannya cuma USULAN: tidak ada yang tersimpan sampai kasir menekan
  /// konfirmasi dan [simpanKendaraan] dipanggil.
  Future<Map<String, dynamic>> tebakKendaraan(String kataCari) async =>
      asMap(await _api.post('/ai/classify-vehicle', body: {'query': kataCari}));

  Future<Kendaraan> simpanKendaraan({
    required String nama,
    required String kategori,
  }) async =>
      Kendaraan.fromJson(
        asMap(await _api.post('/ai/save-vehicle', body: {
          'name': nama,
          'category': kategori,
        },),),
      );
}

List<Map<String, dynamic>> _daftarMap(dynamic v) => v is List
    ? v.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList()
    : const [];
