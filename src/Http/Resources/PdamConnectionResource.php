<?php

namespace Nawasara\Pdam\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu sambungan yang disimpan warga. DAFTAR-IZIN, sama seperti
 * PdamBillResource: kolom baru di tabel tidak ikut terkirim diam-diam.
 *
 * Alamat tetap tidak dikirim, dan memang tidak pernah disimpan.
 */
class PdamConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'connection_number' => $this->resource->connection_number,

            // Nama yang diberikan warga sendiri, mis. "Rumah Ibu".
            'label' => $this->resource->label,

            // Sudah tersamar sejak disimpan.
            'name_masked' => $this->resource->name_masked,

            // Cuplikan saat terakhir dicek. Tagihan terkini tetap lewat
            // POST /pdam/check; cuplikan ini hanya untuk daftar.
            'total_due' => $this->resource->total_due,
            'has_arrears' => $this->resource->has_arrears,
            'unpaid_count' => $this->resource->unpaid_count,
            'refreshed_at' => $this->resource->refreshed_at?->toIso8601String(),

            'saved_at' => $this->resource->saved_at?->toIso8601String(),
        ];
    }
}
