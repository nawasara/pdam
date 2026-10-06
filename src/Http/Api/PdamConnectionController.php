<?php

namespace Nawasara\Pdam\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Nawasara\Pdam\Http\Resources\PdamBillResource;
use Nawasara\Pdam\Http\Resources\PdamConnectionResource;
use Nawasara\Pdam\Models\PdamConnection;
use Nawasara\Pdam\Services\PdamClient;

/**
 * Nomor sambungan yang disimpan warga.
 *
 * Identitas warga selalu dari token, TIDAK PERNAH dari badan permintaan.
 * Menerima `citizen_sub` dari pemanggil berarti siapa pun dapat membaca atau
 * menghapus daftar milik orang lain.
 */
class PdamConnectionController extends Controller
{
    /**
     * Daftar sambungan milik warga yang sedang masuk.
     *
     * Tanpa paginasi: batasnya sudah dipatok di `max_per_citizen`.
     */
    public function index(Request $request): JsonResponse
    {
        $connections = PdamConnection::query()
            ->ownedBy($this->citizenSub($request))
            ->orderByDesc('saved_at')
            ->get();

        return response()->json([
            'data' => PdamConnectionResource::collection($connections),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'connection_number' => ['required', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:40'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Data tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $number = PdamClient::normalize($request->string('connection_number')->toString());

        if (strlen($number) < PdamCheckController::MIN_DIGITS || strlen($number) > PdamCheckController::MAX_DIGITS) {
            return response()->json([
                'message' => 'Nomor sambungan harus berupa angka, sesuai yang tertera di rekening air.',
            ], 422);
        }

        $sub = $this->citizenSub($request);

        // 409 diperiksa SEBELUM memanggil PUDAM: warga yang menekan dua kali
        // tidak perlu membebani sistem hulu. `withTrashed` karena keunikan
        // pasangan tetap berlaku atas baris yang sudah dihapus.
        $existing = PdamConnection::withTrashed()
            ->ownedBy($sub)
            ->where('connection_number', $number)
            ->first();

        if ($existing && ! $existing->trashed()) {
            return response()->json([
                'message' => 'Nomor ini sudah ada di daftar Anda.',
            ], 409);
        }

        // Yang dihapus tidak dihitung, supaya warga tidak terkunci oleh nomor
        // rumah yang sudah ia tinggalkan.
        $limit = (int) config('nawasara-pdam.connections.max_per_citizen', 10);

        if (! $existing && PdamConnection::query()->ownedBy($sub)->count() >= $limit) {
            return response()->json([
                'message' => "Daftar sudah mencapai batas {$limit} nomor. Hapus salah satu sebelum menambah yang baru.",
                'limit' => $limit,
            ], 422);
        }

        try {
            $result = app(PdamClient::class)->findByConnection($number);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Layanan tagihan PDAM sedang tidak dapat dihubungi. Coba lagi nanti.',
            ], 502);
        }

        if (! $result['found']) {
            // Tidak disimpan: nomor yang tidak ada tidak menunjuk ke apa pun.
            return response()->json([
                'message' => 'Nomor sambungan tidak ditemukan.',
            ], 404);
        }

        // Lewat Resource yang sama dengan /pdam/check, supaya penyamaran nama
        // tidak bercabang dua.
        $bill = (new PdamBillResource($result))->toArray($request);

        $attributes = [
            'label' => $request->filled('label') ? trim($request->string('label')->toString()) : null,
            'name_masked' => $bill['name_masked'],
            'total_due' => $bill['total_due'],
            'has_arrears' => $bill['has_arrears'],
            'unpaid_count' => $bill['unpaid_count'],
            'refreshed_at' => now(),
            'saved_at' => now(),
            'deleted_at' => null,
        ];

        if ($existing) {
            // Yang pernah dihapus lalu disimpan lagi memulihkan baris yang sama.
            $existing->forceFill($attributes)->save();
            $connection = $existing->refresh();
        } else {
            $connection = PdamConnection::create($attributes + [
                'citizen_sub' => $sub,
                'connection_number' => $number,
            ]);
        }

        return response()->json([
            'data' => new PdamConnectionResource($connection),
        ], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $connection = PdamConnection::query()
            ->ownedBy($this->citizenSub($request))
            ->find($id);

        if (! $connection) {
            // 404, bukan 403: membedakan "tidak ada" dari "bukan milik Anda"
            // memberi tahu penebak bahwa id itu ada.
            return response()->json([
                'message' => 'Nomor tidak ditemukan.',
            ], 404);
        }

        $connection->delete();

        return response()->json([], 204);
    }

    protected function citizenSub(Request $request): string
    {
        return (string) $request->attributes->get('citizen_sub');
    }
}
