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
        'header_row',
        'data_start_row',
        'mapping',
        'clean_settings',
        'field_defaults',
        'created_by',
    ];

    protected $casts = [
        'header_row' => 'integer',
        'data_start_row' => 'integer',
        'mapping' => 'array',
        'clean_settings' => 'array',
        'field_defaults' => 'array',
    ];
}
