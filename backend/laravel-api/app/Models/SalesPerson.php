<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesPerson extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    /** Laravel's default pluralization of "SalesPerson" is "sales_people" — pinned to match the sales_persons table/FK columns used everywhere else. */
    protected $table = 'sales_persons';

    protected $fillable = [
        'code',
        'name',
        'phone',
        'email',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
