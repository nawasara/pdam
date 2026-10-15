<?php

namespace Nawasara\Pdam\Support;

use Illuminate\Cache\RateLimiting\Limit;

/**
 * Batas laju KHUSUS pengecekan nomor sambungan (`POST /pdam/check`).
 *
 * Audit keamanan 15 Oktober 2026, temuan S13 (enumerasi data PDAM). Batas
 * umum `throttle:nawasara-citizen` (60/menit per akun) berarti 86.400 nomor
 * per hari per akun, dan akun warga gratis. Nomor sambungan pendek dan
 * berurutan; tiap nomor memperlihatkan tunggakan, inisial nama, dan riwayat
 * pemakaian air. Tanpa rem ini satu skrip dapat memanen satu desa dalam
 * semalam.
 *
 * Warga sungguhan mengecek beberapa nomor sebulan sekali (rumahnya, rumah
 * orang tua), jadi batas per jam dan per hari yang kecil tidak terasa olehnya.
 *
 * Empat penghitung, masing-masing dengan kunci `by` sendiri (Laravel
 * menghitung Limit berkunci sama sebagai satu penghitung):
 *
 *   per akun per jam / per hari   rem utama, dikunci pada `sub`.
 *   per IP per jam / per hari     menahan satu orang dengan banyak akun.
 *                                 Lebih longgar karena ribuan warga berbagi IP
 *                                 operator seluler.
 *
 * Nilai 0 mematikan penghitung yang bersangkutan.
 */
final class LookupLimits
{
    /**
     * @param  array{per_hour?: int, per_day?: int, ip_per_hour?: int, ip_per_day?: int}  $cfg
     * @return list<Limit>
     */
    public static function build(?string $sub, string $ip, array $cfg): array
    {
        $who = ($sub !== null && $sub !== '') ? 'sub:'.$sub : 'ip:'.$ip;
        $limits = [];

        foreach ([
            ['per_hour', 'perHour', "pdam-lookup:hour:{$who}"],
            ['per_day', 'perDay', "pdam-lookup:day:{$who}"],
            ['ip_per_hour', 'perHour', "pdam-lookup:iphour:{$ip}"],
            ['ip_per_day', 'perDay', "pdam-lookup:ipday:{$ip}"],
        ] as [$key, $factory, $by]) {
            $max = (int) ($cfg[$key] ?? 0);

            if ($max > 0) {
                $limits[] = Limit::{$factory}($max)->by($by)->response(
                    fn ($request, array $headers) => response()->json([
                        'message' => self::message((int) ($headers['Retry-After'] ?? 3600)),
                    ], 429, $headers)
                );
            }
        }

        return $limits;
    }

    /**
     * Pesan 429 untuk dibaca warga. Bentuknya sama dengan galat lain di
     * endpoint ini (`{"message": ...}`), jadi aplikasi cukup menampilkannya.
     */
    public static function message(int $retryAfterSeconds): string
    {
        return 'Anda sudah terlalu sering mengecek tagihan air. Silakan coba lagi dalam '
            .self::humanWait($retryAfterSeconds).'.';
    }

    /** "45 detik", "3 menit", "2 jam". Dibulatkan ke atas. */
    public static function humanWait(int $seconds): string
    {
        $seconds = max(1, $seconds);

        if ($seconds < 60) {
            return "{$seconds} detik";
        }

        if ($seconds < 3600) {
            return ((int) ceil($seconds / 60)).' menit';
        }

        return ((int) ceil($seconds / 3600)).' jam';
    }
}
