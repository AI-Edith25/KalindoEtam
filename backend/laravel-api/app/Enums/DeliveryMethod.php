<?php

namespace App\Enums;

/**
 * "Status Pengantaran" on the Delivery header — replaces the old include_in_commission_recap
 * boolean. Drives DriverCommissionReportService's commission math: only the two "Antar ..."
 * labor cases get a Driver Commission Rate lookup; Antar Proyek's higher field-negotiated rate
 * is recorded manually via PV outside this system, and Muat Gudang has no sopir commission at
 * all (customer self-pickup).
 */
enum DeliveryMethod: string
{
    case WAREHOUSE_PICKUP = 'muat_gudang';
    case DELIVERED_WITH_LABOR = 'antar_dengan_buruh';
    case DELIVERED_WITHOUT_LABOR = 'antar_tanpa_buruh';
    case PROJECT_DELIVERY = 'antar_proyek';

    /** Only these two get a Driver Commission Rate lookup — Muat Gudang has no sopir involved, Antar Proyek's rate is off-system (PV, field-negotiated, higher than the standard rate). */
    public function isCommissionRateEligible(): bool
    {
        return match ($this) {
            self::DELIVERED_WITH_LABOR, self::DELIVERED_WITHOUT_LABOR => true,
            self::WAREHOUSE_PICKUP, self::PROJECT_DELIVERY => false,
        };
    }

    /** Only "Antar dengan Buruh" rolls into the monthly recap — "Antar tanpa Buruh" is already paid in the field, same as before (ex include_in_commission_recap). */
    public function includedInMonthlyRecap(): bool
    {
        return $this === self::DELIVERED_WITH_LABOR;
    }
}
