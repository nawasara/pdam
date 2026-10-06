<?php

namespace Nawasara\Pdam\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Nawasara\Pdam\Http\Resources\PdamBillResource;
use Nawasara\Pdam\Services\CheckRecorder;
use Nawasara\Pdam\Services\PdamClient;

/**
 * Cek tagihan air untuk aplikasi warga.
 *
 * Kenapa aplikasi tidak memanggil PUDAM langsung:
 *
 *   1. Kunci API PUDAM tidak boleh ada di aplikasi ponsel. Siapa pun yang
 *      membongkar aplikasinya akan mendapatkan kunci yang membuka nama dan
 *      alamat seluruh pelanggan.
 *   2. PUDAM memulangkan nama dan alamat utuh untuk nomor siapa pun. Perantara
 *      ini menyaringnya (PdamBillResource) sebelum data keluar.
 *   3. Warga harus login dulu (`api.citizen`), dan lajunya dibatasi per akun,
 *      jadi penebak nomor tidak anonim dan tidak bisa cepat.
 *
 * Nomor dari badan permintaan, bukan dari token: satu warga lazim mengurus
 * tagihan beberapa rumah, termasuk rumah orang tuanya.
 */
class PdamCheckController extends Controller
{
    /** Panjang nomor yang masuk akal. Bentuk pastinya diputuskan PUDAM. */
    public const MIN_DIGITS = 6;

    public const MAX_DIGITS = 20;

    public function __invoke(Request $request, CheckRecorder $recorder): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            // Titik, spasi, dan strip dibiarkan lewat lalu dibersihkan di
            // bawah: memaksa satu format membuat aplikasi menebak tanda baca
            // mana yang diterima.
            'connection_number' => ['required', 'string', 'max:32'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Nomor sambungan tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $number = PdamClient::normalize($request->string('connection_number')->toString());

        if (strlen($number) < self::MIN_DIGITS || strlen($number) > self::MAX_DIGITS) {
            return response()->json([
                'message' => 'Nomor sambungan harus berupa angka, sesuai yang tertera di rekening air.',
            ], 422);
        }

        try {
            // Dibangun di dalam try: bila grup `pdam` di Vault belum diisi,
            // warga menerima 502 yang sama, bukan 500 yang tampak seperti
            // Nawasara rusak.
            $result = app(PdamClient::class)->findByConnection($number);
        } catch (\Throwable $e) {
            report($e);

            // 502, bukan 500: yang gagal adalah sistem di hulu. Dibedakan
            // supaya aplikasi menyarankan "coba lagi nanti", bukan
            // menyalahkan nomor yang diketik warga.
            return response()->json([
                'message' => 'Layanan tagihan PDAM sedang tidak dapat dihubungi. Coba lagi nanti.',
            ], 502);
        }

        $recorder->record(
            (string) $request->attributes->get('citizen_sub'),
            $number,
            $result['found'],
            $result['total_due'] ?? null,
        );

        if (! $result['found']) {
            return response()->json([
                'message' => 'Nomor sambungan tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'data' => new PdamBillResource($result),
        ]);
    }
}
