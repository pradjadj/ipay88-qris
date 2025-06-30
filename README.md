# iPay88 QRIS Gateway

## Deskripsi
Plugin WooCommerce untuk menerima pembayaran via QRIS melalui iPay88. Menampilkan QR code langsung di halaman checkout tanpa redirect.

## Fitur
- Pembayaran QRIS seamless tanpa redirect
- Konfigurasi Merchant Code dan Merchant Key iPay88
- Pilihan environment sandbox dan production
- Pengaturan waktu kedaluwarsa transaksi
- Status order otomatis setelah pembayaran
- Logging debug untuk troubleshooting

## Instalasi
1. Upload folder plugin ke direktori `/wp-content/plugins/`
2. Aktifkan plugin melalui menu Plugins di WordPress
3. Konfigurasikan plugin di WooCommerce > Settings > Checkout > iPay88 QRIS

## Konfigurasi
- Aktifkan metode pembayaran
- Isi Merchant Code dan Merchant Key dari iPay88
- Pilih environment (sandbox/production)
- Atur waktu kedaluwarsa transaksi (menit)
- Pilih status order setelah pembayaran berhasil
- Aktifkan debug log jika perlu

## Cara Penggunaan
- Pada halaman checkout, pilih metode pembayaran "QRIS via iPay88"
- Klik "Place Order"
- Scan QR code yang muncul menggunakan aplikasi mobile banking atau e-wallet yang mendukung QRIS
- Tunggu konfirmasi pembayaran otomatis

## Dukungan
Untuk bantuan, kunjungi [https://sgnet.co.id](https://sgnet.co.id)

## Penulis
Pradja DJ - https://sgnet.co.id

## Catatan Perubahan
1.0
- Rilis awal dengan dukungan integrasi seamless.