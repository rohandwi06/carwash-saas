' Jalankan Laravel scheduler tanpa jendela hitam muncul.
' Dipanggil Task Scheduler tiap menit (tugas: "OtinCarwash Scheduler").
'
' Scheduler inilah yang menjalankan backup harian 21:30. Tanpa tugas Task
' Scheduler yang memanggil berkas ini, TIDAK ADA backup yang berjalan.
' Pasang tugasnya lewat: deploy\windows\scripts\pasang-backup-otomatis.ps1
'
' Catatan: jendela disembunyikan (argumen 0), jadi kesalahan path TIDAK
' terlihat sama sekali - persis yang terjadi sebelumnya ketika path di sini
' salah tulis "xamppp". Selalu uji manual dulu setelah mengubah baris ini.
Set shell = CreateObject("WScript.Shell")
shell.Run """C:\xampp\php\php.exe"" ""C:\xampp\htdocs\otin-carwash\artisan"" schedule:run", 0, False
