import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:wakelock_plus/wakelock_plus.dart';

import 'core/theme.dart';
import 'data/session.dart';
import 'state/providers.dart';
import 'ui/gerbang.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Nama bulan & hari dalam bahasa Indonesia untuk DateFormat. Harus selesai
  // SEBELUM layar pertama digambar, kalau tidak format tanggalnya melempar.
  await initializeDateFormatting('id_ID');

  // Tablet kasir dipakai berdiri di meja, mendatar. Dikunci supaya layar
  // tidak berputar tiap kali tablet disenggol saat sedang mengetik plat.
  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.landscapeLeft,
    DeviceOrientation.landscapeRight,
    DeviceOrientation.portraitUp,
  ]);

  // Layar tidak boleh mati sendiri di tengah antrean cucian. Kasir yang harus
  // membuka kunci tablet tiap kali mencatat mobil akan berhenti mencatat.
  await WakelockPlus.enable();

  final sesi = await SesiStore.buka();

  runApp(
    ProviderScope(
      overrides: [sesiStoreProvider.overrideWithValue(sesi)],
      child: const AplikasiOtin(),
    ),
  );
}

class AplikasiOtin extends StatelessWidget {
  const AplikasiOtin({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'OTIN CARWASH',
      debugShowCheckedModeBanner: false,
      theme: temaOtin(),
      locale: const Locale('id'),
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: const [Locale('id'), Locale('en')],
      // Teks TIDAK ikut membesar mengikuti pengaturan sistem. Tata letak kasir
      // rapat dan angkanya besar-besar; tablet yang disetel font 130% membuat
      // tombol total tertimpa dan kasir salah pencet.
      builder: (context, child) => MediaQuery.withClampedTextScaling(
        minScaleFactor: 1,
        maxScaleFactor: 1.15,
        child: child ?? const SizedBox.shrink(),
      ),
      home: const Gerbang(),
    );
  }
}
