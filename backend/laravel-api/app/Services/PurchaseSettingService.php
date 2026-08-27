<?php

namespace App\Services;

use App\Models\PurchaseSetting;
use App\Repositories\PurchaseSettingRepository;
use Illuminate\Support\Facades\Auth;

class PurchaseSettingService
{
    public function __construct(
        protected PurchaseSettingRepository $purchaseSettingRepository,
    ) {}

    public function current(): PurchaseSetting
    {
        return $this->purchaseSettingRepository->current();
    }

    public function update(array $data): PurchaseSetting
    {
        $setting = $this->current();
        $setting->update([...$data, 'updated_by' => Auth::id()]);

        return $setting;
    }
}
