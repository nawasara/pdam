<?php

declare(strict_types=1);

namespace Nawasara\Pdam\Tests;

use Nawasara\Pdam\Support\LookupLimits;
use PHPUnit\Framework\TestCase;

/**
 * Batas khusus pengecekan (audit keamanan 15 Oktober 2026, temuan S13).
 * Yang diuji kelas yang dipakai limiter di service provider.
 */
class LookupLimitsTest extends TestCase
{
    private const CFG = ['per_hour' => 10, 'per_day' => 30, 'ip_per_hour' => 60, 'ip_per_day' => 300];

    public function test_empat_penghitung_dengan_kunci_berbeda(): void
    {
        $limits = LookupLimits::build('sub-1', '203.0.113.5', self::CFG);

        $this->assertCount(4, $limits);
        // Kunci kembar membuat Laravel menghitung semuanya sebagai satu.
        $this->assertCount(4, array_unique(array_map(fn ($l) => $l->key, $limits)));
        $this->assertSame([10, 30, 60, 300], array_map(fn ($l) => $l->maxAttempts, $limits));
        $this->assertSame([3600, 86400, 3600, 86400], array_map(fn ($l) => $l->decaySeconds, $limits));
    }

    /** Banyak akun dari satu IP tetap berbagi penghitung IP. */
    public function test_akun_berbeda_berbagi_penghitung_ip(): void
    {
        $a = LookupLimits::build('sub-a', '203.0.113.5', self::CFG);
        $b = LookupLimits::build('sub-b', '203.0.113.5', self::CFG);

        $this->assertNotSame($a[0]->key, $b[0]->key);
        $this->assertSame($a[2]->key, $b[2]->key);
        $this->assertSame($a[3]->key, $b[3]->key);
    }

    public function test_nol_mematikan_penghitung(): void
    {
        $this->assertCount(2, LookupLimits::build('s', '1.1.1.1', ['per_hour' => 10, 'per_day' => 30]));
        $this->assertCount(0, LookupLimits::build('s', '1.1.1.1', []));
    }

    public function test_pesan_429_berbahasa_indonesia(): void
    {
        $this->assertSame('Anda sudah terlalu sering mengecek tagihan air. Silakan coba lagi dalam 25 menit.', LookupLimits::message(1450));
        $this->assertSame('2 jam', LookupLimits::humanWait(3700));
        $this->assertSame('30 detik', LookupLimits::humanWait(30));
    }
}
