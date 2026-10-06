<?php

declare(strict_types=1);

namespace Nawasara\Pdam\Tests;

use Nawasara\Pdam\Exceptions\PdamException;
use Nawasara\Pdam\Services\PdamClient;
use PHPUnit\Framework\TestCase;

/**
 * Penafsiran jawaban PUDAM.
 *
 * Setiap badan jawaban di sini adalah jawaban SUNGGUHAN dari
 * pudamtirtakatong.com, diambil 6 Oktober 2026, bukan contoh karangan. Nama
 * dan alamat pelanggan diganti.
 *
 * Yang dijaga: galat PUDAM datang dengan HTTP 200, dan kode 99 dipakai untuk
 * dua hal yang berbeda. Salah menafsirkannya membuat warga yang salah ketik
 * membaca "layanan gangguan", atau sebaliknya.
 */
class ResponseInterpretationTest extends TestCase
{
    private function client(): PdamClient
    {
        return new PdamClient('https://contoh.invalid', 'kunci-uji');
    }

    private function sample(): array
    {
        return [
            'status' => true,
            'response_code' => '00',
            'response_message' => 'Inquiry berhasil',
            'pelanggan' => [
                'nosambungan' => '0506040212',
                'nama' => 'NAMA UJI',
                'alamat' => 'JL.UJI ',
                'idtarif' => 'RA',
                'jml_rek' => 3,
            ],
            'tagihan' => [
                ['bulan' => 7, 'tahun' => 2026, 'stan' => 7666, 'stan_lalu' => 7658, 'pakai' => 8, 'tagihan' => 32000, 'denda' => 3000, 'tagihannonair' => [], 'total' => 35000],
                ['bulan' => 8, 'tahun' => 2026, 'stan' => 7676, 'stan_lalu' => 7666, 'pakai' => 10, 'tagihan' => 32000, 'denda' => 3000, 'tagihannonair' => [], 'total' => 35000],
                ['bulan' => 9, 'tahun' => 2026, 'stan' => 7679, 'stan_lalu' => 7676, 'pakai' => 3, 'tagihan' => 32000, 'denda' => 0, 'tagihannonair' => [], 'total' => 32000],
            ],
            'total' => 102000,
            'histori' => [
                ['bulan' => 5, 'tahun' => 2026, 'pakai' => 8, 'total' => 35000],
                ['bulan' => 9, 'tahun' => 2026, 'pakai' => 3, 'total' => 32000],
            ],
        ];
    }

    public function test_jawaban_berhasil_terbaca_utuh(): void
    {
        $r = $this->client()->interpret($this->sample(), 200, '0506040212');

        $this->assertTrue($r['found']);
        $this->assertSame('0506040212', $r['connection_number']);
        $this->assertSame(3, $r['unpaid_count']);
        $this->assertSame(102000, $r['total_due']);
        $this->assertCount(3, $r['bills']);
        $this->assertCount(2, $r['history']);

        $bulanJuli = $r['bills'][0];
        $this->assertSame(7, $bulanJuli['month']);
        $this->assertSame(7658, $bulanJuli['meter_start']);
        $this->assertSame(7666, $bulanJuli['meter_end']);
        $this->assertSame(8, $bulanJuli['usage_m3']);
        $this->assertSame(32000, $bulanJuli['water_charge']);
        $this->assertSame(3000, $bulanJuli['penalty']);
        $this->assertSame(35000, $bulanJuli['amount']);
    }

    /** Alamat tidak pernah diteruskan, bahkan sampai ke lapisan dalam. */
    public function test_alamat_tidak_ikut_dibawa(): void
    {
        $r = $this->client()->interpret($this->sample(), 200, '0506040212');

        $this->assertArrayNotHasKey('address', $r);
        $this->assertArrayNotHasKey('alamat', $r);
        $this->assertStringNotContainsString('JL.UJI', json_encode($r));
    }

    /** Datang dengan HTTP 200, bukan 404. */
    public function test_kode_03_berarti_tidak_ditemukan(): void
    {
        $r = $this->client()->interpret([
            'status' => false,
            'response_code' => '03',
            'response_message' => 'Nosambungan tidak terdaftar',
        ], 200, '123');

        $this->assertFalse($r['found']);
    }

    /**
     * Kode 99 untuk awalan nomor yang tak cocok dengan cabang mana pun adalah
     * NOMOR SALAH, bukan gangguan. Jawaban untuk `9999999999`.
     */
    public function test_kode_99_cabang_berarti_tidak_ditemukan(): void
    {
        $r = $this->client()->interpret([
            'status' => false,
            'response_code' => '99',
            'response_message' => 'Gagal menentukan database cabang',
        ], 200, '9999999999');

        $this->assertFalse($r['found']);
    }

    /** Kode 99 dengan pesan lain tetap kegagalan hulu. */
    public function test_kode_99_lain_adalah_kegagalan_hulu(): void
    {
        $this->expectException(PdamException::class);

        $this->client()->interpret([
            'status' => false,
            'response_code' => '99',
            'response_message' => 'Koneksi database terputus',
        ], 200, '0506040212');
    }

    /** Kunci API ditolak bukan kesalahan warga, jadi tidak boleh jadi 404. */
    public function test_kunci_ditolak_adalah_kegagalan_bukan_tidak_ditemukan(): void
    {
        $this->expectException(PdamException::class);

        $this->client()->interpret([
            'status' => false,
            'response_code' => '98',
            'response_message' => 'API Key tidak valid',
        ], 401, '0506040212');
    }

    /** Halaman galat nginx dan sejenisnya: bukan JSON. */
    public function test_badan_bukan_json_adalah_kegagalan_hulu(): void
    {
        $this->expectException(PdamException::class);

        $this->client()->interpret(null, 502, '0506040212');
    }

    /**
     * Kode yang belum pernah dilihat dilempar, bukan dijawab "tidak
     * ditemukan". Jawaban untuk pelanggan yang sudah lunas belum pernah
     * terlihat; kalau ternyata memakai kode tersendiri, ia harus muncul di log.
     */
    public function test_kode_tak_dikenal_tidak_ditelan(): void
    {
        $this->expectException(PdamException::class);

        $this->client()->interpret([
            'status' => false,
            'response_code' => '05',
            'response_message' => 'Sesuatu yang baru',
        ], 200, '0506040212');
    }

    /** Lunas dengan kode 00 dan daftar kosong: total 0, tidak ada tunggakan. */
    public function test_lunas_dengan_daftar_kosong(): void
    {
        $r = $this->client()->interpret([
            'status' => true,
            'response_code' => '00',
            'pelanggan' => ['nosambungan' => '0506040212', 'nama' => 'NAMA UJI', 'jml_rek' => 0],
            'tagihan' => [],
            'total' => 0,
            'histori' => [],
        ], 200, '0506040212');

        $this->assertTrue($r['found']);
        $this->assertSame(0, $r['total_due']);
        $this->assertSame([], $r['bills']);
    }
}
