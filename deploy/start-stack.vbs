' Hidupkan MySQL + Apache tanpa jendela.
' Dipanggil Task Scheduler saat Windows menyala (tugas: "OtinCarwash Stack").
'
' ====================== PELAJARAN MAHAL, JANGAN DIHAPUS ======================
' Versi lama berkas ini mengaku "aman dijalankan dobel: instance kedua gagal
' bind port lalu keluar sendiri". ITU SALAH, dan untuk MySQL berbahaya.
'
' Yang benar-benar terjadi 27 Juli 2026: tugas startup menghidupkan mysqld
' pukul 15:22 (berhasil). XAMPP Control Panel tidak bisa melihat proses milik
' SYSTEM, jadi tampil "stopped", lalu tombol Start ditekan pukul 15:25.
' Dua mysqld memakai folder data yang sama -> InnoDB deadlock (thread menunggu
' lock sampai 396 detik) -> kedua instance bunuh diri -> kasir mati total.
'
' mysqld TIDAK sekadar "gagal bind port". Ia membuka berkas data yang sama dan
' merusak keadaannya. Karena itu berkas ini sekarang MEMERIKSA DULU sebelum
' menjalankan apa pun.
' =============================================================================
'
' Catatan lain: path sempat salah tulis "xamppp" (tiga p) sehingga berkas ini
' tidak pernah menghidupkan apa pun. Jendelanya disembunyikan (argumen 0), jadi
' kegagalan tidak terlihat. Selalu uji manual setiap kali baris di bawah diubah.

Set shell = CreateObject("WScript.Shell")
Set wmi   = GetObject("winmgmts:\\.\root\cimv2")

' Benarkah sebuah program sudah jalan? Dipakai sebagai pengaman ganda-jalan.
Function SudahJalan(namaExe)
    Dim daftar
    Set daftar = wmi.ExecQuery("SELECT ProcessId FROM Win32_Process WHERE Name='" & namaExe & "'")
    SudahJalan = (daftar.Count > 0)
End Function

If Not SudahJalan("mysqld.exe") Then
    shell.Run """C:\xampp\mysql\bin\mysqld.exe"" --defaults-file=C:\xampp\mysql\bin\my.ini --standalone", 0, False
    ' MySQL perlu waktu memulihkan InnoDB sebelum Apache mulai melayani.
    WScript.Sleep 8000
End If

If Not SudahJalan("httpd.exe") Then
    shell.Run """C:\xampp\apache\bin\httpd.exe""", 0, False
End If
