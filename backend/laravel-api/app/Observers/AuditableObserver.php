<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class AuditableObserver
{
    public function creating(Model $model): void
    {
        $model->created_by = $model->created_by ?? Auth::id();
    }

    public function updating(Model $model): void
    {
        $model->updated_by = Auth::id();
    }

    public function deleting(Model $model): void
    {
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $model->deleted_by = Auth::id();
            $model->saveQuietly();
        }
    }
}
