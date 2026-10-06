<?php

namespace Nawasara\Pdam\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Nawasara\Pdam\Exceptions\PdamException;
use Nawasara\Vault\Facades\Vault;

/**
 * Perantara ke API tagihan PUDAM Tirta Katong.
 *
 *   GET {base_url}/api/kominfo/tagihan/{nosambungan}
 *   X-API-KEY: <kunci>
 *
 * Bentuk jawaban diuji langsung 6 Oktober 2026. Tiga hal yang membentuk kelas
 * ini, semuanya tidak kelihatan dari dokumentasi:
 *
 * 1. GALAT DATANG DENGAN HTTP 200. Hanya kunci API yang ditolak yang memakai
 *    401. Nomor yang tidak terdaftar menjawab 200 dengan `status:false`, jadi
 *    memeriksa status HTTP saja akan membaca "tidak terdaftar" sebagai berhasil
 *    dengan isi kosong. Yang dibaca adalah `response_code`.
 *
 * 2. KODE 99 BERMAKNA GANDA. Untuk nomor yang awalannya tidak cocok dengan
 *    cabang mana pun, PUDAM menjawab `99 Gagal menentukan database cabang`.
 *    Itu nomor yang salah, tetapi memakai kode yang lazimnya berarti galat
 *    sistem. Menyamakan keduanya membuat warga yang salah ketik membaca
 *    "layanan PDAM sedang gangguan".
 *
 * 3. NOMOR BERTITIK DITOLAK. `05.06.040212` dijawab "tidak terdaftar". Karena
 *    itu nomor dibersihkan dari semua yang bukan angka sebelum dikirim.
 *
 * ⚠️ Jawaban untuk pelanggan yang sudah LUNAS belum pernah dilihat. Belum
 * diketahui apakah datang sebagai `00` dengan daftar tagihan kosong, atau
 * dengan kode tersendiri. Kode yang tidak dikenal sengaja dilempar sebagai
 * PdamException dan dilaporkan, supaya muncul di log dan segera ketahuan,
 * bukan diam-diam dijawab "tidak ditemukan".
 */
class PdamClient
{
    /** Berhasil. */
    public const CODE_OK = '00';

    /** Nomor sambungan tidak terdaftar. */
    public const CODE_NOT_FOUND = '03';

    /** Kunci API tidak valid atau tidak dikirim (datang dengan HTTP 401). */
    public const CODE_AUTH = '98';

