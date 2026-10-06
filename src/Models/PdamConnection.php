<?php

namespace Nawasara\Pdam\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Nomor sambungan yang disimpan warga.
 *
 * `HasUuids` menghasilkan UUID v7 yang terurut waktu, bukan v4 acak yang
 * memecah indeks berkelompok InnoDB (AGENTS.md §10a).
 */
class PdamConnection extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'nawasara_pdam_connections';

    protected $guarded = [];

    protected $casts = [
        'total_due' => 'integer',
        'has_arrears' => 'boolean',
        'unpaid_count' => 'integer',
        'refreshed_at' => 'datetime',
        'saved_at' => 'datetime',
    ];

    /**
     * Milik satu warga saja.
     *
     * Scope, bukan where-clause yang diulang di tiap endpoint: satu klausa
     * yang terlupa sudah cukup membuka daftar warga lain, dan yang terlupa
     * tidak memberi tanda apa pun.
     */
    public function scopeOwnedBy(Builder $query, string $citizenSub): Builder
    {
        return $query->where('citizen_sub', $citizenSub);
    }
}
