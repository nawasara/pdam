<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kredensial PUDAM Tirta Katong
    |--------------------------------------------------------------------------
    | Alamat dan kunci API disimpan di Vault (grup `pdam`), bukan di sini.
    | Nawasara memanggilnya sebagai perantara: tagihan tidak pernah disalin ke
    | basis data, setiap pengecekan menembus langsung ke PUDAM, karena tagihan
    | yang disalin akan basi tanpa memberi tanda.
    */
    'vault_group' => 'pdam',

    // Detik. Diuji 6 Oktober 2026 menjawab dalam 0,4 detik; batas ini menjaga
    // permintaan warga tidak menggantung bila PUDAM sedang lambat.
    'http_timeout' => (int) env('NAWASARA_PDAM_HTTP_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Sambungan yang disimpan warga
    |--------------------------------------------------------------------------
    | Kenyamanan semata: warga tidak perlu mengetik ulang nomornya tiap bulan.
    |
    | Batas ini menahan satu akun menyimpan ratusan nomor. Tiap penyimpanan
    | memanggil PUDAM satu kali, dan daftar yang panjang lebih mirip panen data
    | daripada rumah tangga yang mengurus tagihannya.
    |
    | Angkanya disebut di badan jawaban 422 supaya aplikasi dapat memberi tahu
    | warga, bukan hanya menampilkan galat.
    */
    'connections' => [
        'max_per_citizen' => (int) env('NAWASARA_PDAM_MAX_CONNECTIONS', 10),
    ],
];