    /** Galat umum, TERMASUK awalan nomor yang tidak cocok dengan cabang mana pun. */
    public const CODE_GENERAL = '99';

    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private int $timeout = 15,
    ) {}

    /**
     * Dibangun dari Vault; null bila belum dikonfigurasi.
     */
    public static function fromVault(): ?static
    {
        $group = (string) config('nawasara-pdam.vault_group', 'pdam');

        $baseUrl = trim((string) Vault::get($group, 'base_url'));
        $apiKey = trim((string) Vault::get($group, 'api_key'));

        if ($baseUrl === '' || $apiKey === '') {
            return null;
        }

        return new static(
            baseUrl: rtrim($baseUrl, '/'),
            apiKey: $apiKey,
            timeout: (int) config('nawasara-pdam.http_timeout', 15),
        );
    }

    /**
     * Buang semua yang bukan angka: titik, spasi, strip.
     *
     * PUDAM hanya menerima angka polos dan menjawab "tidak terdaftar" untuk
     * nomor bertitik, padahal nomornya benar.
     */
    public static function normalize(string $number): string
    {
        return preg_replace('/\D/', '', $number) ?? '';
    }

    /**
     * Tagihan untuk satu nomor sambungan.
     *
     * @return array{found: bool, connection_number: string, name?: ?string, unpaid_count?: int,
     *               bills?: array<int, array>, total_due?: int, history?: array<int, array>}
     *
     * @throws PdamException bila PUDAM tidak dapat memberi jawaban yang bisa dipercaya
     */
    public function findByConnection(string $number): array
    {
        $number = self::normalize($number);

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-API-KEY' => $this->apiKey,
                    'Accept' => 'application/json',
                ])
                ->get($this->baseUrl.'/api/kominfo/tagihan/'.rawurlencode($number));
        } catch (ConnectionException $e) {
            throw PdamException::unreachable($e);
        }

        return $this->interpret($response->json(), $response->status(), $number);
    }

    /**
     * Mengubah jawaban PUDAM menjadi bentuk Nawasara.
     *
     * Dipisah dari pemanggilan HTTP supaya seluruh aturan penafsiran dapat
     * diuji tanpa jaringan.
     *
     * @throws PdamException
     */
    public function interpret(mixed $data, int $httpStatus, string $number): array
    {
        if (! is_array($data) || ! array_key_exists('response_code', $data)) {
            // Bukan JSON, atau bukan bentuk yang dijanjikan PUDAM: halaman
            // galat nginx, gerbang yang menyela, dsb. Kegagalan hulu, bukan
            // "nomor tidak ditemukan".
            throw PdamException::unexpected("HTTP {$httpStatus}, badan tanpa response_code");
        }

        $code = (string) $data['response_code'];
        $message = (string) ($data['response_message'] ?? '');

        if ($code === self::CODE_AUTH || $httpStatus === 401) {
            throw PdamException::authFailed($message);
        }

        if ($code === self::CODE_NOT_FOUND) {
            return ['found' => false, 'connection_number' => $number];
        }

        // Kode 99 untuk awalan nomor yang tak cocok dengan cabang mana pun
        // adalah NOMOR SALAH. Dikenali dari pesannya karena kodenya dipakai
        // bersama dengan galat sistem; 99 dengan pesan lain tetap dianggap
        // kegagalan hulu.
        if ($code === self::CODE_GENERAL && stripos($message, 'cabang') !== false) {
            return ['found' => false, 'connection_number' => $number];
        }

        if ($code !== self::CODE_OK || ($data['status'] ?? null) !== true) {
            throw PdamException::unexpected("kode {$code}: {$message}");
        }

        $customer = is_array($data['pelanggan'] ?? null) ? $data['pelanggan'] : [];

        $bills = array_map(fn (array $b) => [
            'month' => (int) ($b['bulan'] ?? 0),
            'year' => (int) ($b['tahun'] ?? 0),
            'meter_start' => (int) ($b['stan_lalu'] ?? 0),
            'meter_end' => (int) ($b['stan'] ?? 0),
            'usage_m3' => (int) ($b['pakai'] ?? 0),
            'water_charge' => (int) ($b['tagihan'] ?? 0),
            'penalty' => (int) ($b['denda'] ?? 0),
            'amount' => (int) ($b['total'] ?? 0),
        ], array_values(array_filter((array) ($data['tagihan'] ?? []), 'is_array')));

        $history = array_map(fn (array $h) => [
            'month' => (int) ($h['bulan'] ?? 0),
            'year' => (int) ($h['tahun'] ?? 0),
            'usage_m3' => (int) ($h['pakai'] ?? 0),
            'amount' => (int) ($h['total'] ?? 0),
        ], array_values(array_filter((array) ($data['histori'] ?? []), 'is_array')));

        return [
            'found' => true,
            'connection_number' => (string) ($customer['nosambungan'] ?? $number),
            'name' => isset($customer['nama']) ? trim((string) $customer['nama']) : null,
            'unpaid_count' => (int) ($customer['jml_rek'] ?? count($bills)),
            'bills' => $bills,
            // Total dari PUDAM, bukan dijumlah ulang di sini: bila ada biaya
            // di luar air (`tagihannonair`), PUDAM yang tahu cara
            // menghitungnya.
            'total_due' => (int) ($data['total'] ?? array_sum(array_column($bills, 'amount'))),
            'history' => $history,
        ];
    }

    /**
     * Tombol "Uji" di panel Vault.
     *
     * Memakai nomor yang PASTI tidak ada, supaya pengujian tidak menarik data
     * pribadi pelanggan sungguhan ke layar admin. Jawaban "tidak terdaftar"
     * sudah membuktikan alamat benar dan kunci diterima; kunci yang salah
     * dijawab 98.
     *
     * Kunci `success`, bukan `ok`: panel Vault hanya membaca `success`.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        try {
            $this->findByConnection('0000000000');

            return ['success' => true, 'message' => 'Terhubung ke PUDAM Tirta Katong, kunci API diterima.'];
        } catch (PdamException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
