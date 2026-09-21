import 'json_util.dart';
import 'pekerja.dart';

/// Rekap satu hari — isi `GET /api/reports/daily`.
///
/// SEMUA angka di sini dihitung server (BookkeepingService::dailyRecap).
/// App hanya menampilkan. Dua turunan yang boleh dihitung di sini —
/// [uangMasuk] dan [omzet] — sengaja disalin persis dari kasir.js supaya
/// tablet dan web tidak pernah menyebut angka 'omzet' yang berbeda.
class RekapHarian {
  const RekapHarian({
    required this.tanggal,
    required this.jumlahKendaraan,
    required this.totalCuci,
    required this.tip,
    required this.tf,
    required this.fnbTotal,
    required this.fnbCash,
    required this.fnbTf,
    required this.cashTotal,
    required this.upah,
    required this.pengeluaran,
    required this.daftarPengeluaran,
    required this.laba,
    required this.rincianBayar,
    required this.upahPekerja,
    required this.buku,
  });

  final String tanggal;
  final int jumlahKendaraan;

  /// Omzet cucian saja (sudah termasuk add-on, belum termasuk tip & F&B).
  final int totalCuci;

  /// Tip cuci + tip F&B jadi satu angka — keduanya di luar omzet
  /// masing-masing dan sama-sama masuk laba.
  final int tip;
  final int tf;
  final int fnbTotal;
  final int fnbCash;
  final int fnbTf;
  final int cashTotal;
  final int upah;
  final int pengeluaran;
  final List<Pengeluaran> daftarPengeluaran;

  /// Laba bersih menurut server: cuci + tip + F&B - upah - pengeluaran.
  final int laba;

  final List<RincianBayar> rincianBayar;
  final List<UpahPekerja> upahPekerja;
  final List<BukuKas> buku;

  /// Uang masuk hari itu: cucian + makanan/minuman + tip. Angka KOTOR,
  /// sebelum pengeluaran. Tip ikut karena memang uang yang diterima hari itu
  /// dan ikut dihitung di laba bersih.
  int get uangMasuk => totalCuci + fnbTotal + tip;

  /// Omzet yang DITAMPILKAN: uang masuk dikurangi pengeluaran. Satu arti untuk
  /// semua layar — Rekap, Dashboard, dan Pembukuan memakai angka yang sama,
  /// supaya 'omzet' tidak berarti dua hal berbeda tergantung layar mana.
  int get omzet => uangMasuk - pengeluaran;

  /// Laba khusus cucian, dipakai kartu Rekap: cuci + tip - upah.
  int get labaCuci => totalCuci + tip - upah;

  factory RekapHarian.fromJson(Map<String, dynamic> j) => RekapHarian(
        tanggal: asStr(j['date']),
        jumlahKendaraan: asInt(j['vehicles']),
        totalCuci: asInt(j['total']),
        tip: asInt(j['tip']),
        tf: asInt(j['tf']),
        fnbTotal: asInt(j['fnb_total']),
        fnbCash: asInt(j['fnb_cash']),
        fnbTf: asInt(j['fnb_tf']),
        cashTotal: asInt(j['cash_total']),
        upah: asInt(j['wages']),
        pengeluaran: asInt(j['expenses']),
        daftarPengeluaran: asList(j['expense_list'], Pengeluaran.fromJson),
        laba: asInt(j['profit']),
        rincianBayar: asList(j['by_payment'], RincianBayar.fromJson),
        upahPekerja: asList(j['worker_wages'], UpahPekerja.fromJson),
        buku: asList(j['books'], BukuKas.fromJson),
      );
}

/// Satu cara bayar (cash / tf) beserta pecahannya per jenis kendaraan.
/// 'Cash Rp 330.000' saja tidak menjawab pertanyaan yang muncul saat
/// menghitung uang laci — motor berapa, mobil berapa.
class RincianBayar {
  const RincianBayar({
    required this.metode,
    required this.jumlah,
    required this.total,
    required this.baris,
  });

  final String metode;
  final int jumlah;
  final int total;
  final List<BarisKategoriBayar> baris;

  String get label => metode == 'cash' ? 'Cash' : 'Transfer';

  factory RincianBayar.fromJson(Map<String, dynamic> j) => RincianBayar(
        metode: asStr(j['method'], 'cash'),
        jumlah: asInt(j['count']),
        total: asInt(j['total']),
        baris: asList(j['rows'], BarisKategoriBayar.fromJson),
      );
}

class BarisKategoriBayar {
  const BarisKategoriBayar({
    required this.kategori,
    required this.jumlah,
    required this.total,
  });

  final String kategori;
  final int jumlah;
  final int total;

  factory BarisKategoriBayar.fromJson(Map<String, dynamic> j) =>
      BarisKategoriBayar(
        kategori: asStr(j['category']),
        jumlah: asInt(j['count']),
        total: asInt(j['total']),
      );
}

class Pengeluaran {
  const Pengeluaran({
    required this.id,
    required this.keterangan,
    required this.jumlah,
    required this.tanggal,
    required this.dicatatOleh,
  });

  final int id;
  final String keterangan;
  final int jumlah;
  final String tanggal;
  final String? dicatatOleh;

  factory Pengeluaran.fromJson(Map<String, dynamic> j) => Pengeluaran(
        id: asInt(j['id']),
        keterangan: asStr(j['description']),
        jumlah: asInt(j['amount']),
        tanggal: asStr(j['date']),
        dicatatOleh: asStrNull(j['created_by']),
      );
}

