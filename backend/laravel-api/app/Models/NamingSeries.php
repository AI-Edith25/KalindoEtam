<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NamingSeries extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $table = 'naming_series';

    protected $fillable = [
        'module',
        'document_type',
        'prefix',
        'suffix',
        'digit_length',
        'current_number',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'digit_length' => 'integer',
        'current_number' => 'integer',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
