import 'json_util.dart';

/// Pekerja cucian.
///
/// Server mengirim dua bentuk berbeda tergantung siapa yang meminta: kasir
/// hanya dapat id + nama + status hadir, owner dapat biodata lengkapnya juga
/// (lihat WorkerController@index). Model ini menampung keduanya — kolom
/// biodata null berarti "tidak dikirim", bukan "kosong di database".
class Pekerja {
  const Pekerja({
    required this.id,
    required this.nama,
    required this.hadir,
    required this.training,
    this.nik,
    this.tempatLahir,
    this.tanggalLahir,
    this.umur,
    this.telepon,
    this.alamat,
  });

  final int id;
  final String nama;
  final bool hadir;

  /// Pekerja training dibayar nominal tetap per cucian, bukan bagi rata.
  final bool training;

  final String? nik;
  final String? tempatLahir;
  final String? tanggalLahir;

  /// Dihitung server dari tanggal lahir — sengaja tidak disimpan sebagai
  /// kolom, supaya tidak membusuk tiap kali orangnya berulang tahun.
  final int? umur;
  final String? telepon;
  final String? alamat;

  bool get punyaBiodata => nik != null || alamat != null || tanggalLahir != null;

  factory Pekerja.fromJson(Map<String, dynamic> j) => Pekerja(
        id: asInt(j['id']),
        nama: asStr(j['name']),
        hadir: asBool(j['is_present'], true),
        training: asBool(j['is_trainee']),
        nik: asStrNull(j['nik']),
        tempatLahir: asStrNull(j['birth_place']),
        tanggalLahir: asStrNull(j['birth_date']),
        umur: j['age'] == null ? null : asInt(j['age']),
        telepon: asStrNull(j['phone']),
        alamat: asStrNull(j['address']),
      );
}

/// Upah satu pekerja untuk satu hari (`worker_wages` di rekap harian, atau
/// `/api/reports/wages` untuk rentang tanggal).
class UpahPekerja {
  const UpahPekerja({
    required this.id,
    required this.nama,
    required this.jumlahKendaraan,
    required this.upahKotor,
    required this.potongan,
    required this.timpa,
    required this.upah,
    required this.rincian,
  });

  final int id;
  final String nama;
  final int jumlahKendaraan;

  /// Sebelum potongan/penimpaan — jumlah bagian upah dari tiap cucian.
  final int upahKotor;

  /// Potongan (hukuman) yang diberikan owner.
  final int potongan;

  /// Kalau owner menimpa angka upah hari itu, ini nilainya; null = tidak
  /// ditimpa. Dibedakan dari 0, yang berarti "ditimpa jadi nol rupiah".
  final int? timpa;

  /// Angka final yang diterima pekerja — sudah dihitung server. JANGAN
  /// dihitung ulang di app: aturan potongan/penimpaan hanya hidup di
  /// WageService, dan dua tempat berhitung berarti dua jawaban berbeda.
  final int upah;

  final List<BarisUpah> rincian;

  factory UpahPekerja.fromJson(Map<String, dynamic> j) => UpahPekerja(
        id: asInt(j['id']),
        nama: asStr(j['name']),
        jumlahKendaraan: asInt(j['vehicles']),
        upahKotor: asInt(j['gross_wage']),
        potongan: asInt(j['penalty']),
        timpa: j['override'] == null ? null : asInt(j['override']),
        upah: asInt(j['wage']),
        rincian: asList(j['breakdown'], BarisUpah.fromJson),
      );
}

/// Satu cucian di dalam rincian upah seorang pekerja — bentuknya ditentukan
/// WageService::barisTransaksi() di backend.
class BarisUpah {
  const BarisUpah({
    required this.idTransaksi,
    required this.noAntrian,
    required this.jam,
    required this.kendaraan,
    required this.plat,
    required this.kategori,
    required this.layanan,
    required this.caraBayar,
    required this.total,
    required this.upah,
  });

