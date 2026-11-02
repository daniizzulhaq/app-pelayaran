# Pembaruan company profile HPO

Website tetap menggunakan HTML, CSS, JavaScript, dan PHP 8.2+. Unggah seluruh isi folder ini saat memperbarui hosting, termasuk `professional.css`, `company-pages.css`, `company-pages.js`, kedua halaman tambahan, dan file baru di folder `admin/`. Pertahankan folder `.hpo-private` yang sudah ada agar akun admin dan pengaturan perusahaan tetap tersimpan.

## Halaman publik

- `index.html`: empat layanan utama, jalur permintaan awak dan lamaran pelaut, informasi perusahaan, proses kerja sama, FAQ, serta formulir kontak.
- `company-profile.html`: profil perusahaan berbahasa Inggris/Indonesia, dengan tombol **Cetak / simpan PDF**. Pilih **Save as PDF** di dialog cetak. Logo dan kontak mengambil pengaturan perusahaan yang sama dengan halaman utama.
- `privacy.html`: penjelasan penggunaan data formulir, preferensi bahasa, dan layanan eksternal.

## Pesan masuk

Formulir mengirim pesan langsung ke `api/index.php?route=contact`. Pesan disimpan di `.hpo-private/enquiries.php` dan dapat dibaca di **Admin → Pesan masuk**. Balas melalui tautan email pengirim. Tombol hapus menghapus pesan secara permanen setelah konfirmasi. Periksa kotak masuk secara rutin dan hapus data yang sudah tidak diperlukan.

Formulir memiliki validasi server, persetujuan penggunaan data, honeypot, pembatasan lima pesan per alamat jaringan dalam 15 menit, dan pemeriksaan origin. Pesan tidak menerima lampiran CV; pelaut diarahkan ke email rekrutmen. Penyimpanan maksimal 1.000 pesan; jika penuh, pengunjung diarahkan menggunakan email. Jangan membuka file penyimpanan pribadi untuk akses publik. Gunakan HTTPS pada hosting.

### Notifikasi email opsional

Pesan **tetap tersimpan dan dapat dibuka admin** tanpa layanan email. Notifikasi email ke kontak bisnis/rekrutmen tidak aktif secara bawaan. Untuk mengaktifkannya:

1. Pastikan fungsi `mail()` PHP di hosting telah dikonfigurasi oleh penyedia hosting untuk pengiriman dari domain perusahaan. Konfigurasi SPF/DKIM sesuai petunjuk hosting jika diperlukan.
2. Atur environment variable server `HPO_CONTACT_MAIL=1` melalui pengaturan hosting.
3. Uji penerimaan email dari formulir setelah hosting aktif. Keberhasilan pengiriman formulir berarti pesan tersimpan di kotak masuk admin; itu bukan konfirmasi penerimaan email.

## Konten yang perlu disediakan perusahaan

Tambahkan izin/sertifikasi, profil manajemen, foto kantor/tim, logo mitra dengan izin, dan lowongan aktif hanya setelah data aslinya tersedia. Pembaruan ini tidak membuat nomor legalitas, angka pencapaian, testimoni, atau daftar klien. Foto yang digunakan berasal dari aset proyek yang sudah tersedia.

## Menjalankan lokal

Jalankan `start-website.cmd` dari folder induk seperti sebelumnya, atau jalankan `php -S 127.0.0.1:4173 -t .` dari folder ini. Formulir dan panel admin memerlukan PHP; membuka file HTML langsung atau menggunakan hosting statis tidak menjalankan backend. Jika backend tidak tersedia, formulir menampilkan pesan kegagalan dan tautan email, bukan konfirmasi terkirim.
