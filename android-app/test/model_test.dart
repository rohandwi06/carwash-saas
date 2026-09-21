import 'package:flutter_test/flutter_test.dart';
import 'package:otin_carwash/data/models/json_util.dart';
import 'package:otin_carwash/data/models/katalog.dart';
import 'package:otin_carwash/data/models/laporan.dart';
import 'package:otin_carwash/data/models/transaksi.dart';

/// Fokus tes ini: JAWABAN SERVER YANG BENTUKNYA TIDAK SERAGAM.
///
/// SQLite di laptop mengembalikan angka sebagai int; MySQL lewat PDO kadang
/// mengembalikan DECIMAL/BIGINT sebagai string. Kalau model cuma menangani
/// salah satu, app jalan mulus saat dikembangkan lalu mati di tablet
/// pelanggan — kegagalan yang paling mahal untuk ditemukan.
void main() {
  group('pembaca JSON tahan bentuk', () {
    test('angka boleh datang sebagai string', () {
      expect(asInt('25000'), 25000);
      expect(asInt(25000), 25000);
      expect(asInt(25000.0), 25000);
      expect(asInt('25000.7'), 25001);
    });

    test('null jatuh ke nilai bawaan, bukan melempar', () {
      expect(asInt(null), 0);
      expect(asInt(null, 5), 5);
      expect(asStr(null), '');
      expect(asStrNull(null), isNull);
      expect(asStrNull('   '), isNull);
    });

    test('boolean boleh datang sebagai 1/0 atau "1"/"0"', () {
      expect(asBool(true), isTrue);
      expect(asBool(1), isTrue);
      expect(asBool('1'), isTrue);
      expect(asBool('true'), isTrue);
      expect(asBool(0), isFalse);
      expect(asBool('0'), isFalse);
      expect(asBool(null, true), isTrue);
    });

    test('daftar berisi baris rusak dilewati, tidak menjatuhkan layar', () {
      final hasil = asList<int>(
        [
          {'v': 1},
          'bukan objek',
          {'v': 2},
        ],
        (m) => asInt(m['v']),
      );
      expect(hasil, [1, 2]);
    });
  });

  group('Konfig', () {
    final cfg = Konfig.fromJson({
      'categories': {
        'motor': {
          'id': 1,
          'label': 'Motor',
          'shape': 'moto',
          'examples': 'Beat, Vario',
          'price': '10000',
          'prices': {'reguler': '10000'},
        },
        'kecil': {
          'id': 2,
          'label': 'Mobil Kecil',
          'shape': 'hatch',
          'price': 25000,
          'prices': {'reguler': 25000, 'poles': 60000},
        },
      },
      'services': {
        'reguler': {'id': 1, 'label': 'Cuci Reguler'},
        'poles': {'id': 2, 'label': 'Poles'},
      },
      'addons': [
        {'id': 3, 'name': 'Semir ban', 'price': '5000'},
      ],
      'wage_rates': {
        'kecil': {'reguler': '8000'},
      },
    });

    test('harga per sel terbaca walau server mengirim string', () {
      expect(cfg.harga('motor', 'reguler'), 10000);
      expect(cfg.harga('kecil', 'poles'), 60000);
    });

    test('layanan yang tidak tersedia mengembalikan null, BUKAN nol', () {
      // Bedanya penting: null = tidak dijual untuk kendaraan ini,
      // 0 = dijual tapi gratis. Kalau disamakan, motor akan terlihat
      // punya layanan poles seharga Rp 0.
      expect(cfg.harga('motor', 'poles'), isNull);
    });

    test('daftar layanan urut mengikuti urutan server, bukan abjad', () {
      expect(cfg.layananTersedia('kecil'), ['reguler', 'poles']);
      expect(cfg.layananTersedia('motor'), ['reguler']);
    });

    test('kategori tak dikenal tidak melempar', () {
      expect(cfg.harga('pesawat', 'reguler'), isNull);
      expect(cfg.layananTersedia('pesawat'), isEmpty);
    });

    test('bentuk siluet asing jatuh ke hatch', () {
      expect(cfg.kategori['motor']!.bentuk, 'moto');
      final aneh = KategoriCuci.fromJson('x', {'shape': 'kapal'});
      expect(aneh.bentuk, 'hatch');
    });

    test('add-on & tarif upah ikut terbaca', () {
      expect(cfg.addons.single.harga, 5000);
      expect(cfg.tarifUpah['kecil']!['reguler'], 8000);
    });
  });

  group('Transaksi', () {
    test('totalSemua menjumlahkan cucian + F&B yang menempel', () {
      final t = Transaksi.fromJson({
        'id': 1,
        'queue_no': 7,
        'vehicle_name': 'Avanza',
        'total': '30000',
        'tip': '5000',
        'payment_method': 'cash',
        'fnb_sales': [
          {
            'id': 9,
            'total': 12000,
            'items': [
              {'product_id': 2, 'product_name': 'Kopi', 'qty': 2, 'subtotal': 12000},
            ],
          },
        ],
      });

      expect(t.total, 30000);
      // Tip TIDAK ikut: di pembukuan ia berdiri di luar omzet cucian.
      expect(t.totalSemua, 42000);
      expect(t.itemFnb.single.namaProduk, 'Kopi');
    });

    test('add-on memakai nilai pivot (harga saat transaksi dibuat)', () {
      // Harga add-on yang diubah owner belakangan tidak boleh mengubah
      // riwayat; server menyalinnya ke pivot, dan model harus membaca
      // pivot lebih dulu.
      final t = Transaksi.fromJson({
        'addons': [
          {
            'id': 3,
            'name': 'Semir ban',
            'price': 9000,
            'pivot': {'name': 'Semir ban', 'price': 5000},
          },
        ],
      });
      expect(t.addons.single.harga, 5000);
    });

    test('status void dibaca dari server, bukan ditebak', () {
      expect(Transaksi.fromJson({'void_status': 'batal'}).batal, isTrue);
      expect(
        Transaksi.fromJson({'void_status': 'menunggu'}).menungguVoid,
        isTrue,
      );
      expect(Transaksi.fromJson({}).batal, isFalse);
    });
  });

  group('RekapHarian', () {
    final h = RekapHarian.fromJson({
      'date': '2026-07-11',
      'vehicles': 12,
      'total': 300000,
      'tip': 20000,
      'fnb_total': 50000,
      'expenses': 30000,
      'wages': 80000,
      'profit': 260000,
    });

    test('uangMasuk = cucian + F&B + tip (sebelum pengeluaran)', () {
      expect(h.uangMasuk, 370000);
    });

    test('omzet = uang masuk dikurangi pengeluaran', () {
      // Satu arti untuk semua layar. Kalau Rekap dan Dashboard memakai
      // rumus berbeda, "omzet" jadi dua angka yang tidak bisa dijelaskan.
      expect(h.omzet, 340000);
    });

    test('labaCuci hanya cucian + tip - upah', () {
      expect(h.labaCuci, 240000);
    });

    test('laba bersih diambil apa adanya dari server, tidak dihitung ulang',
        () {
      expect(h.laba, 260000);
    });
  });

  group('BukuKas', () {
    test('saldo awal null berarti belum diisi, bukan laci kosong', () {
      final b = BukuKas.fromJson({'id': 1, 'number': 2, 'status': 'open'});
      expect(b.saldoAwal, isNull);
      expect(b.label, 'Buku 2');
      expect(b.terbuka, isTrue);
      expect(b.labelStatus, 'Berjalan');
    });

    test('saldo awal nol tetap terbaca sebagai nol', () {
      final b = BukuKas.fromJson({'opening_balance': 0});
      expect(b.saldoAwal, 0);
    });
  });

  group('Shift', () {
    test('jam MySQL "07:00:00" dipendekkan jadi "07:00"', () {
      final s = Shift.fromJson({
        'id': 1,
        'name': 'Pagi',
        'start_time': '07:00:00',
        'end_time': '14:00:00',
      });
      expect(s.rentang, '07:00-14:00');
    });
  });

  group('DraftCuci', () {
    test('item F&B daftar objek jadi peta id->qty', () {
      final d = DraftCuci.fromJson({
        'id': 5,
        'vehicle_name': 'Beat',
        'worker_ids': [1, '2'],
        'fnb_items': [
          {'product_id': 3, 'qty': 2},
          {'product_id': '4', 'qty': '1'},
        ],
      });
      expect(d.idPekerja, [1, 2]);
      expect(d.itemFnb, {3: 2, 4: 1});
      expect(d.jumlahFnb, 3);
    });
  });
}