  final int idTransaksi;
  final int noAntrian;

  /// Sudah berbentuk "HH:MM" dari server, bukan timestamp.
  final String? jam;
  final String kendaraan;
  final String? plat;
  final String kategori;
  final String layanan;
  final String caraBayar;

  /// Total transaksinya, untuk konteks — bukan yang diterima pekerja.
  final int total;

  /// Bagian upah pekerja ini dari transaksi tersebut.
  final int upah;

  factory BarisUpah.fromJson(Map<String, dynamic> j) => BarisUpah(
        idTransaksi: asInt(j['transaction_id']),
        noAntrian: asInt(j['queue_no']),
        jam: asStrNull(j['time']),
        kendaraan: asStr(j['vehicle_name']),
        plat: asStrNull(j['plate']),
        kategori: asStr(j['category']),
        layanan: asStr(j['service']),
        caraBayar: asStr(j['payment_method'], 'cash'),
        total: asInt(j['total']),
        upah: asInt(j['wage']),
      );
}

/// Upah seorang pekerja pada SATU tanggal di dalam rekap rentang
/// (`/api/reports/wages`). Dipisah per hari karena penimpaan upah berlaku
/// untuk satu hari tertentu — menjumlahkan dulu lalu menyesuaikan akan
/// menimpa upah seluruh rentang.
class UpahHarian {
  const UpahHarian({
    required this.tanggal,
    required this.jumlahKendaraan,
    required this.upahKotor,
    required this.potongan,
    required this.timpa,
    required this.upah,
  });

  final String tanggal;
  final int jumlahKendaraan;
  final int upahKotor;
  final int potongan;
  final int? timpa;
  final int upah;

  factory UpahHarian.fromJson(Map<String, dynamic> j) => UpahHarian(
        tanggal: asStr(j['date']),
        jumlahKendaraan: asInt(j['vehicles']),
        upahKotor: asInt(j['gross_wage']),
        potongan: asInt(j['penalty']),
        timpa: j['override'] == null ? null : asInt(j['override']),
        upah: asInt(j['wage']),
      );
}

/// Setoran pekerja ke kas (khusus owner).
class DepositPekerja {
  const DepositPekerja({
    required this.id,
    required this.idPekerja,
    required this.namaPekerja,
    required this.jumlah,
    required this.catatan,
    required this.tanggal,
  });

  final int id;
  final int idPekerja;
  final String namaPekerja;
  final int jumlah;
  final String? catatan;
  final String tanggal;

  factory DepositPekerja.fromJson(Map<String, dynamic> j) => DepositPekerja(
        id: asInt(j['id']),
        idPekerja: asInt(j['worker_id']),
        namaPekerja: asStr(j['worker_name'] ?? asMap(j['worker'])['name']),
        jumlah: asInt(j['amount']),
        catatan: asStrNull(j['note'] ?? j['description']),
        tanggal: asStr(j['date']),
      );
}

/// Penyesuaian upah: potongan (hukuman) atau penimpaan angka upah.
class PenyesuaianUpah {
  const PenyesuaianUpah({
    required this.id,
    required this.idPekerja,
    required this.namaPekerja,
    required this.jenis,
    required this.jumlah,
    required this.alasan,
    required this.tanggal,
  });

  final int id;
  final int idPekerja;
  final String namaPekerja;

  /// 'potongan' | 'timpa'
  final String jenis;
  final int jumlah;
  final String? alasan;
  final String tanggal;

  factory PenyesuaianUpah.fromJson(Map<String, dynamic> j) => PenyesuaianUpah(
        id: asInt(j['id']),
        idPekerja: asInt(j['worker_id']),
        namaPekerja: asStr(j['worker_name'] ?? asMap(j['worker'])['name']),
        jenis: asStr(j['type'], 'potongan'),
        jumlah: asInt(j['amount']),
        alasan: asStrNull(j['reason']),
        tanggal: asStr(j['date']),
      );
}
