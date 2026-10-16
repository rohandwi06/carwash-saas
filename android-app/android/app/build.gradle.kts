import java.io.FileInputStream
import java.util.Properties

// Kunci tanda tangan rilis (Play Store / APK). Isinya di android/key.properties
// yang TIDAK pernah masuk git (lihat android/.gitignore) — cara membuatnya ada
// di deploy/play-store/PANDUAN-PLAY-STORE.md di repo server. Tanpa berkas itu,
// build rilis jatuh ke kunci debug: cukup untuk mencoba, DITOLAK Play Store.
val kunciRilis = Properties().apply {
    val berkas = rootProject.file("key.properties")
    if (berkas.exists()) load(FileInputStream(berkas))
}

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "id.rapiin.kasir_carwash"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // ID aplikasi di Play Store. TIDAK BISA diganti setelah rilis pertama.
        applicationId = "id.rapiin.kasir_carwash"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (!kunciRilis.isEmpty) {
            create("rilis") {
                keyAlias = kunciRilis["keyAlias"] as String
                keyPassword = kunciRilis["keyPassword"] as String
                storeFile = file(kunciRilis["storeFile"] as String)
                storePassword = kunciRilis["storePassword"] as String
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (kunciRilis.isEmpty) {
                signingConfigs.getByName("debug")
            } else {
                signingConfigs.getByName("rilis")
            }
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
