/// Lewat jalur mana build ini sampai ke pengguna.
///
///   play  Google Play Store (BAWAAN). Pembaruan hanya lewat Play: aturan
///         Play melarang aplikasi memperbarui dirinya dengan cara lain,
///         termasuk menawarkan tautan unduh APK. Bilah "versi baru" mati.
///   apk   APK untuk tablet yang dijual bersama aplikasinya, di luar Play.
///         Bilah pembaruan (lib/core/pembaruan.dart) menyala.
///
/// Dipilih saat membangun:
///   flutter build appbundle                          -> play
///   flutter build apk --dart-define=DISTRIBUSI=apk   -> apk
///
/// Bawaannya sengaja `play`: build yang lupa diberi tanda tidak boleh
/// ditolak Play karena ketahuan menawarkan APK.
const String kDistribusi = String.fromEnvironment('DISTRIBUSI', defaultValue: 'play');

bool get dariPlayStore => kDistribusi != 'apk';
