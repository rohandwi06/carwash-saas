@echo off
setlocal EnableExtensions
title OTIN CARWASH - berkas ini sudah tidak dipakai

REM ===========================================================================
REM  BERKAS INI SUDAH DIPENSIUNKAN (27 Juli 2026).
REM
REM  Penggantinya: start-tunnel.bat di folder yang sama.
REM
REM  Kenapa dipensiunkan:
REM
REM  1. Ia menyalakan MySQL sendiri. Bersama tugas startup / service, itu
REM     berarti DUA mysqld pada satu folder data - InnoDB gagal membuka
REM     ib_logfile0 dan kasir mati total. Persis yang terjadi 27 Juli 2026.
REM
REM  2. Ia menjalankan "artisan serve" di port 8000. Sekarang Apache sudah
REM     melayani aplikasi yang sama di port 8080, jauh lebih tahan banting.
REM     Akses dari dalam toko tetap dapat - tidak ada yang hilang.
REM
REM  3. Jendelanya wajib dibiarkan terbuka. Menutupnya = kasir mati.
REM     start-tunnel.bat tidak begitu: jendelanya boleh ditutup.
REM
REM  Isi aslinya masih tersimpan di riwayat git bila suatu saat diperlukan:
REM     git show dc1270a:start-otin.bat
REM ===========================================================================

echo.
echo  ============================================================
echo    Berkas ini sudah tidak dipakai.
echo.
echo    Pakai:  start-tunnel.bat
echo.
echo    Berkas itu menyalakan MySQL, Apache, dan tunnel sekaligus,
echo    dan aman dijalankan berkali-kali.
echo  ============================================================
echo.

choice /C YN /N /M " Buka start-tunnel.bat sekarang? [Y/N] "
if errorlevel 2 goto :keluar

start "" "%~dp0start-tunnel.bat"

:keluar
exit /b 0
