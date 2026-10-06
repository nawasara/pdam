<?php

declare(strict_types=1);

namespace Nawasara\Pdam\Tests;

use Nawasara\Pdam\Http\Resources\PdamBillResource;
use Nawasara\Pdam\Services\PdamClient;
use PHPUnit\Framework\TestCase;

/**
 * Pembersihan nomor dan penyamaran nama.
 *
 * Kedua uji memanggil FUNGSI YANG SUNGGUH DIPAKAI. Uji serupa di paket PBB
 * menguji salinan fungsinya, sehingga tetap lulus meski aslinya diubah.
 */
class NormalizationAndMaskingTest extends TestCase
{
    /**
     * PUDAM menjawab "tidak terdaftar" untuk `05.06.040212`, padahal nomornya
     * benar. Warga yang menyalin dari rekening bertitik harus tetap berhasil.
     */
    public function test_nomor_bertitik_dibersihkan(): void
    {
        $this->assertSame('0506040212', PdamClient::normalize('05.06.040212'));
        $this->assertSame('0506040212', PdamClient::normalize(' 05-06 040212 '));
    }

    /** Nol di depan bagian dari nomor, bukan dibuang seperti bilangan. */
    public function test_nol_di_depan_tetap(): void
    {
        $this->assertSame('0506040212', PdamClient::normalize('0506040212'));
    }

    public function test_nama_tersisa_inisial(): void
    {
        $this->assertSame('J•••••', PdamBillResource::maskName('JAITUN'));
    }

    public function test_spasi_di_tepi_tidak_ikut_dihitung(): void
    {
        $this->assertSame('J•••••', PdamBillResource::maskName('  JAITUN  '));
    }

    public function test_nama_kosong_jadi_null(): void
    {
        $this->assertNull(PdamBillResource::maskName(''));
        $this->assertNull(PdamBillResource::maskName(null));
    }

    /** Huruf beraksen dihitung per karakter, bukan per byte. */
    public function test_nama_beraksen_tidak_terpotong(): void
    {
        $this->assertSame('É••', PdamBillResource::maskName('ÉKA'));
    }
}
