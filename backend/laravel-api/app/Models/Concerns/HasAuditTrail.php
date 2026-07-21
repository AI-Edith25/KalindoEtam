<?php

namespace App\Models\Concerns;

use App\Observers\AuditableObserver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasAuditTrail
{
    protected static function bootHasAuditTrail(): void
    {
        static::whenBooted(fn () => static::observe(AuditableObserver::class));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'deleted_by');
    }
}
