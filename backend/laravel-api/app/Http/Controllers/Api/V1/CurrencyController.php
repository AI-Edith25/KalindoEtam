<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCurrencyRequest;
use App\Http\Requests\UpdateCurrencyRequest;
use App\Http\Resources\CurrencyResource;
use App\Models\Currency;
use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;

class CurrencyController extends Controller
{
    use ApiResponse;

    public function __construct(protected CurrencyService $currencyService) {}

    public function index(): JsonResponse
    {
        return $this->success(CurrencyResource::collection($this->currencyService->list()));
    }

    public function store(StoreCurrencyRequest $request): JsonResponse
    {
        $currency = $this->currencyService->create($request->validated());

        return $this->success(new CurrencyResource($currency), 'Currency created.', 201);
    }

    public function show(Currency $currency): JsonResponse
    {
        return $this->success(new CurrencyResource($currency));
    }

    public function update(UpdateCurrencyRequest $request, Currency $currency): JsonResponse
    {
        $currency = $this->currencyService->update($currency, $request->validated());

        return $this->success(new CurrencyResource($currency), 'Currency updated.');
    }

    public function destroy(Currency $currency): JsonResponse
    {
        $this->currencyService->delete($currency);

        return $this->success(null, 'Currency deleted.');
    }
}
