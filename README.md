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
| Pelanggan lunas (kode `08`) | ✅ |

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

`/check` juga dibatasi limiter khusus `throttle:nawasara-pdam-lookup` (audit
keamanan 15 Oktober 2026, temuan S13). Batas umum 60 per menit per akun
membiarkan satu akun gratis menebak nomor sambungan berurutan dan memanen
tunggakan serta riwayat pemakaian air satu desa dalam semalam. Warga
sungguhan mengecek beberapa nomor sebulan sekali, jadi batas kecil di bawah
tidak terasa olehnya.

| Env | Bawaan | |
|---|---|---|
| `NAWASARA_PDAM_LOOKUP_PER_HOUR` | `10` | per `sub` warga |
| `NAWASARA_PDAM_LOOKUP_PER_DAY` | `30` | per `sub` warga |
| `NAWASARA_PDAM_LOOKUP_IP_PER_HOUR` | `60` | per IP, lebih longgar karena NAT operator |
| `NAWASARA_PDAM_LOOKUP_IP_PER_DAY` | `300` | per IP |

`0` mematikan penghitung itu. Lewat batas, jawabannya `429` dengan `message`
berbahasa Indonesia dan header `Retry-After`.

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
| 200 | ketemu; pelanggan lunas juga 200 dengan `total_due: 0`, `bills: []`, `name_masked: null` |
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

**Pelanggan lunas memakai kode tersendiri, `08`, dengan `status:false`.**
Pesannya "Tagihan tidak ditemukan atau sudah terbayar", tanpa nama maupun
riwayat. Dijawab 200 dengan total nol, karena nomornya terdaftar (nomor yang
tidak terdaftar memakai `03`). Menjawabnya 404 membuat warga mengira nomornya
salah, padahal ia sudah membayar. Nama dan riwayat dikirim `null` dan kosong,
bukan dikarang.

Kode `08` ditemukan justru karena kode yang tidak dikenal dilempar dan
dilaporkan, bukan ditelan sebagai "tidak ditemukan". Pertahankan perilaku itu:
kode berikutnya yang belum dikenal akan muncul dengan cara yang sama.

## Roadmap

- Halaman admin untuk statistik pengecekan
- Rincian `tagihannonair` setelah bentuknya terlihat

## Author

**Pringgo J. Saputro** &lt;odyinggo@gmail.com&gt;

## License

MIT
