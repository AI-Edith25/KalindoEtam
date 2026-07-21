<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UnitOfMeasurement extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $table = 'uoms';

    protected $fillable = [
        'name',
        'symbol',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'uom_id');
    }
}
