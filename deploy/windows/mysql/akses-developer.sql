-- =============================================================================
-- OTIN CARWASH - akun MySQL untuk developer (maintenance dari jarak jauh)
--
-- Jalankan sebagai root:
--     C:\xampp\mysql\bin\mysql.exe -u root -p < deploy\windows\mysql\akses-developer.sql
--
-- PENTING - baca dulu:
--
-- 1. GANTI dua kata sandi di bawah sebelum dijalankan. Jangan pakai contoh ini.
--
-- 2. Perhatikan host akunnya '127.0.0.1', BUKAN '%'.
--    Ini disengaja. Cloudflare Tunnel menyambung dari mesin ini sendiri,
--    jadi bagi MySQL koneksi developer tetap terlihat datang dari localhost.
--    Memakai '%' berarti akun bisa dipakai dari mana saja begitu port
--    3306 bocor sedikit pun - tidak ada alasan mengambil risiko itu.
--
-- 3. Pintu masuknya tetap Cloudflare Zero Trust. Tanpa lolos Access Policy,
--    tahu password pun tidak bisa menyentuh MySQL.
-- =============================================================================

-- --- 1. Root wajib berpassword ---------------------------------------------
-- Bawaan XAMPP: root TANPA password. Itu tidak apa-apa selama komputernya
-- terkunci di dalam toko, tapi menjadi lubang besar begitu ada tunnel.
ALTER USER 'root'@'localhost' IDENTIFIED BY 'GANTI-PASSWORD-ROOT-YANG-PANJANG';

-- --- 2. Akun aplikasi (dipakai Laravel) ------------------------------------
-- Aplikasi tidak perlu hak administratif. Cukup baca-tulis data.
CREATE USER IF NOT EXISTS 'otin_app'@'127.0.0.1'
    IDENTIFIED BY 'GANTI-PASSWORD-APLIKASI-YANG-PANJANG';

GRANT SELECT, INSERT, UPDATE, DELETE ON otin_carwash.*
    TO 'otin_app'@'127.0.0.1';

-- Migrasi Laravel butuh ubah struktur tabel. Kalau Anda lebih suka ketat,
-- cabut dua hak ini setelah selesai migrasi, lalu berikan lagi saat update.
GRANT CREATE, ALTER, INDEX, DROP, REFERENCES ON otin_carwash.*
    TO 'otin_app'@'127.0.0.1';

-- --- 3. Akun developer (maintenance lewat tunnel) --------------------------
-- Hak penuh atas SATU database saja - tidak menyentuh mysql.user,
-- tidak bisa membuat akun baru, tidak bisa melihat database lain.
CREATE USER IF NOT EXISTS 'otin_dev'@'127.0.0.1'
    IDENTIFIED BY 'GANTI-PASSWORD-DEVELOPER-YANG-PANJANG';

GRANT ALL PRIVILEGES ON otin_carwash.* TO 'otin_dev'@'127.0.0.1';

-- Batasi beban: developer yang keliru menjalankan query berat tidak
-- sampai membuat kasir di toko ikut macet.
ALTER USER 'otin_dev'@'127.0.0.1'
    WITH MAX_USER_CONNECTIONS 5 MAX_QUERIES_PER_HOUR 20000;

-- --- 4. Bersihkan akun bawaan yang berbahaya -------------------------------
DELETE FROM mysql.user WHERE User = '';                        -- akun anonim
DROP USER IF EXISTS 'root'@'%';                                -- root dari mana saja
DROP DATABASE IF EXISTS test;                                  -- database contoh

FLUSH PRIVILEGES;

-- --- 5. Periksa hasilnya ---------------------------------------------------
SELECT User, Host FROM mysql.user ORDER BY User, Host;
