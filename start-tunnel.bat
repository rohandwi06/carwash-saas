@echo off
setlocal EnableExtensions
title OTIN CARWASH - Server + Tunnel

REM ===========================================================================
REM  OTIN CARWASH - menyalakan server kasir + tunnel internet.
REM
REM  Berkas ini SATU-SATUNYA yang perlu dijalankan. Ia mengurus MySQL, Apache,
REM  dan Cloudflare Tunnel sekaligus, lalu menampilkan alamat yang bisa dibuka
REM  dari HP mana pun.
REM
REM  Menggantikan start-otin.bat (mode LAN lama). Akses dari dalam toko tetap
REM  dapat lewat Apache di port 8080, jadi tidak ada yang hilang.
REM
REM  SHIELD - berkas ini menolak jalan dua kali, karena dua mysqld pada satu
REM  folder data membuat InnoDB gagal dan kasir mati total (pernah terjadi
REM  27 Juli 2026: tombol Start ditekan 4x, semua transaksi berhenti).
REM ===========================================================================

set "PROYEK=%~dp0"
set "PROYEK=%PROYEK:~0,-1%"
set "LOCK=%TEMP%\otin-startup.lock"

REM Jeda pakai ping, bukan "timeout". Perintah timeout butuh konsol dan
REM langsung gagal ("Input redirection is not supported") bila skrip ini
REM dijalankan tanpa jendela - misalnya lewat Task Scheduler atau shortcut
REM minimized. ping ke localhost selalu bisa dipakai.
set "JEDA=ping -n 3 127.0.0.1 >nul"

REM --- SHIELD 1: hanya satu salinan skrip penyalaan yang boleh jalan ---------
REM Handle 9 dipegang selama blok :utama berjalan. Salinan kedua gagal
REM membuka berkas yang sama.
REM
REM PENTING - kunci ini SENGAJA tidak mematikan. Kalau salinan sebelumnya mati
REM mendadak (laptop sleep, listrik padam, proses dibunuh), handle-nya bisa
REM tertinggal menggantung. Kunci yang kaku akan membuat SEMUA penyalaan
REM berikutnya menolak jalan - kasir tidak pernah hidup lagi, kegagalan yang
REM jauh lebih parah daripada dobel MySQL yang mau dicegah.
REM
REM Jadi bila kunci gagal diambil: tunggu sebentar, lalu jalan juga. Pengaman
REM sesungguhnya ada di SHIELD 2 & 3 (memeriksa port dan proses), yang tidak
REM bisa macet dan selalu mencerminkan keadaan nyata.
2>nul (
  9>"%LOCK%" call :utama
) || (
  echo.
  echo  [i] Penyalaan lain sedang berjalan - menunggu 20 detik...
  ping -n 21 127.0.0.1 >nul
  echo  [i] Melanjutkan; pemeriksaan port akan mencegah penyalaan ganda.
  echo.
  call :utama
)
exit /b 0


:utama
echo ==========================================
echo   OTIN CARWASH - menyalakan server...
echo ==========================================
echo.

REM --- 1. MySQL --------------------------------------------------------------
REM SHIELD 2: port 3306 yang dicek, bukan nama proses. Port adalah bukti
REM MySQL benar-benar melayani; nama proses bisa menipu (proses ada tapi
REM sedang gagal start, atau tidak terlihat karena milik SYSTEM).
echo [1/4] MySQL...
netstat -ano | findstr /R /C:":3306 .*LISTENING" >nul
if not errorlevel 1 (
    echo       sudah melayani.
    goto :apache
)

REM Kalau sudah dipasang sebagai Windows Service, pakai service - lebih rapi
REM dan Windows sendiri yang mencegah instance kedua.
sc query mysql >nul 2>&1
if not errorlevel 1 (
    echo       menyalakan lewat Windows Service...
    net start mysql >nul 2>&1
) else (
    echo       menyalakan langsung...
    start "" /min "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini" --standalone
)