/// Satu buku kas — satu periode setoran, biasanya satu shift kasir.
class BukuKas {
  const BukuKas({
    required this.id,
    required this.nomor,
    required this.label,
    required this.status,
    required this.jumlahKendaraan,
    required this.totalCuci,
    required this.tip,
    required this.fnbTotal,
    required this.saldoAwal,
    required this.sisaSaldo,
    required this.omzet,
    required this.setoran,
    required this.kasir,
    required this.diajukanOleh,
    required this.disetujuiOleh,
    required this.ditolakOleh,
    required this.alasanTolak,
  });

  final int id;
  final int nomor;
  final String label;

  /// 'open' | 'pending' | 'deposited' | 'rejected'
  final String status;
  final int jumlahKendaraan;
  final int totalCuci;
  final int tip;
  final int fnbTotal;

  /// Kas kecil yang dicatat manual saat buku dibuka. null = belum pernah
  /// diisi — sengaja dibedakan dari 0, supaya 'belum diisi' tidak terbaca
  /// 'laci kosong'.
  final int? saldoAwal;
  final int? sisaSaldo;

  final int omzet;

  /// Uang yang harus disetor. Buku yang masih terbuka memakai perkiraan yang
  /// dihitung hidup; buku yang sudah diajukan memakai angka yang DIBEKUKAN
  /// saat pengajuan, supaya koreksi data belakangan tidak diam-diam mengubah
  /// angka yang sudah disepakati.
  final int setoran;

  final List<String> kasir;
  final String? diajukanOleh;
  final String? disetujuiOleh;
  final String? ditolakOleh;
  final String? alasanTolak;

  bool get terbuka => status == 'open';
  bool get menunggu => status == 'pending';
  bool get sudahSetor => status == 'deposited';
  bool get ditolak => status == 'rejected';

  static const _label = {
    'open': 'Berjalan',
    'pending': 'Menunggu persetujuan',
    'deposited': 'Sudah disetor',
    'rejected': 'Ditolak',
  };
  String get labelStatus => _label[status] ?? status;

  factory BukuKas.fromJson(Map<String, dynamic> j) {
    final nomor = asInt(j['number']);
    return BukuKas(
      id: asInt(j['id']),
      nomor: nomor,
      label: asStr(j['label'], 'Buku $nomor'),
      status: asStr(j['status'], 'open'),
      jumlahKendaraan: asInt(j['vehicles']),
      totalCuci: asInt(j['wash_total']),
      tip: asInt(j['tip']),
      fnbTotal: asInt(j['fnb_total']),
      saldoAwal:
          j['opening_balance'] == null ? null : asInt(j['opening_balance']),
      sisaSaldo:
          j['remaining_balance'] == null ? null : asInt(j['remaining_balance']),
      omzet: asInt(j['omzet']),
      setoran: asInt(j['amount']),
      kasir: (j['cashiers'] is List)
          ? (j['cashiers'] as List).map(asStr).toList(growable: false)
          : const [],
      diajukanOleh: asStrNull(j['requested_by']),
      disetujuiOleh: asStrNull(j['approved_by']),
      ditolakOleh: asStrNull(j['rejected_by']),
      alasanTolak: asStrNull(j['reject_reason']),
    );
  }
}

/// Status jam operasional — isi `GET /api/shift`.
class StatusShift {
  const StatusShift({
    required this.aktif,
    required this.buka,
    required this.pesan,
    required this.jamSekarang,
    required this.tanpaShift,
    required this.shiftBerjalan,
    required this.shiftBerikut,
    required this.semuaShift,
  });

  /// Sakelar utama fitur penguncian.
  final bool aktif;

  /// Toko sedang buka. Fitur dimatikan, atau belum ada shift aktif sama
  /// sekali, dianggap BUKA — penguncian tanpa jadwal hanya akan mengurung
  /// kasir tanpa jalan keluar.
  final bool buka;

  /// Pesan yang tampil di layar terkunci, diatur owner.
  final String pesan;
  final String jamSekarang;

  /// Kunci menyala tapi tidak ada satu pun shift aktif = salah setel.
  /// Server memilih tidak mengunci; app memakai tanda ini untuk
  /// memperingatkan owner.
  final bool tanpaShift;

  final Shift? shiftBerjalan;
  final Shift? shiftBerikut;
  final List<Shift> semuaShift;

  factory StatusShift.fromJson(Map<String, dynamic> j) => StatusShift(
        aktif: asBool(j['enabled']),
        buka: asBool(j['is_open'], true),
        pesan: asStr(j['message']),
        jamSekarang: asStr(j['now']),
        tanpaShift: asBool(j['no_shift']),
        shiftBerjalan: j['current_shift'] == null
            ? null
            : Shift.fromJson(asMap(j['current_shift'])),
        shiftBerikut: j['next_shift'] == null
            ? null
            : Shift.fromJson(asMap(j['next_shift'])),
        semuaShift: asList(j['shifts'], Shift.fromJson),
      );
}

/// Satu blok jam kerja toko, mis. 'Pagi 07:00-14:00'.
class Shift {
  const Shift({
    required this.id,
    required this.nama,
    required this.mulai,
    required this.selesai,
    required this.aktif,
  });

  final int id;
  final String nama;

  /// 'HH:MM' — sudah dipotong dari 'HH:MM:SS' kalau server mengirim detik.
  final String mulai;
  final String selesai;
  final bool aktif;

  String get rentang => '$mulai-$selesai';

  factory Shift.fromJson(Map<String, dynamic> j) => Shift(
        id: asInt(j['id']),
        nama: asStr(j['name']),
        mulai: _jam(j['start_time']),
        selesai: _jam(j['end_time']),
        aktif: asBool(j['is_active'], true),
      );
}

/// MySQL TIME keluar sebagai '07:00:00'; layar cuma butuh '07:00'.
String _jam(dynamic v) {
  final s = asStr(v);
  return s.length >= 5 ? s.substring(0, 5) : s;
}
