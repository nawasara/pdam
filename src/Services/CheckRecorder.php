<?php

namespace Nawasara\Pdam\Services;

use Illuminate\Support\Facades\Log;
use Nawasara\Pdam\Models\PdamCheck;

/**
 * Mencatat setiap pengecekan yang sampai ke PUDAM.
 */
class CheckRecorder
{
    /**
     * ⚠️ TIDAK PERNAH melempar.
     *
     * Pengecekan adalah layanan untuk warga; pencatatan adalah kebutuhan
     * internal, dan yang kedua tidak boleh mengorbankan yang pertama. Bila
     * penulisan gagal, warga tetap menerima tagihannya dan kegagalannya hanya
     * masuk log. Pemanggil tidak perlu membungkusnya dengan try/catch.
     *
     * Dipanggil hanya untuk pengecekan yang dijawab PUDAM. Yang berakhir 422
     * (nomor kosong) atau 502 (PUDAM tak terjangkau) tidak dicatat: keduanya
     * tidak mengatakan apa pun tentang pemakaian. Yang tidak ditemukan dicatat
     * dengan `found=false`, justru supaya percobaan menebak dapat dipisahkan.
     */
    public function record(string $citizenSub, string $number, bool $found, ?int $totalDue): void
    {
        try {
            PdamCheck::create([
                'citizen_sub' => $citizenSub,
                'connection_number' => PdamClient::normalize($number),
                'found' => $found,
                'total_due' => $found ? $totalDue : null,
                'checked_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Nomor sambungan tidak ikut ke log: ia menunjuk ke rumah
            // seseorang, dan log aplikasi punya pembaca yang jauh lebih luas.
            Log::warning('Gagal mencatat pengecekan PDAM', [
                'citizen_sub' => $citizenSub,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