REM Tunggu sampai port benar-benar terbuka, maksimal 30 detik.
set /a _n=0
:tunggu_mysql
%JEDA%
netstat -ano | findstr /R /C:":3306 .*LISTENING" >nul
if not errorlevel 1 goto :apache
set /a _n+=1
if %_n% lss 15 goto :tunggu_mysql
echo       [!] MySQL tidak kunjung siap setelah 30 detik.
echo           Cek C:\xampp\mysql\data\mysql_error.log
goto :selesai_gagal


:apache
REM --- 2. Apache -------------------------------------------------------------
echo [2/4] Apache...
netstat -ano | findstr /R /C:":8080 .*LISTENING" >nul
if not errorlevel 1 (
    echo       sudah melayani di port 8080.
    goto :tunnel
)

sc query Apache2.4 >nul 2>&1
if not errorlevel 1 (
    echo       menyalakan lewat Windows Service...
    net start Apache2.4 >nul 2>&1
) else (
    echo       menyalakan langsung...
    start "" /min "C:\xampp\apache\bin\httpd.exe"
)

set /a _n=0
:tunggu_apache
%JEDA%
netstat -ano | findstr /R /C:":8080 .*LISTENING" >nul
if not errorlevel 1 goto :tunnel
set /a _n+=1
if %_n% lss 10 goto :tunggu_apache
echo       [!] Apache tidak kunjung siap setelah 20 detik.
echo           Cek storage\logs\apache-error.log
goto :selesai_gagal


:tunnel
REM --- 3. Tunnel -------------------------------------------------------------
REM SHIELD 3: tunnel yang sudah jalan tidak dinyalakan ulang. Kalau tugas
REM terjadwal "OtinCarwash Tunnel" masih terpasang dan sudah menyalakannya,
REM berkas ini tidak akan membuat yang kedua.
echo [3/4] Cloudflare Tunnel...
tasklist /FI "IMAGENAME eq cloudflared.exe" 2>nul | find /I "cloudflared.exe" >nul
if not errorlevel 1 (
    echo       sudah jalan - tidak dinyalakan ulang.
    goto :tampilkan
)

REM Pekerjaan sebenarnya diserahkan ke PowerShell: di sanalah URL dibaca dari
REM log, disimpan ke berkas, dan gerbang keamanan diperiksa.
powershell -NoProfile -ExecutionPolicy Bypass -File ^
  "%PROYEK%\deploy\windows\scripts\jalankan-tunnel.ps1" -Abaikan -TanpaBatas
if errorlevel 1 (
    echo       [!] Tunnel gagal dinyalakan.
    echo           Aplikasi TETAP bisa dipakai di dalam toko lewat port 8080.
)


:tampilkan
REM --- 4. Alamat -------------------------------------------------------------
echo.
echo [4/4] Alamat aplikasi:
echo.
echo       Di laptop ini  : http://localhost:8080
REM Tanda kurung WAJIB di-escape (^) di dalam blok for, kalau tidak cmd
REM membacanya sebagai penutup blok dan gagal dengan
REM ": was unexpected at this time."
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do (
    for /f "tokens=* delims= " %%b in ("%%a") do echo       Dari HP ^(WiFi^) : http://%%b:8080
)
echo.
if exist "%PROYEK%\storage\logs\tunnel-url.txt" (
    echo       Dari internet:
    for /f "usebackq tokens=*" %%u in (`findstr /R /C:"https://.*trycloudflare.com" "%PROYEK%\storage\logs\tunnel-url.txt"`) do echo       %%u
    echo.
    echo       ^(alamat internet BERUBAH setiap laptop dinyalakan^)
)
echo.
echo ==========================================
echo   Server jalan. JANGAN tutup jendela ini -
echo   biarkan tetap terbuka supaya bisa dipantau.
echo ==========================================
echo.

REM Jendela SENGAJA dibiarkan hidup (tidak auto-exit) supaya statusnya
REM tetap terlihat di layar. Tutup manual (klik X) kalau memang perlu
REM mematikan; MySQL/Apache/tunnel tetap jalan sebagai service/proses
REM terpisah walau jendela ini ditutup.
:jaga
ping -n 60 127.0.0.1 >nul
goto :jaga


:selesai_gagal
echo.
echo Ada yang gagal dinyalakan. Baca pesan di atas.
pause
exit /b 1
