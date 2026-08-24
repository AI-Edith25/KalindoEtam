<?php

namespace App\Services;

use App\Repositories\CashBookRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CashBookService
{
    public function __construct(protected CashBookRepository $cashBookRepository) {}

    public function list(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $view = $filters['view'] ?? 'all';

        return $this->cashBookRepository->paginate($view, $filters, $perPage);
    }
}
