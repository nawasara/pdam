<?php

namespace Nawasara\Pdam\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk jawaban tagihan air untuk aplikasi warga.
 *
 * DAFTAR-IZIN, bukan `toArray()` lalu buang sebagian: hanya kunci yang disebut
 * di sini yang keluar. Bila PdamClient kelak memulangkan field baru, ia TIDAK
 * ikut terkirim diam-diam (AGENTS.md §10a).
 *
 * ⚠️ Yang sengaja TIDAK dikirim:
 *
 *   alamat     PUDAM memulangkannya untuk nomor siapa pun. Nomor sambungan
 *              hanya 10 digit dan berurutan, jadi alamat yang ikut terkirim
 *              berarti siapa pun dapat memanen daftar rumah beserta penghuninya
 *              hanya dengan menghitung maju.
 *   nama utuh  diringkas menjadi inisial, cukup untuk warga mengenali "ya, ini
 *              rumah saya", tidak cukup untuk membuka identitas orang lain.
 *   idtarif    kode golongan tarif internal PUDAM; tidak ada yang bisa warga
 *              lakukan dengannya.
 */
class PdamBillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $bills = $this->resource['bills'] ?? [];

        return [
            'connection_number' => $this->resource['connection_number'] ?? null,
            'name_masked' => self::maskName($this->resource['name'] ?? null),
            'unpaid_count' => $this->resource['unpaid_count'] ?? count($bills),

            'bills' => array_map(fn (array $b) => [
                'month' => $b['month'],
                'year' => $b['year'],
                'meter_start' => $b['meter_start'],
                'meter_end' => $b['meter_end'],
                'usage_m3' => $b['usage_m3'],
                'water_charge' => $b['water_charge'],
                'penalty' => $b['penalty'],
                // Biaya di luar air (`tagihannonair` di PUDAM). Bentuk aslinya
                // belum pernah terlihat berisi, jadi yang dikirim hanya
                // selisihnya: total yang sah dikurangi air dan denda.
                'other_charges' => max(0, $b['amount'] - $b['water_charge'] - $b['penalty']),
                'amount' => $b['amount'],
            ], $bills),

            'total_due' => $this->resource['total_due'] ?? 0,
            'has_arrears' => ($this->resource['total_due'] ?? 0) > 0,

            // Riwayat pemakaian lima bulan terakhir, termasuk yang sudah
            // dibayar. Inilah yang membuat warga bisa melihat "kok bulan ini
            // naik", tanpa harus datang ke kantor PUDAM.
            'history' => array_map(fn (array $h) => [
                'month' => $h['month'],
                'year' => $h['year'],
                'usage_m3' => $h['usage_m3'],
                'amount' => $h['amount'],
            ], $this->resource['history'] ?? []),
        ];
    }

    /**
     * "JAITUN" menjadi "J•••••". Nama kosong tetap null, bukan deretan titik.
     *
     * Publik dan statis supaya ujinya memanggil fungsi ini sendiri, bukan
     * salinannya; salinan tetap lulus meski aslinya berubah.
     */
    public static function maskName(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        return mb_substr($name, 0, 1).str_repeat('•', max(1, mb_strlen($name) - 1));
    }
}
