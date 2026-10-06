# Nawasara PDAM

Cek tagihan air PUDAM Tirta Katong Ponorogo untuk aplikasi warga. Nawasara
menjadi perantara: aplikasi tidak memanggil PUDAM langsung, kunci API tidak
pernah ada di ponsel, dan data pribadi pelanggan disaring sebelum keluar.

Bekerja bersama `nawasara/api` (JWT warga, pembatasan laju) dan
`nawasara/vault` (kunci API PUDAM). Polanya sama dengan `nawasara/pbb`.

## Status v0.1.0

| Fitur | |
|---|---|
| Cek tagihan per nomor sambungan | ✅ |
| Riwayat pemakaian 5 bulan | ✅ |
| Simpan nomor ("sambungan saya") | ✅ |
| Jejak pengecekan untuk statistik | ✅ |
| Uji koneksi dari panel Vault | ✅ |
| Halaman admin | ⏳ belum, sengaja |
| Bentuk jawaban untuk pelanggan yang sudah lunas | ⚠️ belum pernah terlihat, lihat di bawah |

## Setup

```bash
composer require nawasara/pdam
php artisan migrate
```

Lalu isi grup **`pdam`** di panel Vault:

| Kolom | Isi |
|---|---|
| Base URL | `https://pudamtirtakatong.com` |
| API Key | kunci dari PUDAM |

Tekan **Uji**. Pengujian memakai nomor yang pasti tidak ada (`0000000000`),
supaya tombol itu tidak menarik nama dan alamat pelanggan sungguhan ke layar
admin. Jawaban "tidak terdaftar" sudah membuktikan alamat benar dan kunci
diterima.

Tidak ada view, jadi tidak perlu baris `@source` di `app.css`. Tidak ada
permission atau menu.

## Endpoint

Semua di belakang JWT warga (`api.citizen`) dan `throttle:nawasara-citizen`.

| Metode | Jalur | Badan |
|---|---|---|
| POST | `/api/v1/pdam/check` | `connection_number` |
| GET | `/api/v1/pdam/connections` | |
| POST | `/api/v1/pdam/connections` | `connection_number`, `label` (opsional, maks 40) |
| DELETE | `/api/v1/pdam/connections/{id}` | |

`/check` memakai POST meski hanya membaca: nomor sambungan menunjuk ke rumah
seseorang, dan di URL ia akan tercatat di log akses dan riwayat proxy.

### Jawaban `/check`

```json
{
  "data": {
    "connection_number": "0506040212",
    "name_masked": "J•••••",
    "unpaid_count": 3,
    "bills": [
      { "month": 7, "year": 2026, "meter_start": 7658, "meter_end": 7666,
        "usage_m3": 8, "water_charge": 32000, "penalty": 3000,
        "other_charges": 0, "amount": 35000 }
    ],
    "total_due": 102000,
    "has_arrears": true,
    "history": [ { "month": 5, "year": 2026, "usage_m3": 8, "amount": 35000 } ]
  }
}
```

| Status | Arti |
|---|---|
| 200 | ketemu |
| 404 | nomor tidak terdaftar |
| 422 | nomor kosong atau bukan angka |
| 502 | PUDAM tak terjangkau atau menolak kunci kita: "coba lagi nanti" |

## Keputusan yang Mudah Dibatalkan Tanpa Tahu Alasannya

**Galat PUDAM datang dengan HTTP 200.** Hanya kunci API yang ditolak memakai
401. "Nomor tidak terdaftar" menjawab 200 dengan `status:false`. Yang dibaca
adalah `response_code`; memeriksa status HTTP saja akan membaca nomor yang
salah sebagai berhasil dengan isi kosong.

**Kode 99 dipakai untuk dua hal berbeda.** Nomor yang awalannya tidak cocok
dengan cabang mana pun dijawab `99 Gagal menentukan database cabang`. Itu nomor
yang salah, jadi dijawab 404. Kode 99 dengan pesan lain tetap dianggap galat
sistem (502). Menyamakan keduanya membuat warga yang salah ketik membaca
"layanan PDAM sedang gangguan".

**Nomor dibersihkan dari semua yang bukan angka.** PUDAM menjawab "tidak
terdaftar" untuk `05.06.040212` padahal nomornya benar. Warga yang menyalin
dari rekening bertitik tetap harus berhasil.

**Kunci API yang ditolak menjadi 502, bukan 404.** Itu kesalahan kita, bukan
warga. Menjawabnya "tidak ditemukan" membuat warga mengira nomornya salah.

**Alamat tidak pernah dikirim, nama hanya inisial.** PUDAM memulangkan nama dan
alamat utuh untuk nomor siapa pun. Nomor sambungan hanya 10 digit dan
berurutan, jadi tanpa penyaringan siapa pun dapat memanen daftar rumah beserta
penghuninya dengan menghitung maju. Resource ditulis sebagai daftar-izin:
field baru dari PUDAM tidak ikut terkirim diam-diam.

**`other_charges` adalah selisih, bukan rincian.** PUDAM punya `tagihannonair`
(biaya di luar air) yang belum pernah terlihat berisi. Yang dikirim hanya
`amount - water_charge - penalty`, supaya totalnya tetap jujur tanpa menebak
bentuk rinciannya.

**Pencatatan tidak pernah menghalangi jawaban.** `CheckRecorder` tidak pernah
melempar: bila basis data gagal, warga tetap menerima tagihannya. Teruji saat
basis data lokal mati.

**Jejak pengecekan bukan daftar pelanggan milik warga.** Warga lazim mengecek
tagihan rumah orang tuanya. Yang menyatakan "ini sambungan saya" adalah tabel
`connections`. Jejak dipakai untuk statistik dan untuk mengenali satu akun yang
mengecek ratusan nomor yang hampir semuanya `found=false`.

## Yang Belum Diketahui

⚠️ **Jawaban untuk pelanggan yang sudah lunas belum pernah terlihat.** Belum
jelas apakah datang sebagai `00` dengan daftar tagihan kosong, atau dengan kode
tersendiri. Kode yang tidak dikenal sengaja dilempar dan dilaporkan (502),
bukan dijawab "tidak ditemukan", supaya muncul di log. Uji dengan nomor
pelanggan yang lunas sebelum dipakai luas.

## Roadmap

- Halaman admin untuk statistik pengecekan
- Rincian `tagihannonair` setelah bentuknya terlihat

## Author

**Pringgo J. Saputro** &lt;odyinggo@gmail.com&gt;

## License

MIT
