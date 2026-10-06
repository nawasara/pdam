<?php

namespace Nawasara\Pdam\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris jejak pengecekan. Ditulis sekali, tidak pernah disunting, jadi
 * tanpa `updated_at`.
 */
class PdamCheck extends Model
{
    protected $table = 'nawasara_pdam_checks';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'found' => 'boolean',
        'total_due' => 'integer',
        'checked_at' => 'datetime',
    ];
}
