<?php

namespace App\Repositories;

use App\Models\User;

class UserRepository extends BaseRepository
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->query()->where('email', $email)->first();
    }

    /** "Username" in the UI/API is this app's existing `name` column — no separate field. */
    public function findByUsername(string $username): ?User
    {
        return $this->model->query()->where('name', $username)->first();
    }
}
