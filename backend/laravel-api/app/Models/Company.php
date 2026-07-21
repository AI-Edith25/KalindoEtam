<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'address',
        'phone',
        'email',
        'npwp',
        'currency',
        'timezone',
        'fiscal_year_start',
    ];

    protected $casts = [
        'fiscal_year_start' => 'date',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
