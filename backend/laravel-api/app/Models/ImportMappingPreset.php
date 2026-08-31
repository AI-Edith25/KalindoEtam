<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A named, reusable column-mapping saved from a completed import batch. Same lightweight operational-record shape as ImportBatch — no HasAuditTrail/SoftDeletes. */
class ImportMappingPreset extends Model
{
    use HasUuids;

    protected $fillable = [
        'module',
        'name',
        'mapping',
        'clean_settings',
        'created_by',
    ];

    protected $casts = [
        'mapping' => 'array',
        'clean_settings' => 'array',
    ];
}
