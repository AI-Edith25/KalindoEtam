<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One row per (user, document_type) print preference. Same lightweight operational-record shape as ImportMappingPreset — no HasAuditTrail/SoftDeletes needed for a small preference row. */
class UserPrintSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'document_type',
        'settings',
        'updated_by',
    ];

    protected $casts = [
        'settings' => 'array',
    ];
}
