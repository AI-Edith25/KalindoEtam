<?php

namespace App\Services;

use App\Models\InvoicePrintSetting;
use App\Repositories\InvoicePrintSettingRepository;
use Illuminate\Support\Facades\Auth;

class InvoicePrintSettingService
{
    public function __construct(
        protected InvoicePrintSettingRepository $invoicePrintSettingRepository,
    ) {}

    public function current(): InvoicePrintSetting
    {
        return $this->invoicePrintSettingRepository->current();
    }

    public function update(array $data): InvoicePrintSetting
    {
        $setting = $this->current();
        $setting->update([...$data, 'updated_by' => Auth::id()]);

        return $setting;
    }
}
